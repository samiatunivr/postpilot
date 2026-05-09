<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/anti_cheat.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);
$me = current_user();
if (!$me || $me['role'] !== 'student') json_error('Unauthorized', 401);
csrf_required();

$body = json_input();
$attempt_id    = (int)($body['attempt_id'] ?? 0);
$is_terminated = !empty($body['is_terminated']);

$pdo = DB::pdo();
$st = $pdo->prepare(
    'SELECT a.*, e.pass_score, e.cheat_threshold, e.duration_minutes, e.ends_at
     FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
     WHERE a.id = ? AND a.student_id = ? LIMIT 1'
);
$st->execute([$attempt_id, (int)$me['id']]);
$attempt = $st->fetch();
if (!$attempt) json_error('Attempt not found', 404);

if ($attempt['submitted_at'] !== null) {
    json_ok(['already_submitted' => true]);
}

$pdo->beginTransaction();
try {
    // Auto-grade objective questions.
    $qs = $pdo->prepare(
        'SELECT q.id AS qid, q.type, q.correct_answer, q.marks,
                aa.id AS aa_id, aa.student_answer
         FROM exam_questions q
         LEFT JOIN attempt_answers aa ON aa.question_id = q.id AND aa.attempt_id = ?
         WHERE q.exam_id = ?'
    );
    $qs->execute([$attempt_id, (int)$attempt['exam_id']]);

    $total_marks = 0;
    $earned = 0.0;
    $needsManual = false;
    $upd = $pdo->prepare(
        'UPDATE attempt_answers SET is_correct = ?, marks_awarded = ? WHERE id = ?'
    );
    $insBlank = $pdo->prepare(
        'INSERT INTO attempt_answers (attempt_id, question_id, student_answer, is_correct, marks_awarded, answered_at)
         VALUES (?,?,?,?,?, NOW())'
    );

    foreach ($qs->fetchAll() as $row) {
        $total_marks += (int)$row['marks'];
        $marks = 0.0;
        $is_correct = null;
        $student_ans = (string)($row['student_answer'] ?? '');

        switch ($row['type']) {
            case 'mcq':
                if ($student_ans !== '' && strtolower($student_ans) === strtolower((string)$row['correct_answer'])) {
                    $marks = (float)$row['marks']; $is_correct = 1;
                } else { $is_correct = 0; }
                break;
            case 'true_false':
                if ($student_ans !== '' && strtolower($student_ans) === strtolower((string)$row['correct_answer'])) {
                    $marks = (float)$row['marks']; $is_correct = 1;
                } else { $is_correct = 0; }
                break;
            case 'fill':
                if ($student_ans !== '' && trim(strtolower($student_ans)) === trim(strtolower((string)$row['correct_answer']))) {
                    $marks = (float)$row['marks']; $is_correct = 1;
                } else { $is_correct = 0; }
                break;
            case 'short':
            case 'code':
                $needsManual = true; // graded by instructor
                $is_correct = null;
                $marks = 0.0;
                break;
        }

        $earned += $marks;

        if ($row['aa_id']) {
            $upd->execute([$is_correct, $marks, (int)$row['aa_id']]);
        } else {
            $insBlank->execute([$attempt_id, (int)$row['qid'], $student_ans, $is_correct, $marks]);
        }
    }

    $score = $total_marks > 0 ? round($earned / $total_marks * 100, 2) : 0.0;
    $passed = $score >= (float)$attempt['pass_score'] ? 1 : 0;

    $pdo->prepare(
        'UPDATE exam_attempts SET submitted_at = NOW(), score = ?, passed = ?, is_terminated = ?
         WHERE id = ? AND submitted_at IS NULL'
    )->execute([$score, $passed, $is_terminated ? 1 : 0, $attempt_id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('submit failed: ' . $e->getMessage());
    json_error('Could not submit exam', 500);
}

json_ok([
    'submitted'      => true,
    'score'          => $score,
    'passed'         => (bool)$passed,
    'needs_manual'   => $needsManual,
    'redirect'       => '/student/results.php',
]);

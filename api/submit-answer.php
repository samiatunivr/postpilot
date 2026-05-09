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
$attempt_id  = (int)($body['attempt_id'] ?? 0);
$question_id = (int)($body['question_id'] ?? 0);
$answer      = (string)($body['answer'] ?? '');

if (strlen($answer) > 20000) json_error('Answer too long', 413);

$attempt = load_active_attempt($attempt_id, (int)$me['id']);
if (!$attempt) json_error('Invalid attempt', 404);
if (!attempt_within_time_window($attempt)) json_error('Time expired', 410);
if (!rate_limit_answer($attempt_id))       json_error('Too fast', 429);

// Validate question belongs to this exam.
$st = DB::pdo()->prepare('SELECT id FROM exam_questions WHERE id = ? AND exam_id = ?');
$st->execute([$question_id, (int)$attempt['exam_id']]);
if (!$st->fetch()) json_error('Question not in exam', 400);

// Validate question is in this attempt's question_order.
$order = json_decode((string)$attempt['question_order'], true) ?: [];
if (!in_array($question_id, array_map('intval', $order), true)) {
    json_error('Question not in attempt', 400);
}

// Upsert (unique on attempt_id, question_id).
$st = DB::pdo()->prepare(
    'INSERT INTO attempt_answers (attempt_id, question_id, student_answer, answered_at)
     VALUES (?,?,?, NOW())
     ON DUPLICATE KEY UPDATE student_answer = VALUES(student_answer), answered_at = NOW()'
);
$st->execute([$attempt_id, $question_id, $answer]);

json_ok(['saved_at' => date('c')]);

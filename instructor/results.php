<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('instructor');
$pdo = DB::pdo();

$flash = null;
$error = null;
$exam_id = (int)($_GET['exam'] ?? 0);
$attempt_id = (int)($_GET['attempt'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'grade_short') {
            $aid = (int)($_POST['attempt_id'] ?? 0);
            $own = $pdo->prepare(
                'SELECT a.id FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
                 WHERE a.id = ? AND e.instructor_id = ?'
            );
            $own->execute([$aid, (int)$me['id']]);
            if (!$own->fetch()) {
                $error = 'Attempt not found.';
            } else {
                $marks = $_POST['marks'] ?? [];
                $upd = $pdo->prepare(
                    'UPDATE attempt_answers SET marks_awarded = ?, is_correct = ? WHERE id = ?'
                );
                foreach ($marks as $aaId => $val) {
                    $aaId = (int)$aaId;
                    $val = max(0.0, (float)$val);
                    $upd->execute([$val, $val > 0 ? 1 : 0, $aaId]);
                }
                $sum = $pdo->prepare('SELECT COALESCE(SUM(marks_awarded),0) FROM attempt_answers WHERE attempt_id = ?');
                $sum->execute([$aid]);
                $earned = (float)$sum->fetchColumn();
                $tot = $pdo->prepare(
                    'SELECT COALESCE(SUM(q.marks),0) FROM exam_questions q
                     JOIN exam_attempts a ON a.exam_id = q.exam_id WHERE a.id = ?'
                );
                $tot->execute([$aid]);
                $total = max(1.0, (float)$tot->fetchColumn());
                $score = round($earned / $total * 100, 2);
                $passed = $pdo->prepare(
                    'SELECT (? >= e.pass_score) FROM exams e JOIN exam_attempts a ON a.exam_id = e.id WHERE a.id = ?'
                );
                $passed->execute([$score, $aid]);
                $isPass = (int)$passed->fetchColumn();
                $pdo->prepare('UPDATE exam_attempts SET score = ?, passed = ? WHERE id = ?')
                    ->execute([$score, $isPass, $aid]);
                $flash = "Saved. Score: {$score}%";
            }
        }
    }
}

$myExams = $pdo->prepare('SELECT id, title FROM exams WHERE instructor_id = ? ORDER BY created_at DESC');
$myExams->execute([(int)$me['id']]);
$myExams = $myExams->fetchAll();

$attempt = null;
$answers = [];
if ($attempt_id > 0) {
    $st = $pdo->prepare(
        'SELECT a.*, e.title AS exam_title, e.instructor_id, u.name AS student_name, u.email AS student_email
         FROM exam_attempts a JOIN exams e ON e.id = a.exam_id JOIN users u ON u.id = a.student_id
         WHERE a.id = ? LIMIT 1'
    );
    $st->execute([$attempt_id]);
    $attempt = $st->fetch();
    if ($attempt && (int)$attempt['instructor_id'] === (int)$me['id']) {
        $st = $pdo->prepare(
            'SELECT aa.id AS aa_id, aa.student_answer, aa.is_correct, aa.marks_awarded,
                    q.id AS qid, q.type, q.body, q.option_a, q.option_b, q.option_c, q.option_d,
                    q.correct_answer, q.marks, q.language
             FROM attempt_answers aa JOIN exam_questions q ON q.id = aa.question_id
             WHERE aa.attempt_id = ? ORDER BY q.sort_order, q.id'
        );
        $st->execute([$attempt_id]);
        $answers = $st->fetchAll();
    } else {
        $attempt = null;
    }
} elseif ($exam_id > 0) {
    $own = $pdo->prepare('SELECT id,title FROM exams WHERE id = ? AND instructor_id = ?');
    $own->execute([$exam_id, (int)$me['id']]);
    $exam = $own->fetch();
    if ($exam) {
        $st = $pdo->prepare(
            'SELECT a.*, u.name AS student_name FROM exam_attempts a
             JOIN users u ON u.id = a.student_id
             WHERE a.exam_id = ? ORDER BY a.started_at DESC'
        );
        $st->execute([$exam_id]);
        $list = $st->fetchAll();
    }
}

render_head('Instructor · Results');
?>
<div class="layout">
<?php render_sidebar('instructor', 'results'); ?>
<main class="main">
  <h1>Results</h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

  <?php if ($attempt): ?>
    <h2><?= e($attempt['exam_title']) ?> · <?= e($attempt['student_name']) ?></h2>
    <p>Score: <b><?= $attempt['score']!==null?e((string)$attempt['score']).'%':'pending' ?></b>
       · Flags: <?= (int)$attempt['cheat_flag_count'] ?>
       <?php if ((int)$attempt['is_terminated']===1): ?><span class="badge danger">terminated</span><?php endif; ?>
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="grade_short">
      <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
      <ol class="qlist">
      <?php foreach ($answers as $A): ?>
        <li class="card q-item">
          <p class="muted small"><?= e($A['type']) ?> · <?= (int)$A['marks'] ?> mark(s)</p>
          <div class="q-body"><?= nl2br_e($A['body']) ?></div>
          <p>Student answer:</p>
          <?php if ($A['type'] === 'code'): ?>
            <pre class="code"><?= e((string)$A['student_answer']) ?></pre>
          <?php else: ?>
            <p><code><?= e((string)$A['student_answer']) ?></code></p>
          <?php endif; ?>
          <p>Correct: <code><?= e((string)$A['correct_answer']) ?></code></p>
          <label>Marks awarded
            <input type="number" step="0.25" min="0" max="<?= (int)$A['marks'] ?>"
                   name="marks[<?= (int)$A['aa_id'] ?>]"
                   value="<?= e((string)($A['marks_awarded'] ?? 0)) ?>">
          </label>
        </li>
      <?php endforeach; ?>
      </ol>
      <button class="btn primary" type="submit">Save grading</button>
    </form>
  <?php elseif (!empty($list)): ?>
    <h2><?= e($exam['title']) ?></h2>
    <table class="table">
      <thead><tr><th>Student</th><th>Started</th><th>Submitted</th><th>Score</th><th>Flags</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><?= e($a['student_name']) ?></td>
          <td class="small"><?= e($a['started_at']) ?></td>
          <td class="small"><?= e((string)($a['submitted_at'] ?? '—')) ?></td>
          <td><?= $a['score']!==null ? e((string)$a['score']).'%' : '—' ?></td>
          <td><?= (int)$a['cheat_flag_count'] ?></td>
          <td><a class="btn small" href="/instructor/results.php?attempt=<?= (int)$a['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p><a href="/instructor/results.php">← Back</a></p>
  <?php else: ?>
    <p>Select an exam:</p>
    <ul>
      <?php foreach ($myExams as $x): ?>
        <li><a href="/instructor/results.php?exam=<?= (int)$x['id'] ?>"><?= e($x['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

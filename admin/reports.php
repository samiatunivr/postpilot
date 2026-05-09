<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('admin');
$pdo = DB::pdo();

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'terminate') {
            $aid = (int)($_POST['attempt_id'] ?? 0);
            $pdo->prepare(
                'UPDATE exam_attempts SET is_terminated = 1, submitted_at = NOW()
                 WHERE id = ? AND submitted_at IS NULL'
            )->execute([$aid]);
            $flash = 'Attempt terminated.';
        }
    }
}

$attempt_id = (int)($_GET['attempt'] ?? 0);

if ($attempt_id > 0) {
    $st = $pdo->prepare(
        "SELECT a.*, e.title AS exam_title, u.name AS student_name, u.email AS student_email
         FROM exam_attempts a
         JOIN exams e ON e.id = a.exam_id
         JOIN users u ON u.id = a.student_id
         WHERE a.id = ? LIMIT 1"
    );
    $st->execute([$attempt_id]);
    $attempt = $st->fetch();
    $logs = [];
    if ($attempt) {
        $r = $pdo->prepare(
            'SELECT event_type, occurred_at, extra_data FROM cheat_logs
             WHERE attempt_id = ? ORDER BY occurred_at ASC LIMIT 1000'
        );
        $r->execute([$attempt_id]);
        $logs = $r->fetchAll();
    }
}

$attempts = $pdo->query(
    "SELECT a.id, a.started_at, a.submitted_at, a.score, a.passed, a.is_terminated,
            a.cheat_flag_count, e.title, u.name AS student
     FROM exam_attempts a
     JOIN exams e ON e.id = a.exam_id
     JOIN users u ON u.id = a.student_id
     ORDER BY a.started_at DESC LIMIT 100"
)->fetchAll();

render_head('Admin · Reports');
?>
<div class="layout">
<?php render_sidebar('admin', 'reports'); ?>
<main class="main">
  <h1>Reports</h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

  <?php if (!empty($attempt)): ?>
    <h2>Attempt #<?= (int)$attempt['id'] ?> · <?= e($attempt['exam_title']) ?></h2>
    <p>Student: <b><?= e($attempt['student_name']) ?></b> &lt;<?= e($attempt['student_email']) ?>&gt;<br>
       Score: <?= $attempt['score'] !== null ? e((string)$attempt['score']) : '—' ?>
       · Passed: <?= ((int)($attempt['passed'] ?? 0) === 1) ? 'Yes' : 'No' ?>
       · Terminated: <?= ((int)$attempt['is_terminated'] === 1) ? '<span class="badge danger">Yes</span>' : 'No' ?>
       · Flags: <b><?= (int)$attempt['cheat_flag_count'] ?></b></p>
    <h3>Cheat-event timeline</h3>
    <?php if (!$logs): ?><p class="muted">No events.</p>
    <?php else: ?>
      <table class="table small">
        <thead><tr><th>When</th><th>Event</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $L): ?>
          <tr>
            <td><?= e($L['occurred_at']) ?></td>
            <td><span class="badge"><?= e($L['event_type']) ?></span></td>
            <td class="mono"><?= e((string)($L['extra_data'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p><a href="/admin/reports.php">← Back to all attempts</a></p>
  <?php else: ?>
    <h2>All attempts</h2>
    <table class="table">
      <thead><tr><th>#</th><th>Student</th><th>Exam</th><th>Started</th><th>Submitted</th><th>Score</th><th>Flags</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($attempts as $a):
        $threshold = (int)$a['cheat_flag_count'] >= 5; ?>
        <tr class="<?= $threshold ? 'row-danger' : '' ?>">
          <td><?= (int)$a['id'] ?></td>
          <td><?= e($a['student']) ?></td>
          <td><?= e($a['title']) ?></td>
          <td class="small"><?= e($a['started_at']) ?></td>
          <td class="small"><?= e((string)($a['submitted_at'] ?? '—')) ?></td>
          <td><?= $a['score'] !== null ? e((string)$a['score']) : '—' ?></td>
          <td><?php if ((int)$a['cheat_flag_count'] > 0): ?>
                <span class="badge <?= $threshold ? 'danger' : '' ?>"><?= (int)$a['cheat_flag_count'] ?></span>
              <?php else: ?>0<?php endif; ?></td>
          <td><a class="btn small" href="/admin/reports.php?attempt=<?= (int)$a['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('student');
$pdo = DB::pdo();

$st = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM exam_attempts a
             WHERE a.exam_id=e.id AND a.student_id=?) AS my_attempts
     FROM exam_students es
     JOIN exams e ON e.id = es.exam_id
     WHERE es.student_id = ?
     ORDER BY e.starts_at IS NULL, e.starts_at ASC, e.id DESC"
);
$st->execute([(int)$me['id'], (int)$me['id']]);
$exams = $st->fetchAll();

$now = time();

render_head('My Exams');
?>
<div class="layout">
<?php render_sidebar('student', 'dashboard'); ?>
<main class="main">
  <h1>My Exams</h1>
  <?php if (!$exams): ?>
    <p class="muted">No exams assigned yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Title</th><th>Subject</th><th>Window</th><th>Duration</th><th>Attempts</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($exams as $x):
        $sa = $x['starts_at'] ? strtotime((string)$x['starts_at']) : null;
        $ea = $x['ends_at']   ? strtotime((string)$x['ends_at'])   : null;
        $can_start = ($x['status']==='published')
            && ((int)$x['my_attempts'] < (int)$x['max_attempts'])
            && ($sa === null || $now >= $sa)
            && ($ea === null || $now <= $ea);
        $reason = '';
        if ($x['status']!=='published') $reason = 'Not yet published';
        elseif ((int)$x['my_attempts'] >= (int)$x['max_attempts']) $reason = 'No attempts left';
        elseif ($sa !== null && $now < $sa) $reason = 'Starts ' . date('Y-m-d H:i', $sa);
        elseif ($ea !== null && $now > $ea) $reason = 'Closed';
        ?>
        <tr>
          <td><?= e($x['title']) ?></td>
          <td><?= e($x['subject']) ?></td>
          <td class="small">
            <?= $sa ? e(date('Y-m-d H:i', $sa)) : '—' ?> →
            <?= $ea ? e(date('Y-m-d H:i', $ea)) : '—' ?>
          </td>
          <td><?= (int)$x['duration_minutes'] ?> min</td>
          <td><?= (int)$x['my_attempts'] ?>/<?= (int)$x['max_attempts'] ?></td>
          <td><?= e($x['status']) ?></td>
          <td>
            <?php if ($can_start): ?>
              <a class="btn primary small" href="/student/take-exam.php?exam=<?= (int)$x['id'] ?>">Start</a>
            <?php else: ?>
              <span class="muted small"><?= e($reason) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

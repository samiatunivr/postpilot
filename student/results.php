<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('student');
$pdo = DB::pdo();

$st = $pdo->prepare(
    'SELECT a.id, a.started_at, a.submitted_at, a.score, a.passed, a.is_terminated,
            e.title, e.pass_score, e.results_released
     FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
     WHERE a.student_id = ? AND a.submitted_at IS NOT NULL
     ORDER BY a.submitted_at DESC'
);
$st->execute([(int)$me['id']]);
$rows = $st->fetchAll();

render_head('My Results');
?>
<div class="layout">
<?php render_sidebar('student', 'results'); ?>
<main class="main">
  <h1>Past Results</h1>
  <?php if (!$rows): ?><p class="muted">No completed attempts yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Exam</th><th>Submitted</th><th>Score</th><th>Pass</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td class="small"><?= e((string)$r['submitted_at']) ?></td>
          <td>
            <?php if ((int)$r['results_released'] === 1 && $r['score'] !== null): ?>
              <b><?= e((string)$r['score']) ?>%</b>
            <?php else: ?>
              <span class="muted">pending</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$r['results_released'] === 1 && $r['passed'] !== null): ?>
              <?= ((int)$r['passed'] === 1) ? '<span class="badge success">Pass</span>' : '<span class="badge danger">Fail</span>' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?php if ((int)$r['is_terminated'] === 1): ?><span class="badge danger">terminated</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

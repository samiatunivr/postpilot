<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('admin');

$pdo = DB::pdo();
$counts = [
    'users'    => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'students' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
    'instructors' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='instructor'")->fetchColumn(),
    'exams'    => (int)$pdo->query('SELECT COUNT(*) FROM exams')->fetchColumn(),
    'attempts' => (int)$pdo->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn(),
    'flagged'  => (int)$pdo->query('SELECT COUNT(*) FROM exam_attempts WHERE cheat_flag_count > 0')->fetchColumn(),
];

$open = $pdo->query(
    "SELECT a.id, a.started_at, a.cheat_flag_count, e.title, u.name AS student
     FROM exam_attempts a
     JOIN exams e ON e.id = a.exam_id
     JOIN users u ON u.id = a.student_id
     WHERE a.submitted_at IS NULL
     ORDER BY a.started_at DESC LIMIT 20"
)->fetchAll();

render_head('Admin · Dashboard');
?>
<div class="layout">
<?php render_sidebar('admin', 'dashboard'); ?>
<main class="main">
  <h1>Admin Dashboard</h1>
  <section class="grid grid-3">
    <div class="card stat"><b><?= $counts['users'] ?></b><span>Total users</span></div>
    <div class="card stat"><b><?= $counts['students'] ?></b><span>Students</span></div>
    <div class="card stat"><b><?= $counts['instructors'] ?></b><span>Instructors</span></div>
    <div class="card stat"><b><?= $counts['exams'] ?></b><span>Exams</span></div>
    <div class="card stat"><b><?= $counts['attempts'] ?></b><span>Attempts</span></div>
    <div class="card stat danger"><b><?= $counts['flagged'] ?></b><span>Flagged attempts</span></div>
  </section>

  <h2>Live attempts</h2>
  <?php if (!$open): ?>
    <p class="muted">No active attempts.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>#</th><th>Student</th><th>Exam</th><th>Started</th><th>Flags</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($open as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= e($r['student']) ?></td>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['started_at']) ?></td>
          <td><?php if ((int)$r['cheat_flag_count'] > 0): ?><span class="badge danger"><?= (int)$r['cheat_flag_count'] ?></span><?php else: ?>0<?php endif; ?></td>
          <td>
            <form method="post" action="/admin/reports.php" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="terminate">
              <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
              <button class="btn danger small" type="submit">Force terminate</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

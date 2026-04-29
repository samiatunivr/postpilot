<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('instructor');
$pdo = DB::pdo();

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $eid = (int)($_POST['exam_id'] ?? 0);
        $own = $pdo->prepare('SELECT id,status,results_released FROM exams WHERE id = ? AND instructor_id = ?');
        $own->execute([$eid, (int)$me['id']]);
        $exam = $own->fetch();
        if (!$exam) {
            $error = 'Exam not found.';
        } elseif ($action === 'publish') {
            $pdo->prepare("UPDATE exams SET status='published' WHERE id = ?")->execute([$eid]);
            $flash = 'Exam published.';
        } elseif ($action === 'close') {
            $pdo->prepare("UPDATE exams SET status='closed' WHERE id = ?")->execute([$eid]);
            $flash = 'Exam closed.';
        } elseif ($action === 'release') {
            $pdo->prepare('UPDATE exams SET results_released = 1 - results_released WHERE id = ?')->execute([$eid]);
            $flash = 'Results visibility toggled.';
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM exams WHERE id = ?')->execute([$eid]);
            $flash = 'Exam deleted.';
        }
    }
}

$st = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id=e.id) AS qcount,
            (SELECT COUNT(*) FROM exam_students es WHERE es.exam_id=e.id) AS assigned,
            (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id=e.id) AS attempts
     FROM exams e WHERE e.instructor_id = ? ORDER BY e.created_at DESC"
);
$st->execute([(int)$me['id']]);
$exams = $st->fetchAll();

render_head('Instructor · My Exams');
?>
<div class="layout">
<?php render_sidebar('instructor', 'dashboard'); ?>
<main class="main">
  <h1>My Exams</h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
  <p><a class="btn primary" href="/instructor/exam-builder.php">+ New exam</a></p>
  <?php if (!$exams): ?>
    <p class="muted">No exams yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Title</th><th>Subject</th><th>Status</th><th>Q</th><th>Assigned</th><th>Attempts</th><th>Released</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($exams as $x): ?>
        <tr>
          <td><a href="/instructor/exam-builder.php?id=<?= (int)$x['id'] ?>"><?= e($x['title']) ?></a></td>
          <td><?= e($x['subject']) ?></td>
          <td><?= e($x['status']) ?></td>
          <td><?= (int)$x['qcount'] ?></td>
          <td><?= (int)$x['assigned'] ?></td>
          <td><?= (int)$x['attempts'] ?></td>
          <td><?= ((int)$x['results_released']===1) ? 'Yes' : 'No' ?></td>
          <td>
            <?php if ($x['status']==='draft'): ?>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="exam_id" value="<?= (int)$x['id'] ?>">
                <button class="btn small primary" type="submit">Publish</button>
              </form>
            <?php elseif ($x['status']==='published'): ?>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="exam_id" value="<?= (int)$x['id'] ?>">
                <button class="btn small" type="submit">Close</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="action" value="release">
              <input type="hidden" name="exam_id" value="<?= (int)$x['id'] ?>">
              <button class="btn small" type="submit">Toggle results</button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Delete exam and all data?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="exam_id" value="<?= (int)$x['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
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

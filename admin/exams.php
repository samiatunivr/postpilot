<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('admin');
$pdo = DB::pdo();

$flash = null;
$error = null;
$exam_id = (int)($_GET['exam_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'assign') {
                $eid = (int)($_POST['exam_id'] ?? 0);
                $sids = $_POST['student_ids'] ?? [];
                if (!is_array($sids)) $sids = [];
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM exam_students WHERE exam_id = ?')->execute([$eid]);
                $ins = $pdo->prepare('INSERT IGNORE INTO exam_students (exam_id, student_id) VALUES (?,?)');
                foreach ($sids as $sid) {
                    $ins->execute([$eid, (int)$sid]);
                }
                $pdo->commit();
                $flash = 'Assignments updated.';
                $exam_id = $eid;
            } elseif ($action === 'csv_assign') {
                $eid  = (int)($_POST['exam_id'] ?? 0);
                $csv  = (string)($_POST['csv'] ?? '');
                $emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', $csv) ?: []));
                $count = 0;
                $sel = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role='student' AND is_active=1");
                $ins = $pdo->prepare('INSERT IGNORE INTO exam_students (exam_id, student_id) VALUES (?,?)');
                foreach ($emails as $em) {
                    $sel->execute([strtolower($em)]);
                    $sid = (int)$sel->fetchColumn();
                    if ($sid > 0) { $ins->execute([$eid, $sid]); $count++; }
                }
                $flash = "Assigned {$count} student(s) via CSV.";
                $exam_id = $eid;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$exams = $pdo->query(
    "SELECT e.id,e.title,e.subject,e.status,e.starts_at,e.ends_at,
            (SELECT COUNT(*) FROM exam_students es WHERE es.exam_id=e.id) AS assigned,
            (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id=e.id) AS qcount,
            u.name AS instructor
     FROM exams e LEFT JOIN users u ON u.id = e.instructor_id
     ORDER BY e.created_at DESC LIMIT 200"
)->fetchAll();

$students = $pdo->query("SELECT id,name,email FROM users WHERE role='student' AND is_active=1 ORDER BY name")->fetchAll();

$assigned_ids = [];
$selected = null;
if ($exam_id > 0) {
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
    $st->execute([$exam_id]);
    $selected = $st->fetch() ?: null;
    if ($selected) {
        $r = $pdo->prepare('SELECT student_id FROM exam_students WHERE exam_id = ?');
        $r->execute([$exam_id]);
        $assigned_ids = array_map('intval', array_column($r->fetchAll(), 'student_id'));
    }
}

render_head('Admin · Exams');
?>
<div class="layout">
<?php render_sidebar('admin', 'exams'); ?>
<main class="main">
  <h1>Exams &amp; Assignments</h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

  <table class="table">
    <thead><tr><th>Title</th><th>Subject</th><th>Instructor</th><th>Window</th><th>Q</th><th>Assigned</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($exams as $x): ?>
      <tr>
        <td><?= e($x['title']) ?></td>
        <td><?= e($x['subject']) ?></td>
        <td><?= e((string)($x['instructor'] ?? '—')) ?></td>
        <td class="small"><?= e((string)($x['starts_at'] ?? '—')) ?> → <?= e((string)($x['ends_at'] ?? '—')) ?></td>
        <td><?= (int)$x['qcount'] ?></td>
        <td><?= (int)$x['assigned'] ?></td>
        <td><?= e($x['status']) ?></td>
        <td><a class="btn small" href="/admin/exams.php?exam_id=<?= (int)$x['id'] ?>">Assign</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($selected): ?>
    <h2>Assign students · <?= e($selected['title']) ?></h2>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="exam_id" value="<?= (int)$selected['id'] ?>">
      <div class="checkbox-list">
        <?php foreach ($students as $s):
          $checked = in_array((int)$s['id'], $assigned_ids, true) ? 'checked' : ''; ?>
          <label><input type="checkbox" name="student_ids[]" value="<?= (int)$s['id'] ?>" <?= $checked ?>>
            <?= e($s['name']) ?> <small class="muted"><?= e($s['email']) ?></small></label>
        <?php endforeach; ?>
      </div>
      <button class="btn primary" type="submit">Save assignments</button>
    </form>

    <details class="card">
      <summary><b>Bulk assign by email CSV</b></summary>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="csv_assign">
        <input type="hidden" name="exam_id" value="<?= (int)$selected['id'] ?>">
        <textarea name="csv" rows="4" placeholder="alice@x.com, bob@x.com"></textarea>
        <button class="btn" type="submit">Add by CSV</button>
      </form>
    </details>
  <?php endif; ?>
</main>
</div>
<?php render_foot(); ?>

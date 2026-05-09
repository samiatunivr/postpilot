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
        try {
            if ($action === 'create') {
                $name  = trim((string)($_POST['name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $role  = (string)($_POST['role'] ?? '');
                $pass  = (string)($_POST['password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Name and valid email required.');
                }
                if (!in_array($role, ['admin','instructor','student'], true)) {
                    throw new RuntimeException('Invalid role.');
                }
                if (strlen($pass) < 8) {
                    throw new RuntimeException('Password must be at least 8 characters.');
                }
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $st = $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
                $st->execute([$name, $email, $hash, $role]);
                $flash = 'User created.';
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id !== (int)$me['id']) {
                    $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                    $flash = 'User status toggled.';
                } else {
                    $error = 'Cannot disable yourself.';
                }
            } elseif ($action === 'reset') {
                $id = (int)($_POST['id'] ?? 0);
                $pass = (string)($_POST['password'] ?? '');
                if (strlen($pass) < 8) throw new RuntimeException('Password too short.');
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
                $flash = 'Password updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === (int)$me['id']) throw new RuntimeException('Cannot delete yourself.');
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                $flash = 'User deleted.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$role_filter = (string)($_GET['role'] ?? '');
$sql = 'SELECT id,name,email,role,is_active,last_login FROM users';
$params = [];
if (in_array($role_filter, ['admin','instructor','student'], true)) {
    $sql .= ' WHERE role = ?';
    $params[] = $role_filter;
}
$sql .= ' ORDER BY role, name LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

render_head('Admin · Users');
?>
<div class="layout">
<?php render_sidebar('admin', 'users'); ?>
<main class="main">
  <h1>Users</h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

  <details open class="card">
    <summary><b>Add user</b></summary>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label>Name <input name="name" required maxlength="120"></label>
      <label>Email <input name="email" type="email" required maxlength="190"></label>
      <label>Role
        <select name="role" required>
          <option value="student">Student</option>
          <option value="instructor">Instructor</option>
          <option value="admin">Admin</option>
        </select>
      </label>
      <label>Password <input name="password" type="text" required minlength="8" maxlength="200"></label>
      <button class="btn primary" type="submit">Create</button>
    </form>
  </details>

  <form method="get" class="filters">
    <label>Filter:
      <select name="role" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="student" <?= $role_filter==='student'?'selected':'' ?>>Students</option>
        <option value="instructor" <?= $role_filter==='instructor'?'selected':'' ?>>Instructors</option>
        <option value="admin" <?= $role_filter==='admin'?'selected':'' ?>>Admins</option>
      </select>
    </label>
  </form>

  <table class="table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active</th><th>Last login</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['name']) ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= ((int)$u['is_active']===1) ? 'Yes' : '<span class="muted">No</span>' ?></td>
        <td><?= e((string)($u['last_login'] ?? '—')) ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn small" type="submit">Toggle</button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('Reset password?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="text" name="password" placeholder="new password" minlength="8" required>
            <button class="btn small" type="submit">Reset</button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('Delete this user?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn small danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
</div>
<?php render_foot(); ?>

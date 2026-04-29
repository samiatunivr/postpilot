<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// If already logged in, send to role landing page.
if (($u = current_user()) !== null) {
    $map = ['admin' => '/admin/dashboard.php',
            'instructor' => '/instructor/dashboard.php',
            'student' => '/student/dashboard.php'];
    redirect($map[$u['role']] ?? '/index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token    = (string)($_POST['csrf'] ?? '');

    if (!csrf_check($token)) {
        $error = 'Session expired. Refresh and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please provide email and password.';
    } elseif (login_fail_count(client_ip()) >= LOGIN_MAX_FAILS) {
        $error = 'Too many failed attempts. Try again later.';
    } else {
        $u = authenticate($email, $password);
        login_record(client_ip(), $email, (bool)$u);
        if ($u !== null) {
            login_session($u);
            $map = ['admin' => '/admin/dashboard.php',
                    'instructor' => '/instructor/dashboard.php',
                    'student' => '/student/dashboard.php'];
            redirect($map[$u['role']] ?? '/index.php');
        }
        $error = 'Invalid credentials.';
    }
}

render_head('Sign in');
?>
<main class="login-wrap">
  <form class="card" method="post" action="/index.php" autocomplete="on">
    <h1><?= e(APP_NAME) ?></h1>
    <p class="muted">Sign in to access your exams.</p>
    <?php if ($error): ?>
      <div class="alert danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <label>Email
      <input type="email" name="email" required autofocus maxlength="190">
    </label>
    <label>Password
      <input type="password" name="password" required maxlength="200">
    </label>
    <?= csrf_field() ?>
    <button class="btn primary" type="submit">Sign in</button>
    <p class="muted small">Accounts are created by your administrator.</p>
  </form>
</main>
<?php render_foot(); ?>

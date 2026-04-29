<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Require an active session and one of the listed roles, or redirect to login. */
function require_role(string ...$allowed_roles): array
{
    $u = current_user();
    if ($u === null) {
        redirect('/index.php');
    }
    if (!in_array($u['role'], $allowed_roles, true)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    return $u;
}

/** Authenticate by email + password. Returns user row or null. */
function authenticate(string $email, string $password): ?array
{
    $st = DB::pdo()->prepare('SELECT id,name,email,password_hash,role,is_active FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || (int)$u['is_active'] !== 1) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    return $u;
}

/** Count failed login attempts for IP within window. */
function login_fail_count(string $ip): int
{
    $st = DB::pdo()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $st->execute([$ip, LOGIN_FAIL_WINDOW]);
    return (int)$st->fetchColumn();
}

/** Record a login attempt. */
function login_record(string $ip, ?string $email, bool $success): void
{
    $st = DB::pdo()->prepare(
        'INSERT INTO login_attempts (ip_address, email, success) VALUES (?,?,?)'
    );
    $st->execute([$ip, $email, $success ? 1 : 0]);
}

/** Establish authenticated session with regenerated id. */
function login_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid']        = (int)$user['id'];
    $_SESSION['role']       = (string)$user['role'];
    $_SESSION['login_time'] = time();
    DB::pdo()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([(int)$user['id']]);
}

<?php
declare(strict_types=1);

/** HTML-escape a string for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Fetch the per-request CSP nonce. */
function csp_nonce(): string
{
    return (string)($GLOBALS['CSP_NONCE'] ?? '');
}

/** Issue or read the active CSRF token. */
function csrf_token(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

/** Render hidden CSRF input. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Validate a CSRF token from form POST or AJAX header. */
function csrf_check(?string $supplied): bool
{
    return is_string($supplied) && hash_equals(csrf_token(), $supplied);
}

/** Abort with JSON error and HTTP status. */
function json_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** Send a JSON success payload. */
function json_ok(array $data = []): never
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $data);
    exit;
}

/** Read JSON request body. */
function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Get current logged-in user row or null. */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = DB::pdo()->prepare('SELECT id,name,email,role,is_active FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || (int)$u['is_active'] !== 1) {
        session_destroy();
        return null;
    }
    return $cache = $u;
}

/** Redirect helper. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Get client IP address (single value). */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Truncated UA. */
function client_ua(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
}

/** Generate a UUIDv4 string. */
function uuidv4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Render shared HTML head with CSS and CSP nonce. */
function render_head(string $title, bool $exam_mode = false): void
{
    $n = csp_nonce();
    $cls = $exam_mode ? 'exam-mode' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' · ' . APP_NAME . '</title>'
        . '<link rel="stylesheet" href="/assets/app.css">'
        . '<meta name="csrf-token" content="' . e(csrf_token()) . '">'
        . '</head><body class="' . $cls . '">';
    echo '<script nonce="' . e($n) . '">window.PD={csrf:"' . e(csrf_token()) . '"};</script>';
}

/** Render shared HTML footer + main JS. */
function render_foot(bool $exam_mode = false): void
{
    $n = csp_nonce();
    if ($exam_mode) {
        echo '<script nonce="' . e($n) . '" src="/assets/katex.min.js" defer></script>';
        echo '<link rel="stylesheet" href="/assets/katex.min.css">';
    }
    echo '<script nonce="' . e($n) . '" src="/assets/app.js" defer></script>';
    echo '</body></html>';
}

/** Render a sidebar navigation for admin/instructor. */
function render_sidebar(string $role, string $active = ''): void
{
    $u = current_user();
    $items = [];
    if ($role === 'admin') {
        $items = [
            'dashboard' => ['/admin/dashboard.php', 'Dashboard'],
            'users'     => ['/admin/users.php', 'Users'],
            'exams'     => ['/admin/exams.php', 'Exams & Assignments'],
            'reports'   => ['/admin/reports.php', 'Reports'],
        ];
    } elseif ($role === 'instructor') {
        $items = [
            'dashboard' => ['/instructor/dashboard.php', 'My Exams'],
            'builder'   => ['/instructor/exam-builder.php', 'New Exam'],
            'results'   => ['/instructor/results.php', 'Results'],
        ];
    } else {
        $items = [
            'dashboard' => ['/student/dashboard.php', 'My Exams'],
            'results'   => ['/student/results.php', 'Past Results'],
        ];
    }
    echo '<aside class="sidebar"><div class="brand">' . APP_NAME . '</div><nav>';
    foreach ($items as $key => [$href, $label]) {
        $cls = ($key === $active) ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</nav><div class="me">' . e($u['name'] ?? '')
        . ' <small>(' . e($role) . ')</small><br>'
        . '<a href="/logout.php">Logout</a></div></aside>';
}

/** Convert plain text to safe HTML preserving line breaks. */
function nl2br_e(?string $s): string
{
    return nl2br(e((string)$s));
}

/** Acquire CSRF token from POST body or X-CSRF-Token header. */
function csrf_required(): void
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_check(is_string($t) ? $t : null)) {
        json_error('Invalid CSRF token.', 403);
    }
}

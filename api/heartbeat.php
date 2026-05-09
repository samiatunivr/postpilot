<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/anti_cheat.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);
$me = current_user();
if (!$me || $me['role'] !== 'student') json_error('Unauthorized', 401);
csrf_required();

$body = json_input();
$attempt_id = (int)($body['attempt_id'] ?? 0);
$events = is_array($body['events'] ?? null) ? $body['events'] : [];

$attempt = load_active_attempt($attempt_id, (int)$me['id']);
if (!$attempt) json_error('Invalid attempt', 404);

if (!attempt_within_time_window($attempt)) {
    DB::pdo()->prepare(
        'UPDATE exam_attempts SET submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL'
    )->execute([$attempt_id]);
    json_ok(['terminated' => true, 'reason' => 'time_expired']);
}

DB::pdo()->prepare('UPDATE exam_attempts SET last_heartbeat = NOW() WHERE id = ?')
    ->execute([$attempt_id]);

foreach ($events as $ev) {
    $type  = (string)($ev['type'] ?? '');
    $extra = is_array($ev['extra'] ?? null) ? $ev['extra'] : [];
    if ($type !== '') log_cheat_event($attempt_id, $type, $extra);
}

$terminated = maybe_terminate_attempt($attempt_id);

$st = DB::pdo()->prepare('SELECT cheat_flag_count FROM exam_attempts WHERE id = ?');
$st->execute([$attempt_id]);
$flags = (int)$st->fetchColumn();

json_ok([
    'flags'      => $flags,
    'threshold'  => (int)$attempt['cheat_threshold'],
    'terminated' => $terminated,
]);

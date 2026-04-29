<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Allowed cheat event types. */
const CHEAT_EVENTS = [
    'tab_switch','focus_lost','copy_attempt','paste_attempt','right_click',
    'devtools_open','window_blur','fullscreen_exit','multi_face','keyboard_shortcut'
];

/** Log a single cheat event for an attempt and increment counter. */
function log_cheat_event(int $attempt_id, string $event_type, array $extra = []): void
{
    if (!in_array($event_type, CHEAT_EVENTS, true)) return;
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO cheat_logs (attempt_id, event_type, extra_data) VALUES (?,?,?)'
        );
        $st->execute([$attempt_id, $event_type, $extra ? json_encode($extra) : null]);
        $pdo->prepare('UPDATE exam_attempts SET cheat_flag_count = cheat_flag_count + 1 WHERE id = ?')
            ->execute([$attempt_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('cheat log failed: ' . $e->getMessage());
    }
}

/** Validate that an attempt belongs to the current student and is not finalised. */
function load_active_attempt(int $attempt_id, int $student_id): ?array
{
    $st = DB::pdo()->prepare(
        'SELECT a.*, e.duration_minutes, e.starts_at, e.ends_at, e.cheat_threshold, e.status
         FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
         WHERE a.id = ? AND a.student_id = ? AND a.submitted_at IS NULL LIMIT 1'
    );
    $st->execute([$attempt_id, $student_id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Determine whether server time falls within the exam window for this attempt. */
function attempt_within_time_window(array $attempt): bool
{
    $now = time();
    $started = strtotime((string)$attempt['started_at']);
    $hardCap = $started + ((int)$attempt['duration_minutes'] * 60);
    if ($now > $hardCap) return false;
    if (!empty($attempt['ends_at']) && $now > strtotime((string)$attempt['ends_at'])) return false;
    return true;
}

/** Reject if any other open attempt exists for this student/exam. */
function ensure_single_session(int $exam_id, int $student_id, int $current_attempt_id): bool
{
    $st = DB::pdo()->prepare(
        'SELECT id FROM exam_attempts
         WHERE exam_id = ? AND student_id = ? AND submitted_at IS NULL AND id <> ?
         LIMIT 1'
    );
    $st->execute([$exam_id, $student_id, $current_attempt_id]);
    return $st->fetch() === false;
}

/** Auto-terminate an attempt if it has exceeded the configured cheat threshold. */
function maybe_terminate_attempt(int $attempt_id): bool
{
    $st = DB::pdo()->prepare(
        'SELECT a.cheat_flag_count, e.cheat_threshold
         FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
         WHERE a.id = ? LIMIT 1'
    );
    $st->execute([$attempt_id]);
    $row = $st->fetch();
    if (!$row) return false;
    if ((int)$row['cheat_flag_count'] >= (int)$row['cheat_threshold']) {
        DB::pdo()->prepare(
            'UPDATE exam_attempts SET is_terminated = 1, submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL'
        )->execute([$attempt_id]);
        return true;
    }
    return false;
}

/** Per-attempt rate limit: returns true if allowed, false if too fast. */
function rate_limit_answer(int $attempt_id): bool
{
    $st = DB::pdo()->prepare(
        'SELECT MAX(answered_at) FROM attempt_answers WHERE attempt_id = ?'
    );
    $st->execute([$attempt_id]);
    $last = $st->fetchColumn();
    if (!$last) return true;
    return (time() - strtotime((string)$last)) >= ANSWER_RATE_LIMIT;
}

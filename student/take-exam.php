<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/anti_cheat.php';
$me = require_role('student');
$pdo = DB::pdo();

$exam_id = (int)($_GET['exam'] ?? 0);
$now = time();

// Verify assignment + window.
$st = $pdo->prepare(
    'SELECT e.* FROM exams e JOIN exam_students es ON es.exam_id = e.id
     WHERE e.id = ? AND es.student_id = ? LIMIT 1'
);
$st->execute([$exam_id, (int)$me['id']]);
$exam = $st->fetch();
if (!$exam) { http_response_code(404); exit('Exam not assigned to you.'); }
if ($exam['status'] !== 'published') exit('Exam not currently available.');
if ($exam['starts_at'] && $now < strtotime((string)$exam['starts_at'])) exit('Exam has not started yet.');
if ($exam['ends_at']   && $now > strtotime((string)$exam['ends_at']))   exit('Exam window closed.');

// Identity-confirm + start handler.
$attempt = null;
$st = $pdo->prepare(
    'SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ?
     AND submitted_at IS NULL ORDER BY id DESC LIMIT 1'
);
$st->execute([$exam_id, (int)$me['id']]);
$attempt = $st->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) exit('Bad CSRF.');
    $confirm = trim((string)($_POST['confirm_name'] ?? ''));
    if (strcasecmp($confirm, (string)$me['name']) !== 0) {
        $start_error = 'Name does not match. Type your full name as registered.';
    } else {
        $usedSt = $pdo->prepare(
            'SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND student_id = ?'
        );
        $usedSt->execute([$exam_id, (int)$me['id']]);
        if ((int)$usedSt->fetchColumn() >= (int)$exam['max_attempts']) exit('No attempts remaining.');

        // Build randomised question order.
        $qs = $pdo->prepare('SELECT id FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id');
        $qs->execute([$exam_id]);
        $ids = array_map('intval', array_column($qs->fetchAll(), 'id'));
        if (!empty($exam['shuffle_questions'])) shuffle($ids);
        $order = json_encode($ids);

        $ins = $pdo->prepare(
            'INSERT INTO exam_attempts (exam_id, student_id, ip_address, user_agent, question_order, last_heartbeat)
             VALUES (?,?,?,?,?, NOW())'
        );
        $ins->execute([$exam_id, (int)$me['id'], client_ip(), client_ua(), $order]);
        $aid = (int)$pdo->lastInsertId();
        redirect('/student/take-exam.php?exam=' . $exam_id);
    }
}

// If no active attempt, show identity confirmation form.
if (!$attempt) {
    render_head('Confirm identity');
    ?>
    <main class="login-wrap">
      <form class="card" method="post">
        <h1><?= e($exam['title']) ?></h1>
        <p>Duration: <b><?= (int)$exam['duration_minutes'] ?> min</b>
           · Pass score: <b><?= (int)$exam['pass_score'] ?>%</b></p>
        <?php if (!empty($exam['instructions'])): ?>
          <details open><summary>Instructions</summary><p><?= nl2br_e($exam['instructions']) ?></p></details>
        <?php endif; ?>
        <p class="alert"><b>Proctored exam:</b> Fullscreen will be enforced.
          Tab switches, copy/paste, right-click and devtools attempts are logged.
          After <?= (int)$exam['cheat_threshold'] ?> violations the attempt is auto-terminated.</p>
        <?php if (!empty($start_error)): ?><div class="alert danger"><?= e($start_error) ?></div><?php endif; ?>
        <label>Type your full name to confirm identity
          <input name="confirm_name" required>
        </label>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start">
        <button class="btn primary" type="submit">Begin exam</button>
        <p><a href="/student/dashboard.php">Cancel</a></p>
      </form>
    </main>
    <?php
    render_foot();
    exit;
}

// Active attempt — anti-cheat verifications.
$attempt_view = $attempt + [
    'duration_minutes' => (int)$exam['duration_minutes'],
    'ends_at'          => $exam['ends_at'],
    'cheat_threshold'  => (int)$exam['cheat_threshold'],
];
if (!attempt_within_time_window($attempt_view)) {
    // Auto-submit if expired.
    $pdo->prepare('UPDATE exam_attempts SET submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL')
        ->execute([(int)$attempt['id']]);
    redirect('/student/dashboard.php');
}
if (!ensure_single_session((int)$attempt['exam_id'], (int)$me['id'], (int)$attempt['id'])) {
    exit('Another active session detected for this exam. Close it first.');
}

// Load ordered questions per question_order JSON.
$order = json_decode((string)$attempt['question_order'], true) ?: [];
$questions = [];
if ($order) {
    $place = implode(',', array_fill(0, count($order), '?'));
    $st = $pdo->prepare("SELECT * FROM exam_questions WHERE id IN ($place)");
    $st->execute($order);
    $byId = [];
    foreach ($st->fetchAll() as $q) $byId[(int)$q['id']] = $q;
    foreach ($order as $qid) {
        if (isset($byId[(int)$qid])) $questions[] = $byId[(int)$qid];
    }
}

// Pre-fill saved answers.
$saved = [];
$ans = $pdo->prepare('SELECT question_id, student_answer FROM attempt_answers WHERE attempt_id = ?');
$ans->execute([(int)$attempt['id']]);
foreach ($ans->fetchAll() as $row) $saved[(int)$row['question_id']] = (string)$row['student_answer'];

// Reference files
$files = $pdo->prepare('SELECT id, original_name FROM exam_files WHERE exam_id = ?');
$files->execute([$exam_id]);
$files = $files->fetchAll();

$started = strtotime((string)$attempt['started_at']);
$ends_at_unix = $started + ((int)$exam['duration_minutes'] * 60);
if (!empty($exam['ends_at'])) {
    $ends_at_unix = min($ends_at_unix, strtotime((string)$exam['ends_at']));
}
$remaining = max(0, $ends_at_unix - $now);

render_head($exam['title'], /* exam_mode */ true);
?>
<div class="exam-wrap"
     data-attempt="<?= (int)$attempt['id'] ?>"
     data-exam="<?= (int)$exam_id ?>"
     data-remaining="<?= (int)$remaining ?>"
     data-shuffle-options="<?= !empty($exam['shuffle_options']) ? '1' : '0' ?>"
     data-allow-back="<?= !empty($exam['allow_back_nav']) ? '1' : '0' ?>"
     data-cheat-threshold="<?= (int)$exam['cheat_threshold'] ?>">

  <header class="exam-bar">
    <div class="title"><?= e($exam['title']) ?></div>
    <div class="timer" id="examTimer" aria-live="polite">--:--</div>
    <div class="status" id="saveStatus">Idle</div>
  </header>

  <div class="exam-grid">
    <nav class="qnav" aria-label="Question navigator" id="qNav">
      <h3>Questions</h3>
      <ol id="qNavList">
        <?php foreach ($questions as $i => $q):
          $answered = isset($saved[(int)$q['id']]) && $saved[(int)$q['id']] !== ''; ?>
          <li><button type="button" class="qnav-btn <?= $answered?'answered':'' ?>"
              data-idx="<?= $i ?>"><?= $i+1 ?></button></li>
        <?php endforeach; ?>
      </ol>
      <button type="button" id="flagBtn" class="btn small">Flag for review</button>
      <button type="button" id="finishBtn" class="btn primary">Submit exam</button>
    </nav>

    <section class="qarea">
      <?php foreach ($questions as $i => $q):
        $sa = (string)($saved[(int)$q['id']] ?? ''); ?>
        <article class="question" data-qid="<?= (int)$q['id'] ?>" data-idx="<?= $i ?>" data-type="<?= e($q['type']) ?>" hidden>
          <h2>Question <?= $i+1 ?> <small class="muted">· <?= (int)$q['marks'] ?> mark(s)</small></h2>
          <div class="q-body" data-render><?= nl2br_e($q['body']) ?></div>
          <?php if (!empty($q['image_path'])): ?>
            <img loading="lazy" alt="" src="/uploads/exam-files/<?= e($q['image_path']) ?>">
          <?php endif; ?>

          <?php if ($q['type'] === 'mcq'):
            $opts = [];
            foreach (['a','b','c','d'] as $L) {
              if (!empty($q['option_'.$L])) $opts[] = [$L, $q['option_'.$L]];
            }
          ?>
            <div class="opts" data-shuffle="<?= !empty($exam['shuffle_options'])?'1':'0' ?>">
              <?php foreach ($opts as [$L, $val]): ?>
                <label class="opt">
                  <input type="radio" name="ans_<?= (int)$q['id'] ?>" value="<?= e($L) ?>"
                    <?= ($sa === $L)?'checked':'' ?>
                    data-qid="<?= (int)$q['id'] ?>">
                  <span><b><?= strtoupper($L) ?>.</b> <?= e($val) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($q['type'] === 'true_false'): ?>
            <div class="opts">
              <label class="opt"><input type="radio" name="ans_<?= (int)$q['id'] ?>" value="true"
                  data-qid="<?= (int)$q['id'] ?>" <?= $sa==='true'?'checked':'' ?>><span>True</span></label>
              <label class="opt"><input type="radio" name="ans_<?= (int)$q['id'] ?>" value="false"
                  data-qid="<?= (int)$q['id'] ?>" <?= $sa==='false'?'checked':'' ?>><span>False</span></label>
            </div>

          <?php elseif ($q['type'] === 'fill'): ?>
            <input class="answer-input" type="text" name="ans_<?= (int)$q['id'] ?>"
                   data-qid="<?= (int)$q['id'] ?>" maxlength="500" value="<?= e($sa) ?>">

          <?php elseif ($q['type'] === 'short'): ?>
            <textarea class="answer-input" rows="6" name="ans_<?= (int)$q['id'] ?>"
                      data-qid="<?= (int)$q['id'] ?>"
                      <?php if (!empty($q['word_limit'])): ?>data-word-limit="<?= (int)$q['word_limit'] ?>"<?php endif; ?>
            ><?= e($sa) ?></textarea>
            <?php if (!empty($q['word_limit'])): ?><p class="muted small">Word limit: <?= (int)$q['word_limit'] ?> · <span class="wc">0</span> words</p><?php endif; ?>

          <?php elseif ($q['type'] === 'code'): ?>
            <p class="muted small">Language: <?= e((string)$q['language']) ?></p>
            <div class="code-editor" data-language="<?= e((string)$q['language']) ?>">
              <textarea class="answer-input mono" rows="14" spellcheck="false"
                        name="ans_<?= (int)$q['id'] ?>" data-qid="<?= (int)$q['id'] ?>"
                        data-no-paste="1"><?= e($sa !== '' ? $sa : (string)($q['starter_code'] ?? '')) ?></textarea>
              <pre class="code-highlight" aria-hidden="true"></pre>
            </div>
          <?php endif; ?>

          <div class="q-controls">
            <?php if (!empty($exam['allow_back_nav'])): ?>
              <button type="button" class="btn small qprev">Previous</button>
            <?php endif; ?>
            <button type="button" class="btn small qsave">Save</button>
            <button type="button" class="btn small primary qnext">Next</button>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <?php if ($files): ?>
    <aside class="exam-files">
      <h3>Reference</h3>
      <ul>
        <?php foreach ($files as $f): ?>
          <li><a href="/uploads/exam-files/<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </aside>
    <?php endif; ?>
  </div>

  <div id="warningOverlay" class="overlay" hidden>
    <div class="card">
      <h2>Warning</h2>
      <p id="warningText"></p>
      <button class="btn primary" id="warningOk">I understand</button>
    </div>
  </div>
</div>
<?php render_foot(/* exam_mode */ true); ?>

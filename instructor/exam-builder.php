<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$me = require_role('instructor');
$pdo = DB::pdo();

$flash = null;
$error = null;
$exam_id = (int)($_GET['id'] ?? 0);

/** Load instructor-owned exam by id, or null. */
function load_owned_exam(PDO $pdo, int $exam_id, int $owner_id): ?array
{
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ? AND instructor_id = ? LIMIT 1');
    $st->execute([$exam_id, $owner_id]);
    $r = $st->fetch();
    return $r ?: null;
}

$exam = $exam_id > 0 ? load_owned_exam($pdo, $exam_id, (int)$me['id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save_meta') {
                $title    = trim((string)($_POST['title'] ?? ''));
                $subject  = (string)($_POST['subject'] ?? 'other');
                $duration = max(1, (int)($_POST['duration_minutes'] ?? 60));
                $shuffleQ = isset($_POST['shuffle_questions']) ? 1 : 0;
                $shuffleO = isset($_POST['shuffle_options']) ? 1 : 0;
                $backNav  = isset($_POST['allow_back_nav']) ? 1 : 0;
                $maxAtt   = max(1, (int)($_POST['max_attempts'] ?? 1));
                $passSc   = max(0, min(100, (int)($_POST['pass_score'] ?? 50)));
                $instr    = (string)($_POST['instructions'] ?? '');
                $starts   = trim((string)($_POST['starts_at'] ?? '')) ?: null;
                $ends     = trim((string)($_POST['ends_at'] ?? '')) ?: null;
                $threshold = max(1, (int)($_POST['cheat_threshold'] ?? 5));
                if ($title === '') throw new RuntimeException('Title required.');
                if (!in_array($subject, ['math','english','coding','physics','chemistry','other'], true))
                    throw new RuntimeException('Invalid subject.');
                if ($exam) {
                    $st = $pdo->prepare(
                        'UPDATE exams SET title=?,subject=?,duration_minutes=?,shuffle_questions=?,shuffle_options=?,
                         allow_back_nav=?,max_attempts=?,pass_score=?,instructions=?,starts_at=?,ends_at=?,cheat_threshold=?
                         WHERE id = ? AND instructor_id = ?'
                    );
                    $st->execute([$title,$subject,$duration,$shuffleQ,$shuffleO,$backNav,$maxAtt,$passSc,$instr,$starts,$ends,$threshold,(int)$exam['id'],(int)$me['id']]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO exams (title,subject,instructor_id,duration_minutes,shuffle_questions,shuffle_options,
                         allow_back_nav,max_attempts,pass_score,instructions,starts_at,ends_at,cheat_threshold,status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, "draft")'
                    );
                    $st->execute([$title,$subject,(int)$me['id'],$duration,$shuffleQ,$shuffleO,$backNav,$maxAtt,$passSc,$instr,$starts,$ends,$threshold]);
                    $exam_id = (int)$pdo->lastInsertId();
                    redirect('/instructor/exam-builder.php?id=' . $exam_id);
                }
                $flash = 'Saved.';
                $exam = load_owned_exam($pdo, (int)$exam['id'], (int)$me['id']);
            } elseif ($action === 'add_question' && $exam) {
                $type = (string)($_POST['type'] ?? 'mcq');
                if (!in_array($type, ['mcq','true_false','short','code','fill'], true))
                    throw new RuntimeException('Invalid type.');
                $body  = trim((string)($_POST['body'] ?? ''));
                if ($body === '') throw new RuntimeException('Question body required.');
                $marks = max(1, (int)($_POST['marks'] ?? 1));
                $a = $b = $c = $d = $correct = null;
                $lang = $starter = null;
                $word_limit = null;
                if ($type === 'mcq') {
                    $a = (string)($_POST['option_a'] ?? '');
                    $b = (string)($_POST['option_b'] ?? '');
                    $c = (string)($_POST['option_c'] ?? '');
                    $d = (string)($_POST['option_d'] ?? '');
                    $correct = strtolower((string)($_POST['correct'] ?? ''));
                    if (!in_array($correct, ['a','b','c','d'], true))
                        throw new RuntimeException('Pick correct option.');
                } elseif ($type === 'true_false') {
                    $correct = (string)($_POST['correct'] ?? 'true');
                    if (!in_array($correct, ['true','false'], true))
                        throw new RuntimeException('Pick correct value.');
                } elseif ($type === 'fill') {
                    $correct = trim((string)($_POST['correct_text'] ?? ''));
                } elseif ($type === 'short') {
                    $correct = trim((string)($_POST['correct_text'] ?? ''));
                    $word_limit = (int)($_POST['word_limit'] ?? 0) ?: null;
                } elseif ($type === 'code') {
                    $lang    = (string)($_POST['language'] ?? 'python');
                    $starter = (string)($_POST['starter_code'] ?? '');
                    $correct = (string)($_POST['expected_output'] ?? '');
                }

                $image_path = null;
                if (!empty($_FILES['image']['name'])) {
                    $image_path = save_question_image($_FILES['image']);
                }

                $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM exam_questions WHERE exam_id = ?');
                $st->execute([(int)$exam['id']]);
                $sort = (int)$st->fetchColumn();

                $ins = $pdo->prepare(
                    'INSERT INTO exam_questions (exam_id,type,body,option_a,option_b,option_c,option_d,
                     correct_answer,marks,sort_order,image_path,language,starter_code,word_limit)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([(int)$exam['id'],$type,$body,$a,$b,$c,$d,$correct,$marks,$sort,$image_path,$lang,$starter,$word_limit]);
                $flash = 'Question added.';
            } elseif ($action === 'delete_question' && $exam) {
                $qid = (int)($_POST['qid'] ?? 0);
                $pdo->prepare('DELETE FROM exam_questions WHERE id = ? AND exam_id = ?')
                    ->execute([$qid, (int)$exam['id']]);
                $flash = 'Question removed.';
            } elseif ($action === 'upload_file' && $exam) {
                save_exam_file((int)$exam['id'], $_FILES['ref'] ?? null);
                $flash = 'File uploaded.';
            } elseif ($action === 'delete_file' && $exam) {
                $fid = (int)($_POST['fid'] ?? 0);
                $st = $pdo->prepare('SELECT stored_name FROM exam_files WHERE id = ? AND exam_id = ?');
                $st->execute([$fid, (int)$exam['id']]);
                $r = $st->fetch();
                if ($r) {
                    @unlink(UPLOAD_DIR . '/' . $r['stored_name']);
                    $pdo->prepare('DELETE FROM exam_files WHERE id = ?')->execute([$fid]);
                }
                $flash = 'File deleted.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

/** Validate and store a question image; returns relative stored path. */
function save_question_image(array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ((int)$f['size'] > MAX_IMAGE_BYTES) throw new RuntimeException('Image too large (max 500KB).');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/svg+xml'=>'svg'];
    if (!isset($allow[$mime])) throw new RuntimeException('Bad image mime.');
    $name = uuidv4() . '.' . $allow[$mime];
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name))
        throw new RuntimeException('Could not store image.');
    return $name;
}

/** Validate and store a reference file attached to an exam. */
function save_exam_file(int $exam_id, ?array $f): void
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        throw new RuntimeException('No file uploaded.');
    if ((int)$f['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('File too large (max 5MB).');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    $allow = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','text/plain'=>'txt'];
    if (!isset($allow[$mime])) throw new RuntimeException('Mime not allowed.');
    $stored = uuidv4() . '.' . $allow[$mime];
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored))
        throw new RuntimeException('Storage failed.');
    DB::pdo()->prepare(
        'INSERT INTO exam_files (exam_id,original_name,stored_name,mime_type,file_size) VALUES (?,?,?,?,?)'
    )->execute([$exam_id, substr((string)$f['name'],0,180), $stored, $mime, (int)$f['size']]);
}

$questions = [];
$files = [];
if ($exam) {
    $st = $pdo->prepare('SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id');
    $st->execute([(int)$exam['id']]);
    $questions = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM exam_files WHERE exam_id = ? ORDER BY uploaded_at DESC');
    $st->execute([(int)$exam['id']]);
    $files = $st->fetchAll();
}

$current_subject = $exam['subject'] ?? 'other';

render_head($exam ? 'Edit exam' : 'New exam', /* exam_mode */ true);
?>
<div class="layout">
<?php render_sidebar('instructor', $exam ? 'dashboard' : 'builder'); ?>
<main class="main">
  <h1><?= $exam ? 'Edit exam' : 'New exam' ?></h1>
  <?php if ($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_meta">
    <div class="form-grid">
      <label>Title <input name="title" required maxlength="190" value="<?= e($exam['title'] ?? '') ?>"></label>
      <label>Subject
        <select name="subject" id="subjectSelect">
          <?php foreach (['math','english','coding','physics','chemistry','other'] as $s): ?>
            <option value="<?= $s ?>" <?= $current_subject===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Duration (min) <input type="number" min="1" name="duration_minutes" value="<?= (int)($exam['duration_minutes'] ?? 60) ?>"></label>
      <label>Pass score (%) <input type="number" min="0" max="100" name="pass_score" value="<?= (int)($exam['pass_score'] ?? 50) ?>"></label>
      <label>Max attempts <input type="number" min="1" name="max_attempts" value="<?= (int)($exam['max_attempts'] ?? 1) ?>"></label>
      <label>Cheat threshold <input type="number" min="1" name="cheat_threshold" value="<?= (int)($exam['cheat_threshold'] ?? 5) ?>"></label>
      <label>Starts at <input type="datetime-local" name="starts_at" value="<?= e(isset($exam['starts_at']) ? str_replace(' ', 'T', (string)$exam['starts_at']) : '') ?>"></label>
      <label>Ends at <input type="datetime-local" name="ends_at" value="<?= e(isset($exam['ends_at']) ? str_replace(' ', 'T', (string)$exam['ends_at']) : '') ?>"></label>
      <label class="check"><input type="checkbox" name="shuffle_questions" <?= !empty($exam['shuffle_questions'])?'checked':'' ?>> Shuffle questions</label>
      <label class="check"><input type="checkbox" name="shuffle_options" <?= !empty($exam['shuffle_options'])?'checked':'' ?>> Shuffle options</label>
      <label class="check"><input type="checkbox" name="allow_back_nav" <?= !isset($exam) || !empty($exam['allow_back_nav'])?'checked':'' ?>> Allow back navigation</label>
    </div>
    <label>Instructions
      <textarea name="instructions" rows="3"><?= e((string)($exam['instructions'] ?? '')) ?></textarea>
    </label>
    <button class="btn primary" type="submit"><?= $exam ? 'Save changes' : 'Create exam' ?></button>
  </form>

  <?php if ($exam): ?>

  <h2>Questions (<?= count($questions) ?>)</h2>
  <?php if (!$questions): ?><p class="muted">No questions yet.</p>
  <?php else: ?>
    <ol class="qlist">
    <?php foreach ($questions as $q): ?>
      <li class="card q-item">
        <div class="row">
          <span class="badge"><?= e($q['type']) ?></span>
          <span class="muted">· <?= (int)$q['marks'] ?> mark(s)</span>
        </div>
        <div class="q-body" data-render><?= nl2br_e($q['body']) ?></div>
        <?php if ($q['type'] === 'mcq'): ?>
          <ul class="opts">
            <?php foreach (['a','b','c','d'] as $L):
              $val = $q['option_'.$L] ?? null; if ($val === null || $val === '') continue; ?>
              <li><b><?= strtoupper($L) ?>.</b> <?= e($val) ?>
                <?php if ($q['correct_answer'] === $L): ?><span class="badge success">correct</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($q['type'] === 'true_false'): ?>
          <p>Correct: <b><?= e((string)$q['correct_answer']) ?></b></p>
        <?php elseif ($q['type'] === 'fill' || $q['type'] === 'short'): ?>
          <p>Model answer: <code><?= e((string)$q['correct_answer']) ?></code></p>
        <?php elseif ($q['type'] === 'code'): ?>
          <p>Language: <code><?= e((string)$q['language']) ?></code></p>
          <?php if (!empty($q['starter_code'])): ?>
            <pre class="code"><?= e((string)$q['starter_code']) ?></pre>
          <?php endif; ?>
          <p>Expected output: <code><?= e((string)$q['correct_answer']) ?></code></p>
        <?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('Delete question?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_question">
          <input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      </li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <h2>Add question</h2>
  <form method="post" enctype="multipart/form-data" class="card builder" id="qBuilder" data-subject="<?= e($current_subject) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_question">

    <label>Type
      <select name="type" id="qType">
        <option value="mcq">Multiple choice</option>
        <option value="true_false">True / False</option>
        <option value="short">Short answer</option>
        <option value="fill">Fill in the blank</option>
        <option value="code">Code</option>
      </select>
    </label>

    <div class="latex-toolbar" id="latexToolbar" hidden>
      <button type="button" data-ins="\\frac{a}{b}">frac</button>
      <button type="button" data-ins="\\sqrt{x}">sqrt</button>
      <button type="button" data-ins="\\int_{a}^{b} ">∫</button>
      <button type="button" data-ins="\\sum_{i=1}^{n} ">Σ</button>
      <button type="button" data-ins="x_{i}">subscript</button>
      <button type="button" data-ins="x^{2}">superscript</button>
      <button type="button" data-ins="\\alpha ">α</button>
      <button type="button" data-ins="\\beta ">β</button>
      <button type="button" data-ins="\\gamma ">γ</button>
      <button type="button" data-ins="\\delta ">δ</button>
      <button type="button" data-ins="\\theta ">θ</button>
      <button type="button" data-ins="\\lambda ">λ</button>
      <button type="button" data-ins="\\pi ">π</button>
      <button type="button" data-ins="\\sigma ">σ</button>
      <button type="button" data-ins="\\Omega ">Ω</button>
      <button type="button" data-ins="\\rightarrow ">→</button>
      <button type="button" data-ins="\\rightleftharpoons ">⇌</button>
      <button type="button" data-ins="\\uparrow ">↑</button>
      <button type="button" data-ins="\\downarrow ">↓</button>
    </div>

    <details class="picker" id="periodicTable" hidden><summary>Periodic table</summary>
      <div id="ptBody" class="pt-grid"></div>
    </details>

    <details class="picker" id="unitPicker" hidden><summary>Unit picker</summary>
      <div id="unitBody"></div>
    </details>

    <label>Question body (LaTeX with $...$ or $$...$$)
      <textarea name="body" id="qBody" rows="5" required></textarea>
    </label>
    <div class="preview" id="qPreview"><i>Live preview…</i></div>

    <div id="mcqFields" class="form-grid">
      <label>Option A <input name="option_a" maxlength="500"></label>
      <label>Option B <input name="option_b" maxlength="500"></label>
      <label>Option C <input name="option_c" maxlength="500"></label>
      <label>Option D <input name="option_d" maxlength="500"></label>
      <label>Correct
        <select name="correct">
          <option value="a">A</option><option value="b">B</option>
          <option value="c">C</option><option value="d">D</option>
        </select>
      </label>
    </div>

    <div id="tfFields" hidden>
      <label>Correct
        <select name="correct">
          <option value="true">True</option><option value="false">False</option>
        </select>
      </label>
    </div>

    <div id="textFields" hidden class="form-grid">
      <label>Model / accepted answer <input name="correct_text" maxlength="500"></label>
      <label>Word limit (optional) <input type="number" name="word_limit" min="1"></label>
    </div>

    <div id="codeFields" hidden class="form-grid">
      <label>Language
        <select name="language">
          <option>php</option><option>python</option><option>javascript</option>
          <option>c</option><option>cpp</option><option>java</option>
          <option>sql</option><option>bash</option>
        </select>
      </label>
      <label>Expected output <input name="expected_output" maxlength="500"></label>
      <label class="span-2">Starter code
        <textarea name="starter_code" rows="4" class="mono"></textarea>
      </label>
    </div>

    <label>Marks <input type="number" name="marks" min="1" value="1"></label>
    <label>Image (optional, ≤500KB) <input type="file" name="image" accept="image/*"></label>
    <button class="btn primary" type="submit">Add question</button>
  </form>

  <h2>Reference files</h2>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_file">
    <input type="file" name="ref" required>
    <button class="btn" type="submit">Upload</button>
  </form>
  <?php if ($files): ?>
    <ul>
    <?php foreach ($files as $f): ?>
      <li><?= e($f['original_name']) ?> <small class="muted">(<?= number_format((int)$f['file_size']/1024, 1) ?> KB)</small>
        <form method="post" class="inline" onsubmit="return confirm('Delete file?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_file">
          <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php endif; /* if ($exam) */ ?>
</main>
</div>
<?php render_foot(/* exam_mode */ true); ?>

/* ProctorDesk app.js — proctoring + editor + timer (~28KB before gzip) */
(function () {
  'use strict';

  var CSRF = (window.PD && window.PD.csrf) || '';

  /* ---------- Helpers ---------- */
  function $(s, root) { return (root || document).querySelector(s); }
  function $$(s, root) { return Array.prototype.slice.call((root || document).querySelectorAll(s)); }
  function debounce(fn, ms) {
    var t; return function () {
      var a = arguments, c = this;
      clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms);
    };
  }
  function postJSON(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(data || {})
    }).then(function (r) { return r.json().catch(function () { return {ok:false}; }); });
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* ---------- LaTeX preview (KaTeX) ---------- */
  function renderMath(host) {
    if (!host || !window.katex) return;
    // host text may contain $...$ or $$...$$
    var html = host.dataset.raw || host.innerHTML;
    host.dataset.raw = html;
    var out = html.replace(/\$\$([\s\S]+?)\$\$/g, function (_, m) {
      try { return window.katex.renderToString(m, { displayMode: true }); }
      catch (e) { return _; }
    }).replace(/\$([^\$\n]+?)\$/g, function (_, m) {
      try { return window.katex.renderToString(m, { displayMode: false }); }
      catch (e) { return _; }
    });
    host.innerHTML = out;
  }
  function renderAllMath() { $$('[data-render]').forEach(renderMath); }

  /* ---------- Builder editor (instructor) ---------- */
  function initBuilder() {
    var form = $('#qBuilder');
    if (!form) return;
    var typeSel = $('#qType');
    var body = $('#qBody');
    var preview = $('#qPreview');

    function showFields() {
      var t = typeSel.value;
      $('#mcqFields').hidden = (t !== 'mcq');
      $('#tfFields').hidden  = (t !== 'true_false');
      $('#textFields').hidden = !(t === 'short' || t === 'fill');
      $('#codeFields').hidden = (t !== 'code');
    }
    typeSel.addEventListener('change', showFields);
    showFields();

    var sub = form.dataset.subject || 'other';
    var showLatex = ['math','physics','chemistry'].indexOf(sub) >= 0;
    $('#latexToolbar').hidden = !showLatex;
    $('#periodicTable').hidden = (sub !== 'chemistry');
    $('#unitPicker').hidden = (sub !== 'physics');

    $$('#latexToolbar button').forEach(function (b) {
      b.addEventListener('click', function () { insertAtCursor(body, b.dataset.ins); livePreview(); });
    });

    if (sub === 'chemistry') buildPeriodicTable($('#ptBody'), body);
    if (sub === 'physics')   buildUnits($('#unitBody'), body);

    var livePreview = debounce(function () {
      preview.innerHTML = body.value
        ? body.value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        : '<i>Live preview…</i>';
      preview.dataset.raw = preview.innerHTML;
      renderMath(preview);
    }, 300);
    body.addEventListener('input', livePreview);
    livePreview();
  }
  function insertAtCursor(el, txt) {
    if (!el) return;
    var s = el.selectionStart, e = el.selectionEnd, v = el.value;
    el.value = v.slice(0, s) + txt + v.slice(e);
    el.focus();
    el.selectionStart = el.selectionEnd = s + txt.length;
  }
  function buildPeriodicTable(host, target) {
    if (!host) return;
    var els = ('H,He,Li,Be,B,C,N,O,F,Ne,Na,Mg,Al,Si,P,S,Cl,Ar,K,Ca,'
      + 'Sc,Ti,V,Cr,Mn,Fe,Co,Ni,Cu,Zn,Ga,Ge,As,Se,Br,Kr,Rb,Sr,Y,Zr,'
      + 'Nb,Mo,Tc,Ru,Rh,Pd,Ag,Cd,In,Sn,Sb,Te,I,Xe,Cs,Ba,La,Ce,Pr,Nd,'
      + 'Pm,Sm,Eu,Gd,Tb,Dy,Ho,Er,Tm,Yb,Lu,Hf,Ta,W,Re,Os,Ir,Pt,Au,Hg,'
      + 'Tl,Pb,Bi,Po,At,Rn,Fr,Ra,Ac,Th,Pa,U,Np,Pu,Am,Cm,Bk,Cf,Es,Fm,'
      + 'Md,No,Lr,Rf,Db,Sg,Bh,Hs,Mt,Ds,Rg,Cn,Nh,Fl,Mc,Lv,Ts,Og').split(',');
    host.innerHTML = '';
    els.forEach(function (sym) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = sym;
      b.addEventListener('click', function () { insertAtCursor(target, sym); });
      host.appendChild(b);
    });
  }
  function buildUnits(host, target) {
    if (!host) return;
    var units = ['m/s','m/s²','N','J','W','Pa','T','Hz','eV','kg','C','V','Ω','rad','mol'];
    host.innerHTML = '';
    units.forEach(function (u) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = u;
      b.style.margin = '2px';
      b.addEventListener('click', function () { insertAtCursor(target, u); });
      host.appendChild(b);
    });
  }

  /* ---------- Exam-taking UI + anti-cheat ---------- */
  function initExam() {
    var wrap = $('.exam-wrap');
    if (!wrap) return;
    var attemptId = +wrap.dataset.attempt;
    var examId = +wrap.dataset.exam;
    var remaining = +wrap.dataset.remaining;
    var allowBack = wrap.dataset.allowBack === '1';
    var threshold = +wrap.dataset.cheatThreshold || 5;

    var questions = $$('.question');
    var nav = $$('#qNavList .qnav-btn');
    var current = 0;
    var flagged = {};
    var pendingEvents = [];
    var heartbeatMisses = 0;
    var localFlagCount = 0;
    var submitted = false;

    function show(idx) {
      if (idx < 0 || idx >= questions.length) return;
      if (!allowBack && idx < current) return;
      questions.forEach(function (q, i) { q.hidden = (i !== idx); });
      nav.forEach(function (b, i) { b.classList.toggle('active', i === idx); });
      current = idx;
      var q = questions[idx];
      if (q) renderMath(q.querySelector('[data-render]'));
    }

    nav.forEach(function (b) {
      b.addEventListener('click', function () { show(+b.dataset.idx); });
    });
    $$('.qnext').forEach(function (b) {
      b.addEventListener('click', function () { saveCurrent(); show(current + 1); });
    });
    $$('.qprev').forEach(function (b) {
      b.addEventListener('click', function () { saveCurrent(); show(current - 1); });
    });
    $$('.qsave').forEach(function (b) {
      b.addEventListener('click', function () { saveCurrent(true); });
    });
    var flagBtn = $('#flagBtn');
    if (flagBtn) flagBtn.addEventListener('click', function () {
      flagged[current] = !flagged[current];
      nav[current] && nav[current].classList.toggle('flagged', !!flagged[current]);
    });

    function setStatus(msg) { var s = $('#saveStatus'); if (s) s.textContent = msg; }

    function collectAnswer(q) {
      if (!q) return null;
      var qid = +q.dataset.qid;
      var inputs = q.querySelectorAll('input[type=radio][data-qid], textarea[data-qid], input[type=text][data-qid]');
      var val = '';
      for (var i = 0; i < inputs.length; i++) {
        var el = inputs[i];
        if (el.type === 'radio') { if (el.checked) { val = el.value; break; } }
        else { val = el.value; break; }
      }
      return { question_id: qid, answer: val };
    }

    var lastSent = {};
    function saveCurrent(force) {
      var q = questions[current];
      var data = collectAnswer(q);
      if (!data) return;
      if (!force && lastSent[data.question_id] === data.answer) return;
      lastSent[data.question_id] = data.answer;
      setStatus('Saving…');
      postJSON('/api/submit-answer.php', {
        attempt_id: attemptId, question_id: data.question_id, answer: data.answer
      }).then(function (r) {
        if (r && r.ok) {
          setStatus('Saved ' + new Date().toLocaleTimeString());
          if (data.answer !== '') nav[current] && nav[current].classList.add('answered');
        } else { setStatus('Save failed'); }
      });
    }

    var autoSave = debounce(function () { saveCurrent(); }, 1500);
    $$('.answer-input, input[type=radio][data-qid]').forEach(function (el) {
      el.addEventListener('input', autoSave);
      el.addEventListener('change', autoSave);
    });
    setInterval(function () { saveCurrent(); }, 60000);

    /* Word count for short answers. */
    $$('textarea[data-word-limit]').forEach(function (ta) {
      var wc = ta.parentNode.querySelector('.wc');
      ta.addEventListener('input', function () {
        var n = (ta.value.trim().match(/\S+/g) || []).length;
        if (wc) wc.textContent = n;
      });
      ta.dispatchEvent(new Event('input'));
    });

    /* Shuffle MCQ option DOM where requested. */
    $$('.opts[data-shuffle="1"]').forEach(function (host) {
      var items = $$('label.opt', host);
      for (var i = items.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        host.insertBefore(items[j], items[i].nextSibling);
        var tmp = items[i]; items[i] = items[j]; items[j] = tmp;
      }
    });

    /* ---- Cheat detection ---- */
    function flag(type, extra) {
      pendingEvents.push({ type: type, extra: extra || {} });
      localFlagCount++;
      if (localFlagCount === Math.max(1, threshold - 2)) {
        showWarning('Suspicious activity detected. Further violations will terminate your exam.');
      } else if (localFlagCount === Math.max(1, threshold - 1)) {
        showWarning('Final warning. One more violation will end your attempt.');
      }
    }
    function showWarning(msg) {
      $('#warningText').textContent = msg;
      $('#warningOverlay').hidden = false;
    }
    $('#warningOk') && $('#warningOk').addEventListener('click', function () {
      $('#warningOverlay').hidden = true;
    });

    document.addEventListener('contextmenu', function (e) {
      e.preventDefault(); flag('right_click');
    });
    document.addEventListener('copy',  function (e) { e.preventDefault(); flag('copy_attempt'); });
    document.addEventListener('cut',   function (e) { e.preventDefault(); flag('copy_attempt'); });
    document.addEventListener('paste', function (e) {
      var tgt = e.target;
      if (tgt && tgt.dataset && tgt.dataset.noPaste === '1') {
        e.preventDefault(); flag('paste_attempt'); return;
      }
      flag('paste_attempt');
    });

    document.addEventListener('keydown', function (e) {
      var k = e.key, c = e.ctrlKey || e.metaKey;
      if (k === 'F12') { e.preventDefault(); flag('keyboard_shortcut',{k:'F12'}); }
      if (c && (k === 'u' || k === 's' || k === 'p')) { e.preventDefault(); flag('keyboard_shortcut',{k:k}); }
      if (c && (k === 'c' || k === 'v' || k === 'x' || k === 'a')) {
        var t = e.target && e.target.tagName;
        if (t !== 'TEXTAREA' && t !== 'INPUT') { e.preventDefault(); flag('keyboard_shortcut',{k:k}); }
      }
      if (e.altKey && k === 'Tab') flag('keyboard_shortcut',{k:'AltTab'});
    });

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'visible') flag('tab_switch');
    });
    window.addEventListener('blur',  function () { flag('window_blur'); });
    window.addEventListener('focus', function () { /* recovery */ });

    /* DevTools heuristic: window outer/inner delta. */
    setInterval(function () {
      var dw = (window.outerWidth - window.innerWidth);
      var dh = (window.outerHeight - window.innerHeight);
      if (dw > 200 || dh > 200) flag('devtools_open', { dw: dw, dh: dh });
    }, 2000);

    /* Console-timing trick. */
    setInterval(function () {
      var t = performance.now();
      // eslint-disable-next-line no-console
      console.log('%c', '');
      var d = performance.now() - t;
      if (d > 100) flag('devtools_open', { lag: d });
      console.clear();
    }, 5000);

    /* Fullscreen enforcement. */
    function enterFullscreen() {
      var el = document.documentElement;
      if (el.requestFullscreen) el.requestFullscreen().catch(function(){});
    }
    enterFullscreen();
    document.addEventListener('fullscreenchange', function () {
      if (!document.fullscreenElement) {
        flag('fullscreen_exit');
        setTimeout(enterFullscreen, 500);
      }
    });

    /* Block back button. */
    history.pushState(null, '', location.href);
    window.addEventListener('popstate', function () {
      history.pushState(null, '', location.href);
    });

    /* Override clipboard read silently. */
    try {
      if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText = function () { return Promise.resolve(''); };
      }
    } catch (e) {}

    /* Disable text selection in question area (CSS handles, JS belt-and-braces). */
    $$('.qarea .q-body, .qarea .opts').forEach(function (el) {
      el.addEventListener('selectstart', function (e) { e.preventDefault(); });
    });

    /* ---- Heartbeat ---- */
    function heartbeat() {
      var batch = pendingEvents.splice(0);
      postJSON('/api/heartbeat.php', { attempt_id: attemptId, events: batch })
        .then(function (r) {
          heartbeatMisses = 0;
          if (r && r.ok) {
            if (r.terminated) finalize(true);
          }
        })
        .catch(function () {
          heartbeatMisses++;
          if (heartbeatMisses >= 3 && !submitted) finalize(true);
        });
    }
    setInterval(heartbeat, 30000);
    heartbeat();

    /* ---- Timer ---- */
    var timerEl = $('#examTimer');
    function tick() {
      remaining = Math.max(0, remaining - 1);
      var m = Math.floor(remaining / 60), s = remaining % 60;
      if (timerEl) {
        timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        timerEl.classList.toggle('danger', remaining <= 300);
      }
      if (remaining <= 0 && !submitted) finalize(false, true);
    }
    setInterval(tick, 1000);
    tick();

    /* ---- Submit ---- */
    function finalize(terminated, autoSubmit) {
      if (submitted) return;
      submitted = true;
      saveCurrent(true);
      postJSON('/api/submit-exam.php', { attempt_id: attemptId, is_terminated: !!terminated })
        .then(function (r) {
          if (r && r.redirect) location.href = r.redirect;
          else location.href = '/student/dashboard.php';
        });
    }
    var fb = $('#finishBtn');
    if (fb) fb.addEventListener('click', function () {
      if (confirm('Submit your exam now? You cannot return after submitting.')) finalize(false);
    });

    show(0);
    renderAllMath();

    /* Apply lightweight code highlighting for code answer textareas. */
    $$('.code-editor textarea').forEach(initSimpleCodeHighlight);
  }

  /* ---------- Tiny code highlighter (no third-party) ---------- */
  function initSimpleCodeHighlight(ta) {
    var pre = ta.parentNode.querySelector('.code-highlight');
    if (!pre) return;
    var lang = ta.parentNode.dataset.language || 'plain';
    function update() { pre.innerHTML = highlight(ta.value, lang) + '\n'; }
    ta.addEventListener('input', update);
    update();
  }
  function highlight(src, lang) {
    var keywords = {
      php: 'function|return|if|else|elseif|while|for|foreach|class|public|private|protected|static|new|use|namespace|echo|print|array|true|false|null',
      python: 'def|return|if|elif|else|while|for|in|class|import|from|as|with|try|except|finally|None|True|False|pass|lambda',
      javascript: 'function|return|if|else|while|for|var|let|const|class|new|true|false|null|undefined|import|from|export',
      c: 'int|char|float|double|return|if|else|while|for|struct|typedef|void|static|const',
      cpp: 'int|char|float|double|return|if|else|while|for|struct|typedef|void|class|public|private|protected|template|typename|const|static|new|delete|namespace|using',
      java: 'class|public|private|protected|static|void|int|String|return|if|else|while|for|new|true|false|null|import|package|extends|implements',
      sql: 'select|from|where|insert|into|update|delete|values|join|left|right|inner|outer|on|and|or|not|null|primary|key|foreign|references|create|table|drop|alter',
      bash: 'if|then|fi|else|elif|while|do|done|for|in|case|esac|function|return|export|local|echo'
    };
    var k = keywords[lang] || '';
    var s = String(src).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    s = s.replace(/("([^"\\]|\\.)*"|'([^'\\]|\\.)*')/g,'<span style="color:#a6e22e">$1</span>');
    s = s.replace(/(\/\/.*$|\#.*$)/gm,'<span style="color:#75715e">$1</span>');
    if (k) s = s.replace(new RegExp('\\b(' + k + ')\\b','g'), '<span style="color:#66d9ef">$1</span>');
    s = s.replace(/\b(\d+)\b/g,'<span style="color:#ae81ff">$1</span>');
    return s;
  }

  /* ---------- Boot ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    initBuilder();
    initExam();
    if (!$('.exam-wrap')) renderAllMath();
  });
})();

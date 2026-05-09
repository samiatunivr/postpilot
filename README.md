# ProctorDesk — Online Exam Platform

Low-bandwidth proctored exam platform: PHP 8.3, vanilla JS, MySQL 8. Zero
Composer / npm / CDN dependencies. Targets <50 KB per page (gzipped).

## Stack & guarantees
- PHP 8.3 with `declare(strict_types=1)` in every file
- PDO + prepared statements only (no raw SQL interpolation anywhere)
- Sessions: HttpOnly, Secure (auto-detected), SameSite=Strict, regenerated on login
- Per-request CSP nonce, X-Frame-Options DENY, no inline scripts
- gzip output via `ob_gzhandler`
- Native anti-cheat — no third-party SDK

## Setup

1. **Create database**
   ```sh
   mysql -u root -p -e "CREATE DATABASE proctordesk DEFAULT CHARSET utf8mb4;"
   mysql -u root -p -e "CREATE USER 'proctordesk'@'localhost' IDENTIFIED BY 'change-me';"
   mysql -u root -p -e "GRANT ALL ON proctordesk.* TO 'proctordesk'@'localhost';"
   mysql -u proctordesk -p proctordesk < schema.sql
   ```

2. **Edit `config.php`** — set `DB_HOST`, `DB_USER`, `DB_PASS`.

3. **Set webroot to the project directory.** Apache/Nginx must point at this
   folder. Ensure PHP can write to `uploads/exam-files/`.

4. **Visit `https://yourhost/`** and sign in with the seed admin:
   - Email: `admin@proctordesk.local`
   - Password: `Admin@1234`
   - Change this immediately via *Users → Reset password*.

5. **(Optional) KaTeX self-host.** A working stub ships in `assets/katex.min.*`
   so math text falls back to monospace. To enable real rendering, download the
   official KaTeX 0.16.x release (MIT — https://github.com/KaTeX/KaTeX/releases)
   and overwrite:
   - `assets/katex.min.js`
   - `assets/katex.min.css`
   - `assets/fonts/*.woff2`

## Default credentials
| Role  | Email                       | Password   |
|-------|-----------------------------|------------|
| Admin | admin@proctordesk.local     | Admin@1234 |

## Folder layout
```
/                    login + config + auth helpers
/admin/              dashboard, users, exams, reports
/instructor/         dashboard, exam-builder, results
/student/            dashboard, take-exam, results
/api/                heartbeat, submit-answer, submit-exam (JSON, CSRF, rate-limited)
/assets/             app.css, app.js, katex.min.* (self-hosted)
/uploads/exam-files/ user uploads (PHP execution blocked via .htaccess)
/includes/           db.php, functions.php, mailer.php, anti_cheat.php
schema.sql           full schema with FKs, indexes, seed admin
```

## Roles
- **Admin** — full CRUD on users, assigns students to exams, terminates live
  attempts, sees full cheat timeline.
- **Instructor** — creates and edits own exams, builds questions per subject,
  views and grades results, releases scores.
- **Student** — takes assigned exams in a proctored environment, sees own
  released results.

## Anti-cheat (native)
Client-side JS (`assets/app.js`):
- Force fullscreen + re-enter on exit
- Block right-click, copy/cut/paste, Ctrl+S/U/P, F12, Alt+Tab
- Page Visibility tracking (tab_switch, window_blur)
- DevTools heuristic via window outer/inner delta + console-timing
- Disabled clipboard read; selection blocked in question area
- Heartbeat every 30 s — 3 misses → auto-submit
- Warning overlay before threshold; auto-terminate on N violations

Server-side (`includes/anti_cheat.php` + API):
- Every event logged to `cheat_logs` (with IP and JSON extra)
- Per-attempt event counter on `exam_attempts.cheat_flag_count`
- Multi-session lock (rejects parallel attempts)
- Server-time validation against attempt window
- Question/attempt ownership check on every save
- CSRF on every AJAX call (double-submit cookie + session)
- 1 save per 2 s rate limit on answer submissions
- Login throttling — 5 fails per 15 min per IP

## Subject-specific editors
- **Math / Physics / Chemistry** — LaTeX toolbar (frac, sqrt, integral, sigma,
  Greek letters, chemistry arrows), live KaTeX preview pane (debounced 300 ms),
  collapsible periodic-table picker (118 elements), unit picker.
- **Coding** — Tiny self-hosted regex highlighter (PHP / Python / JS / C / C++ /
  Java / SQL / Bash); language picker; expected-output and starter-code fields;
  optional paste-block per question via `data-no-paste`.
- **English** — plain textarea with optional word limit and live counter.
- **All types** — MCQ, True/False, Short answer, Fill-in-blank, Code, optional
  ≤500 KB image (jpg/png/gif/svg validated server-side, renamed to UUID).

## Auto-grading
- MCQ, True/False, Fill — graded automatically on submit.
- Short / Code — graded manually by the instructor in
  `/instructor/results.php`.
- Score = sum(marks_awarded) / sum(question marks) × 100.

## Performance notes
- gzip via `ob_gzhandler` on every response.
- All images `loading="lazy"`; system font stack only.
- Single CSS / JS bundle, served with `Cache-Control: max-age=86400` (configure
  at the web-server level — sample Apache directive below).
- AJAX payloads JSON, well under 2 KB per call.

Sample Apache cache headers (place in `.htaccess` at the project root):
```
<FilesMatch "\.(css|js|woff2)$">
  Header set Cache-Control "public, max-age=86400"
</FilesMatch>
```

## Notes / extension points
- Multi-face / webcam proctoring: schema includes a `multi_face` event type;
  wire a getUserMedia frame-checker in `app.js` and POST events to
  `/api/heartbeat.php`. *Can be extended.*
- Email notifications: `includes/mailer.php` provides `send_mail()`. Hook it
  into account creation or exam assignment if desired.
- APCu cache: exam metadata reads can be cached when the extension is loaded.

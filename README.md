# PostPilot — Eurobillr Integration Guide
## Hostinger cPanel + Railway + Eurobillr PHP SaaS

---

## What you get when done

PostPilot appears as a native sidebar page in Eurobillr at:
`yourdomain.com/dashboard/scheduler.php`

- Same navbar, same sidebar, same Bootstrap 5 design system (var(--primary), etc.)
- Clicking "Social Scheduler" in the sidebar nav loads it like any other page
- No second login — your existing $auth->isLoggedIn() check gates it
- cPanel cron fires the scheduler every minute via the PHP trigger script

---

## File placement on Hostinger

```
/public_html/
├── dashboard/
│   ├── scheduler.php          NEW — the PostPilot iframe page
│   ├── scheduler_token.php    NEW — AJAX endpoint for token refresh
│   ├── postpilot_auth.php     NEW — JWT generator (no composer needed)
│   ├── invoices.php           (existing)
│   ├── sidebar.php            (existing — add 4 lines)
│   ├── header.php             (existing — no changes)
│   └── footer.php             (existing — no changes)
└── cron/
    └── postpilot.php          NEW — cron trigger (keep outside public reach)
```

---

## Step 1 — Deploy PostPilot to Railway (free)

Push to GitHub, then connect to Railway:
1. railway.app → New Project → Deploy from GitHub
2. Go to Variables tab and add:

   PORT=3000
   CRON_SECRET=generate-with: php -r "echo bin2hex(random_bytes(32));"
   APP_KEY=same-value-as-POSTPILOT_SECRET-in-postpilot_auth.php
   ALLOWED_ORIGINS=https://yourdomain.com

3. Settings → Domains → Add Custom Domain → scheduler.yourdomain.com
   Railway gives you a CNAME value to add in Hostinger DNS.

Test: https://scheduler.yourdomain.com/api/health → should return {"ok":true}

---

## Step 2 — DNS in Hostinger hPanel

Domains → DNS Zone → Add CNAME:
  Name:   scheduler
  Target: yourapp.up.railway.app
  TTL:    3600

Wait 5–30 min for propagation.

---

## Step 3 — Upload files to Eurobillr dashboard

Upload from the cron/ folder:
  cron/scheduler.php       → /public_html/dashboard/scheduler.php
  cron/scheduler_token.php → /public_html/dashboard/scheduler_token.php
  cron/postpilot_auth.php  → /public_html/dashboard/postpilot_auth.php

Edit postpilot_auth.php — set your two constants:
  define('POSTPILOT_SECRET', 'your-32-char-secret');
  define('POSTPILOT_URL',    'https://scheduler.yourdomain.com');

POSTPILOT_SECRET must match APP_KEY set in Railway variables.

---

## Step 4 — Add to sidebar.php (4 lines total)

Near the top where you detect the current page, add:
  $is_scheduler = ($current_page === 'scheduler.php');

Inside your <ul class="nav flex-column">, add the nav item:
  <li class="nav-item">
    <a class="nav-link <?php echo $is_scheduler ? 'active' : ''; ?>"
       href="<?php echo dashboard_url('scheduler'); ?>">
      <span class="nav-icon"><i class="fas fa-rocket"></i></span>
      <span class="nav-text">Social Scheduler</span>
      <span class="badge ms-auto" style="background:var(--primary-light);color:var(--primary);font-size:.65rem;font-weight:600;border-radius:4px;padding:2px 6px;">AI</span>
    </a>
  </li>

In url_helper.php, add to the $pages array:
  'scheduler' => 'scheduler.php',

---

## Step 5 — Set up cPanel cron in Hostinger hPanel

Upload cron/postpilot.php to /public_html/cron/postpilot.php
Edit the two constants at the top of that file.

hPanel → Advanced → Cron Jobs → Add:
  Command:   php /home/YOURUSERNAME/public_html/cron/postpilot.php
  Schedule:  * * * * *  (every minute)

Protect it — create /public_html/cron/.htaccess:
  Order Deny,Allow
  Deny from all

Check the log at /public_html/cron/postpilot_cron.log — new line every minute.

---

## Timezone note

All PostPilot slot times are UTC.
Brussels UTC+2 (summer): subtract 2 hours — 09:00 local = 07:00 UTC in PostPilot.
Brussels UTC+1 (winter): subtract 1 hour  — 09:00 local = 08:00 UTC in PostPilot.

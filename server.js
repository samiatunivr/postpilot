require('dotenv').config();
const express = require('express');
const cors = require('cors');
const axios = require('axios');
const crypto = require('crypto');
const OAuth = require('oauth-1.0a');
const fs = require('fs');
const path = require('path');
const FormData = require('form-data');
const multer = require('multer');
const jwt = require('jsonwebtoken');

const app = express();
app.use(express.json());

// ── CORS & iframe embedding headers ──────────────────────────────────────────
// Set ALLOWED_ORIGINS in Railway Variables as comma-separated domains:
// e.g.  https://eurobillr.com,https://www.eurobillr.com
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '').split(',').map(s => s.trim()).filter(Boolean);

// Must run BEFORE express.static so HTML pages also get the headers
app.use((req, res, next) => {
  const origin = req.headers.origin || req.headers.referer || '';
  const frameOrigins = ALLOWED_ORIGINS.length ? ALLOWED_ORIGINS.join(' ') : "'self'";

  // Content-Security-Policy frame-ancestors is the modern standard
  // X-Frame-Options is the legacy fallback (some older browsers)
  res.setHeader('Content-Security-Policy', `frame-ancestors ${frameOrigins}`);
  res.setHeader('X-Frame-Options', ALLOWED_ORIGINS.length ? `ALLOW-FROM ${ALLOWED_ORIGINS[0]}` : 'SAMEORIGIN');

  // Required for cross-origin iframe to load resources correctly
  res.setHeader('Cross-Origin-Resource-Policy', 'cross-origin');
  res.setHeader('Cross-Origin-Embedder-Policy', 'unsafe-none');
  next();
});

app.use(cors({
  origin: (origin, cb) => {
    if (!origin || ALLOWED_ORIGINS.includes(origin) || ALLOWED_ORIGINS.includes('*')) return cb(null, true);
    cb(null, true); // allow all in dev; tighten via ALLOWED_ORIGINS in prod
  },
  credentials: true
}));

// ── Serve index.html dynamically — inject token directly into the page ──────
// This avoids all browser storage issues with cross-origin iframes.
// The token arrives in ?token=, Express reads it, embeds it as a JS variable.
// The static middleware still serves other assets (CSS, JS, images).
app.get('/', (req, res) => {
  const token = req.query.token || '';
  const htmlPath = path.join(__dirname, 'public', 'index.html');
  if (!fs.existsSync(htmlPath)) return res.status(404).send('index.html not found');

  let html = fs.readFileSync(htmlPath, 'utf8');

  // Inject the token as a global JS variable right before </head>
  // This runs before any script on the page — guaranteed available immediately
  const injection = `<script>window.__PP_TOKEN__ = ${JSON.stringify(token)};</script>`;
  html = html.replace('</head>', injection + '</head>');

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.send(html);
});

// Serve all other static assets (fonts, images, etc.) normally
app.use(express.static(path.join(__dirname, 'public')));

// ── File paths ────────────────────────────────────────────────────────────────
const DATA_FILE  = path.join(__dirname, 'data.json');
const UPLOAD_DIR = path.join(__dirname, 'uploads');
if (!fs.existsSync(UPLOAD_DIR)) fs.mkdirSync(UPLOAD_DIR);
app.use('/uploads', express.static(UPLOAD_DIR));

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, UPLOAD_DIR),
  filename:    (req, file, cb) => cb(null, `upload_${Date.now()}${path.extname(file.originalname) || '.jpg'}`)
});
const upload = multer({ storage, limits: { fileSize: 20 * 1024 * 1024 },
  fileFilter: (req, file, cb) => file.mimetype.startsWith('image/') ? cb(null, true) : cb(new Error('Images only'))
});

// ── Auth middleware ───────────────────────────────────────────────────────────
function requireAuth(req, res, next) {
  // Skip auth entirely if APP_KEY not set — local dev mode
  if (!process.env.APP_KEY) return next();

  // Accept token from (in priority order):
  // 1. Authorization: Bearer <token>  header  (JS apiFetch sends this)
  // 2. ?token= query param            (initial iframe src load)
  // 3. x-postpilot-token header       (fallback)
  const token =
    req.headers['authorization']?.replace('Bearer ', '').trim() ||
    req.query.token ||
    req.headers['x-postpilot-token'];

  if (!token) {
    return res.status(401).json({ error: 'Unauthorized — no token provided' });
  }

  try {
    const decoded = jwt.verify(token, process.env.APP_KEY, { algorithms: ['HS256'] });
    req.user = decoded;
    next();
  } catch (e) {
    return res.status(401).json({ error: 'Token invalid or expired — please refresh the page' });
  }
}

// Public routes: health check, cron tick (protected by CRON_SECRET instead)
// All /api/* routes require auth EXCEPT /api/health and /api/cron-tick
app.use('/api', (req, res, next) => {
  if (req.path === '/health' || req.path === '/cron-tick') return next();
  requireAuth(req, res, next);
});

// ── AI Provider registry ──────────────────────────────────────────────────────
const AI_PROVIDERS = {
  anthropic: { name: 'Claude', fullName: 'Claude (Anthropic)', icon: '🟣', color: '#a78bfa', configKey: 'anthropicApiKey',
    models: [
      { id: 'claude-sonnet-4-20250514',  label: 'Claude Sonnet 4',  badge: 'Recommended' },
      { id: 'claude-opus-4-5',           label: 'Claude Opus 4.5',  badge: 'Most Capable' },
      { id: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5', badge: 'Fastest' },
    ]},
  openai: { name: 'GPT-4o', fullName: 'ChatGPT (OpenAI)', icon: '🟢', color: '#10a37f', configKey: 'openaiApiKey',
    models: [
      { id: 'gpt-4o',      label: 'GPT-4o',      badge: 'Best Quality' },
      { id: 'gpt-4o-mini', label: 'GPT-4o Mini', badge: 'Fast & Cheap' },
      { id: 'o3-mini',     label: 'o3-mini',      badge: 'Reasoning' },
    ]},
  gemini: { name: 'Gemini', fullName: 'Gemini (Google)', icon: '🔵', color: '#4285f4', configKey: 'geminiApiKey',
    models: [
      { id: 'gemini-2.0-flash',             label: 'Gemini 2.0 Flash', badge: 'Recommended' },
      { id: 'gemini-2.5-pro-preview-05-06', label: 'Gemini 2.5 Pro',   badge: 'Most Capable' },
      { id: 'gemini-1.5-flash',             label: 'Gemini 1.5 Flash', badge: 'Fast' },
    ]},
  groq: { name: 'Groq', fullName: 'Groq (Ultra-fast)', icon: '⚡', color: '#f59e0b', configKey: 'groqApiKey',
    models: [
      { id: 'llama-3.3-70b-versatile', label: 'Llama 3.3 70B', badge: 'Best Quality' },
      { id: 'llama-3.1-8b-instant',    label: 'Llama 3.1 8B',  badge: 'Ultra-fast' },
      { id: 'mixtral-8x7b-32768',      label: 'Mixtral 8x7B',  badge: '' },
    ]},
  deepseek: { name: 'DeepSeek', fullName: 'DeepSeek', icon: '🐋', color: '#06b6d4', configKey: 'deepseekApiKey',
    models: [
      { id: 'deepseek-chat',     label: 'DeepSeek-V3', badge: 'Recommended' },
      { id: 'deepseek-reasoner', label: 'DeepSeek-R1', badge: 'Reasoning' },
    ]},
  qwen: { name: 'Qwen', fullName: 'Qwen (Alibaba)', icon: '🌐', color: '#8b5cf6', configKey: 'qwenApiKey',
    models: [
      { id: 'qwen-max',   label: 'Qwen-Max',   badge: 'Best Quality' },
      { id: 'qwen-plus',  label: 'Qwen-Plus',  badge: 'Balanced' },
      { id: 'qwen-turbo', label: 'Qwen-Turbo', badge: 'Fast' },
      { id: 'qwq-32b',    label: 'QwQ-32B',    badge: 'Reasoning' },
    ]},
};

const PLATFORMS = {
  twitter:   { name: 'X (Twitter)', icon: '𝕏',  color: '#1d9bf0', maxChars: 280 },
  facebook:  { name: 'Facebook',    icon: '𝑓',  color: '#1877f2', maxChars: 63206 },
  instagram: { name: 'Instagram',   icon: '📸', color: '#e1306c', maxChars: 2200 },
  linkedin:  { name: 'LinkedIn',    icon: '🔗', color: '#0a66c2', maxChars: 3000 },
  pinterest: { name: 'Pinterest',   icon: '📌', color: '#e60023', maxChars: 500 },
  tiktok:    { name: 'TikTok',      icon: '🎵', color: '#ff0050', maxChars: 2200 },
  gbp:       { name: 'Google Biz',  icon: '🔍', color: '#4285f4', maxChars: 1500 },
  reddit:    { name: 'Reddit',      icon: '🟠', color: '#ff4500', maxChars: 40000 },
};

const PLATFORM_PROMPTS = {
  twitter:   `X (Twitter): Max 280 chars. Punchy, witty, direct. 2–3 hashtags. Strong hook in first line.`,
  facebook:  `Facebook: Conversational and warm. 1–3 short paragraphs. Ask a question or CTA. 2–4 hashtags.`,
  instagram: `Instagram: Visual storytelling. Attention-grabbing opener. Emoji-rich, authentic. 5–10 hashtags at end.`,
  linkedin:  `LinkedIn: Professional but human. Insight or story. Line breaks for readability. 3–5 hashtags. Max 3000 chars.`,
  pinterest: `Pinterest: SEO-friendly, vivid visual description. Keywords naturally placed. 2–3 hashtags. Max 500 chars.`,
  tiktok:    `TikTok: Trendy, energetic, casual. Punchy caption with hook. 3–5 trending hashtags. Max 150 chars.`,
  gbp:       `Google Business Profile: Professional, local SEO. Offer/update/tip with clear CTA. No hashtags. Max 1500 chars.`,
  reddit:    `Reddit: Write a genuine, community-first post. NO marketing language, NO hashtags, NO emojis. Redditors hate blatant promotion. Frame it as sharing knowledge, asking for feedback, or starting a discussion. Use a compelling title (max 300 chars) on the FIRST LINE, then a blank line, then the body (2-5 paragraphs). Be specific, honest, add real value. End with a question to spark discussion. Format: TITLE\n\nBODY`,
};

// ── AES-256-GCM Encryption ────────────────────────────────────────────────────
// ENCRYPTION_KEY must be 32 bytes (64 hex chars) set in Railway env variables.
// Generate with: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
// If not set we warn loudly and store plaintext (local dev only).

const ENCRYPTION_KEY_HEX = process.env.ENCRYPTION_KEY || '';
const ENCRYPTION_ENABLED = ENCRYPTION_KEY_HEX.length === 64;

if (!ENCRYPTION_ENABLED) {
  console.warn('⚠️  ENCRYPTION_KEY not set — API keys will be stored as PLAINTEXT.');
  console.warn('   Set a 64-char hex key in Railway env variables before going live.');
  console.warn('   Generate: node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"');
}

// Keys in data.config that contain sensitive credentials — everything else is stored as-is
const SENSITIVE_KEYS = new Set([
  'anthropicApiKey', 'openaiApiKey', 'geminiApiKey', 'groqApiKey',
  'deepseekApiKey',  'qwenApiKey',   'falApiKey',
  'twitterApiKey',   'twitterApiSecret', 'twitterAccessToken', 'twitterAccessSecret',
  'facebookAccessToken', 'instagramAccessToken', 'linkedinAccessToken',
  'pinterestAccessToken', 'tiktokAccessToken',
  'gbpAccessToken',
  'redditRefreshToken',
]);

// Format stored in data.json for an encrypted value:
// "enc:v1:<iv_hex>:<authTag_hex>:<ciphertext_hex>"
const ENC_PREFIX = 'enc:v1:';

function encryptValue(plaintext) {
  if (!ENCRYPTION_ENABLED || !plaintext) return plaintext;
  const key = Buffer.from(ENCRYPTION_KEY_HEX, 'hex');
  const iv  = crypto.randomBytes(12); // 96-bit IV for GCM
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const authTag = cipher.getAuthTag(); // 16-byte authentication tag
  return `${ENC_PREFIX}${iv.toString('hex')}:${authTag.toString('hex')}:${encrypted.toString('hex')}`;
}

function decryptValue(stored) {
  if (!stored || !stored.startsWith(ENC_PREFIX)) return stored; // plaintext or empty
  if (!ENCRYPTION_ENABLED) {
    console.error('✗ Cannot decrypt: ENCRYPTION_KEY not set but encrypted value found in data.json');
    return '';
  }
  try {
    const parts = stored.slice(ENC_PREFIX.length).split(':');
    if (parts.length !== 3) throw new Error('Malformed encrypted value');
    const [ivHex, authTagHex, ciphertextHex] = parts;
    const key        = Buffer.from(ENCRYPTION_KEY_HEX, 'hex');
    const iv         = Buffer.from(ivHex, 'hex');
    const authTag    = Buffer.from(authTagHex, 'hex');
    const ciphertext = Buffer.from(ciphertextHex, 'hex');
    const decipher   = crypto.createDecipheriv('aes-256-gcm', key, iv);
    decipher.setAuthTag(authTag);
    return decipher.update(ciphertext) + decipher.final('utf8');
  } catch (e) {
    console.error('✗ Decryption failed:', e.message);
    return ''; // return empty rather than crash — will surface as "key not configured"
  }
}

// Encrypt all sensitive fields before writing config to disk
function encryptConfig(config) {
  const out = {};
  for (const [k, v] of Object.entries(config)) {
    out[k] = (SENSITIVE_KEYS.has(k) && v && !v.startsWith(ENC_PREFIX))
      ? encryptValue(v)
      : v;
  }
  return out;
}

// Decrypt all sensitive fields after reading from disk
// This is what the rest of the app uses — always plaintext in memory
function decryptConfig(config) {
  const out = {};
  for (const [k, v] of Object.entries(config)) {
    out[k] = SENSITIVE_KEYS.has(k) ? decryptValue(v) : v;
  }
  return out;
}

// ── Data ──────────────────────────────────────────────────────────────────────
function loadData() {
  if (fs.existsSync(DATA_FILE)) {
    try {
      const raw = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
      // Decrypt config on load so the rest of the app always sees plaintext
      if (raw.config) raw.config = decryptConfig(raw.config);
      return raw;
    } catch(e) { console.error('Failed to load data.json:', e.message); }
  }
  return { config: {}, slots: [], instruction: '', logs: [], lastGenerated: null, uploadedImage: null, subreddits: [] };
}

function saveData() {
  // Encrypt config before writing to disk — everything else written as-is
  const toWrite = { ...data, config: encryptConfig(data.config || {}) };
  fs.writeFileSync(DATA_FILE, JSON.stringify(toWrite, null, 2));
}

let data = loadData();

// ── Health check (public) ─────────────────────────────────────────────────────
app.get('/api/health', (req, res) => res.json({
  ok: true,
  ts: new Date().toISOString(),
  encryption: ENCRYPTION_ENABLED ? 'aes-256-gcm' : 'disabled'
}));

// ── Migrate plaintext data.json to encrypted (call once after setting ENCRYPTION_KEY) ──
// POST /api/migrate-encryption   (requires auth)
app.post('/api/migrate-encryption', (req, res) => {
  if (!ENCRYPTION_ENABLED) {
    return res.status(400).json({ error: 'ENCRYPTION_KEY not set — cannot migrate' });
  }

  const cfg = data.config || {};
  let migrated = 0, alreadyEncrypted = 0, skipped = 0;

  for (const k of SENSITIVE_KEYS) {
    const v = cfg[k];
    if (!v) { skipped++; continue; }
    if (v.startsWith(ENC_PREFIX)) { alreadyEncrypted++; continue; }
    cfg[k] = v; // already plaintext in memory — saveData() will encrypt
    migrated++;
  }

  saveData(); // writes encrypted version
  res.json({ ok: true, migrated, alreadyEncrypted, skipped });
});

// ── CRON TICK endpoint (called by cPanel cron via PHP) ────────────────────────
// Protected by CRON_SECRET header, NOT by JWT
app.post('/api/cron-tick', async (req, res) => {
  const secret = req.headers['x-cron-secret'] || req.body?.secret;
  if (process.env.CRON_SECRET && secret !== process.env.CRON_SECRET) {
    return res.status(403).json({ error: 'Invalid cron secret' });
  }

  const now = new Date();
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const currentTime = `${hh}:${mm}`;

  // Find all active slots whose time matches current UTC HH:MM
  const dueSlots = (data.slots || []).filter(s => s.active && s.time === currentTime);

  res.json({ ok: true, currentTime, dueSlots: dueSlots.length });

  // Run pipelines async (don't block the response)
  for (const slot of dueSlots) {
    runPipeline({
      label:      slot.label,
      imageMode:  slot.imageMode  || 'none',
      platforms:  slot.platforms  || ['twitter', 'facebook'],
      aiProvider: slot.aiProvider || 'anthropic',
      aiModel:    slot.aiModel    || null,
      platformAI: slot.platformAI || {},
    }).catch(err => addLog('error', 'system', `✗ Pipeline error: ${err.message}`));
  }
});

// ── Config ────────────────────────────────────────────────────────────────────
app.post('/api/config', (req, res) => {
  // Merge incoming values into in-memory config (plaintext in memory)
  // saveData() will encrypt sensitive fields before writing to disk
  for (const [k, v] of Object.entries(req.body)) {
    if (v !== undefined && v !== null && v !== '') {
      data.config[k] = v; // store plaintext in memory
    }
  }
  saveData(); // encrypts on write
  res.json({ success: true, encrypted: ENCRYPTION_ENABLED });
});

app.get('/api/config', (req, res) => {
  // Return masked values — show last 4 chars of plaintext (from in-memory decrypted config)
  // Also indicate which keys are encrypted at rest
  const masked = {};
  for (const [k, v] of Object.entries(data.config || {})) {
    if (!v) { masked[k] = ''; continue; }
    masked[k] = '••••' + v.slice(-4);
  }
  res.json({ values: masked, encryptionEnabled: ENCRYPTION_ENABLED });
});

app.post('/api/instruction', (req, res) => { data.instruction = req.body.instruction || ''; saveData(); res.json({ success: true }); });
app.get('/api/instruction', (req, res) => res.json({ instruction: data.instruction || '' }));

// ── Reddit subreddit list ─────────────────────────────────────────────────────
// Stored as data.subreddits = ['programming', 'entrepreneur', 'smallbusiness', ...]
app.get('/api/subreddits', (req, res) => res.json({ subreddits: data.subreddits || [] }));
app.post('/api/subreddits', (req, res) => {
  const { subreddits } = req.body;
  if (!Array.isArray(subreddits)) return res.status(400).json({ error: 'subreddits must be an array' });
  // Sanitise — strip r/ prefix, lowercase, no spaces
  data.subreddits = subreddits.map(s => s.trim().replace(/^r\//, '').toLowerCase()).filter(Boolean);
  saveData();
  res.json({ success: true, subreddits: data.subreddits });
});

// ── Upload Image ──────────────────────────────────────────────────────────────
app.post('/api/upload-image', upload.single('image'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'No file received' });
  if (data.uploadedImage?.filename) { const old = path.join(UPLOAD_DIR, data.uploadedImage.filename); if (fs.existsSync(old)) fs.unlinkSync(old); }
  data.uploadedImage = { filename: req.file.filename, originalName: req.file.originalname, mimetype: req.file.mimetype, size: req.file.size, url: `/uploads/${req.file.filename}`, uploadedAt: new Date().toISOString() };
  saveData(); res.json({ success: true, image: data.uploadedImage });
});
app.delete('/api/upload-image', (req, res) => {
  if (data.uploadedImage?.filename) { const f = path.join(UPLOAD_DIR, data.uploadedImage.filename); if (fs.existsSync(f)) fs.unlinkSync(f); }
  data.uploadedImage = null; saveData(); res.json({ success: true });
});
app.get('/api/upload-image', (req, res) => res.json({ image: data.uploadedImage || null }));

// ── Slots CRUD ────────────────────────────────────────────────────────────────
app.get('/api/slots', (req, res) => res.json(data.slots || []));
app.post('/api/slots', (req, res) => {
  const { time, label, imageMode, platforms, aiProvider, aiModel, platformAI, redditLink } = req.body;
  if (!time) return res.status(400).json({ error: 'time required' });
  const slot = { id: Date.now().toString(), time, label: label || `Post at ${time} UTC`, active: true, imageMode: imageMode || 'none', platforms: platforms || ['twitter', 'facebook'], aiProvider: aiProvider || 'anthropic', aiModel: aiModel || 'claude-sonnet-4-20250514', platformAI: platformAI || {}, redditLink: redditLink || '', createdAt: new Date().toISOString() };
  data.slots.push(slot); saveData(); res.json(slot);
});
app.patch('/api/slots/:id', (req, res) => {
  const slot = data.slots.find(s => s.id === req.params.id);
  if (!slot) return res.status(404).json({ error: 'Not found' });
  Object.assign(slot, req.body); saveData(); res.json(slot);
});
app.delete('/api/slots/:id', (req, res) => {
  data.slots = data.slots.filter(s => s.id !== req.params.id); saveData(); res.json({ success: true });
});

// ── Status & meta ─────────────────────────────────────────────────────────────
app.get('/api/status', (req, res) => {
  const activeSlots = (data.slots || []).filter(s => s.active);
  const now = new Date();
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const currentTime = `${hh}:${mm}`;

  // Next slot after now
  const upcoming = activeSlots
    .map(s => ({ ...s, nextRun: getNextRun(s.time) }))
    .sort((a, b) => new Date(a.nextRun) - new Date(b.nextRun));

  const configuredAIs = {};
  for (const [id, p] of Object.entries(AI_PROVIDERS)) configuredAIs[id] = !!data.config?.[p.configKey];

  res.json({
    totalSlots: (data.slots || []).length, activeSlots: activeSlots.length,
    nextRun: upcoming[0]?.nextRun || null, nextSlotLabel: upcoming[0]?.label || null,
    currentTime, lastGenerated: data.lastGenerated,
    instructionSet: !!data.instruction, uploadedImage: data.uploadedImage || null,
    configuredAIs,
    credentialsSet: {
      twitter:   !!(data.config?.twitterApiKey && data.config?.twitterAccessToken),
      facebook:  !!(data.config?.facebookPageId && data.config?.facebookAccessToken),
      instagram: !!(data.config?.instagramAccountId && data.config?.instagramAccessToken),
      linkedin:  !!(data.config?.linkedinAccessToken),
      pinterest: !!(data.config?.pinterestAccessToken && data.config?.pinterestBoardId),
      tiktok:    !!(data.config?.tiktokAccessToken),
      gbp:       !!(data.config?.gbpAccountId && data.config?.gbpLocationId && data.config?.gbpAccessToken),
      reddit:    !!(data.config?.redditClientId && data.config?.redditRefreshToken),
    }
  });
});
app.get('/api/logs',      (req, res) => res.json((data.logs || []).slice(-300).reverse()));
app.get('/api/providers', (req, res) => res.json(AI_PROVIDERS));
app.get('/api/platforms', (req, res) => res.json(PLATFORMS));

app.post('/api/run-now', async (req, res) => {
  const { imageMode, platforms, aiProvider, aiModel, platformAI } = req.body || {};
  res.json({ success: true });
  runPipeline({ label: 'Manual run', imageMode: imageMode || 'none', platforms: platforms || Object.keys(PLATFORMS), aiProvider: aiProvider || 'anthropic', aiModel: aiModel || null, platformAI: platformAI || {} });
});

app.post('/api/preview', async (req, res) => {
  try {
    const { instruction, platform, aiProvider, aiModel } = req.body;
    if (!instruction && !data.instruction) return res.status(400).json({ error: 'No instruction' });
    const content = await generatePost({ instruction: instruction || data.instruction, platform: platform || 'twitter', aiProvider: aiProvider || 'anthropic', aiModel: aiModel || null });
    res.json({ content, platform, aiProvider, aiModel });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.post('/api/preview-image', async (req, res) => {
  try {
    const instruction = req.body.instruction || data.instruction;
    if (!instruction) return res.status(400).json({ error: 'No instruction' });
    const imagePrompt = await generateImagePrompt(instruction);
    const imageUrl    = await generateImage(imagePrompt);
    res.json({ imageUrl, imagePrompt });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

// ── AI generation ─────────────────────────────────────────────────────────────
function buildPrompt(instruction, platform) {
  const now = new Date();
  const tod = now.getUTCHours() < 12 ? 'morning' : now.getUTCHours() < 17 ? 'afternoon' : 'evening';
  const today = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  return `Today is ${today} (${tod}, UTC).\n\nYou are a professional social media copywriter. Write a post for ${PLATFORMS[platform]?.name || platform} based on:\n\nBUSINESS INSTRUCTIONS:\n${instruction}\n\nPLATFORM STYLE GUIDE:\n${PLATFORM_PROMPTS[platform] || PLATFORM_PROMPTS.twitter}\n\nRules:\n- ONE post only — no variants, no labels, no preamble\n- Follow the platform style guide exactly\n- Vary the angle and wording every time\n- Output ONLY the post text, nothing else`;
}

async function generatePost({ instruction, platform, aiProvider = 'anthropic', aiModel = null }) {
  switch (aiProvider) {
    case 'anthropic': return callAnthropic(instruction, platform, aiModel);
    case 'openai':    return callOAICompat('https://api.openai.com/v1/chat/completions',    data.config?.openaiApiKey,    aiModel || 'gpt-4o',                  instruction, platform, 'OpenAI');
    case 'gemini':    return callGemini(instruction, platform, aiModel);
    case 'groq':      return callOAICompat('https://api.groq.com/openai/v1/chat/completions', data.config?.groqApiKey,   aiModel || 'llama-3.3-70b-versatile', instruction, platform, 'Groq');
    case 'deepseek':  return callOAICompat('https://api.deepseek.com/chat/completions',      data.config?.deepseekApiKey, aiModel || 'deepseek-chat',          instruction, platform, 'DeepSeek');
    case 'qwen':      return callOAICompat('https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', data.config?.qwenApiKey, aiModel || 'qwen-max', instruction, platform, 'Qwen');
    default: throw new Error(`Unknown AI provider: ${aiProvider}`);
  }
}

async function callAnthropic(instruction, platform, model) {
  const apiKey = data.config?.anthropicApiKey;
  if (!apiKey) throw new Error('Anthropic API key not configured');
  const r = await axios.post('https://api.anthropic.com/v1/messages',
    { model: model || 'claude-sonnet-4-20250514', max_tokens: 600, messages: [{ role: 'user', content: buildPrompt(instruction, platform) }] },
    { headers: { 'x-api-key': apiKey, 'anthropic-version': '2023-06-01', 'content-type': 'application/json' } });
  return r.data.content[0].text.trim();
}

async function callOAICompat(url, apiKey, model, instruction, platform, name) {
  if (!apiKey) throw new Error(`${name} API key not configured`);
  const r = await axios.post(url,
    { model, max_tokens: 600, messages: [{ role: 'user', content: buildPrompt(instruction, platform) }] },
    { headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' } });
  return r.data.choices[0].message.content.trim();
}

async function callGemini(instruction, platform, model) {
  const apiKey = data.config?.geminiApiKey;
  if (!apiKey) throw new Error('Gemini API key not configured');
  const m = model || 'gemini-2.0-flash';
  const r = await axios.post(`https://generativelanguage.googleapis.com/v1beta/models/${m}:generateContent?key=${apiKey}`,
    { contents: [{ parts: [{ text: buildPrompt(instruction, platform) }] }], generationConfig: { maxOutputTokens: 600 } },
    { headers: { 'Content-Type': 'application/json' } });
  return r.data.candidates[0].content.parts[0].text.trim();
}

async function generateImagePrompt(instruction) {
  const prompt = `Based on this business:\n\n${instruction}\n\nWrite a short image generation prompt for a social media post.\n- Visually striking, professional, photorealistic\n- No text or logos\n- Max 80 words\n- Output ONLY the prompt`;
  const order = ['anthropic', 'openai', 'groq', 'deepseek', 'qwen', 'gemini'];
  for (const p of order) {
    if (data.config?.[AI_PROVIDERS[p]?.configKey]) {
      return generatePost({ instruction: prompt, platform: null, aiProvider: p, aiModel: AI_PROVIDERS[p].models.at(-1)?.id });
    }
  }
  throw new Error('No AI key configured');
}

async function generateImage(prompt) {
  const apiKey = data.config?.falApiKey;
  if (!apiKey) throw new Error('fal.ai API key not configured');
  const sub = await axios.post('https://queue.fal.run/fal-ai/flux/schnell',
    { prompt, image_size: 'square_hd', num_images: 1, num_inference_steps: 4 },
    { headers: { 'Authorization': `Key ${apiKey}`, 'Content-Type': 'application/json' } });
  const { request_id } = sub.data;
  for (let i = 0; i < 30; i++) {
    await sleep(2000);
    const s = await axios.get(`https://queue.fal.run/fal-ai/flux/schnell/requests/${request_id}/status`, { headers: { 'Authorization': `Key ${apiKey}` } });
    if (s.data.status === 'COMPLETED') {
      const r = await axios.get(`https://queue.fal.run/fal-ai/flux/schnell/requests/${request_id}`, { headers: { 'Authorization': `Key ${apiKey}` } });
      const url = r.data?.images?.[0]?.url; if (!url) throw new Error('No image URL'); return url;
    }
    if (s.data.status === 'FAILED') throw new Error('fal.ai generation failed');
  }
  throw new Error('fal.ai timed out');
}

const sleep = ms => new Promise(r => setTimeout(r, ms));
async function downloadImage(url) {
  const res = await axios.get(url, { responseType: 'arraybuffer' });
  return { buffer: Buffer.from(res.data), contentType: res.headers['content-type'] || 'image/jpeg' };
}

// ── Pipeline ──────────────────────────────────────────────────────────────────
async function runPipeline({ label = 'Scheduled', imageMode = 'none', platforms = ['twitter','facebook'], aiProvider = 'anthropic', aiModel = null, platformAI = {} } = {}) {
  if (!platforms?.length) return;
  const getAI = (p) => ({ provider: platformAI[p]?.provider || aiProvider, model: platformAI[p]?.model || aiModel });
  const prov = AI_PROVIDERS[aiProvider] || AI_PROVIDERS.anthropic;
  addLog('info', 'system', `▶ [${label}] ${prov.icon} ${prov.name} → ${platforms.join(', ')}`);
  if (!data.instruction) { addLog('error', 'system', '✗ No instruction configured'); return; }

  // Generate text per platform
  const contents = {};
  await Promise.all(platforms.map(async (p) => {
    const { provider, model } = getAI(p);
    try {
      contents[p] = await generatePost({ instruction: data.instruction, platform: p, aiProvider: provider, aiModel: model });
      addLog('success', 'ai', `${AI_PROVIDERS[provider]?.icon||'🤖'} ${PLATFORMS[p]?.name}: "${contents[p].substring(0, 55)}…"`);
    } catch (err) {
      addLog('error', 'ai', `✗ ${PLATFORMS[p]?.name} (${AI_PROVIDERS[provider]?.name}): ${err.message}`);
    }
  }));

  data.lastGenerated = { contents, timestamp: new Date().toISOString(), label, imageMode, platforms, aiProvider, aiModel, platformAI };
  saveData();

  // Image
  let imageBuffer = null, imageContentType = 'image/jpeg';
  if (imageMode === 'uploaded' && data.uploadedImage?.filename) {
    try { imageBuffer = fs.readFileSync(path.join(UPLOAD_DIR, data.uploadedImage.filename)); imageContentType = data.uploadedImage.mimetype; addLog('success', 'ai', `✓ Using uploaded: ${data.uploadedImage.originalName}`); }
    catch (e) { addLog('error', 'ai', `✗ Could not read uploaded image`); }
  } else if (imageMode === 'ai') {
    try {
      addLog('info', 'ai', '⏳ Generating AI image…');
      const prompt = await generateImagePrompt(data.instruction);
      const imageUrl = await generateImage(prompt);
      const dl = await downloadImage(imageUrl);
      imageBuffer = dl.buffer; imageContentType = dl.contentType;
      data.lastGenerated.imageUrl = imageUrl; saveData();
      addLog('success', 'ai', '✓ AI image generated');
    } catch (err) { addLog('error', 'ai', `✗ AI image: ${err.message}`); }
  }

  // Inject reddit link from slot config so postReddit() can access it
  data._currentRedditLink = '';
  const activeSlot = (data.slots || []).find(s => s.active && s.platforms?.includes('reddit'));
  if (activeSlot?.redditLink) data._currentRedditLink = activeSlot.redditLink;

  // Post
  for (const p of platforms) {
    if (!contents[p]) continue;
    if (!isPlatformConfigured(p)) { addLog('info', p, `⚠ ${PLATFORMS[p]?.name} credentials not set`); continue; }
    try { await postToPlatform(p, contents[p], imageBuffer, imageContentType); addLog('success', p, `✓ Posted to ${PLATFORMS[p]?.name}${imageBuffer ? ' with image' : ''}`); }
    catch (err) { addLog('error', p, `✗ ${PLATFORMS[p]?.name}: ${extractError(err)}`); }
  }

  addLog('info', 'system', `■ [${label}] Pipeline complete`);
  saveData();
}

// ── Platform posting ──────────────────────────────────────────────────────────
async function postToPlatform(platform, text, ib, ct) {
  switch (platform) {
    case 'twitter':   return postToTwitter(text, ib, ct);
    case 'facebook':  return ib ? postFBImage(text, ib, ct) : postFB(text);
    case 'instagram': return postIG(text, ib);
    case 'linkedin':  return postLI(text, ib, ct);
    case 'pinterest': return postPin(text, ib, ct);
    case 'tiktok':    return postTT(text, ib, ct);
    case 'gbp':       return postGBP(text);
    case 'reddit':    return postReddit(text);
    default: throw new Error(`Unknown: ${platform}`);
  }
}
function isPlatformConfigured(p) {
  const c = data.config || {};
  return !!({ twitter: ()=>c.twitterApiKey&&c.twitterApiSecret&&c.twitterAccessToken&&c.twitterAccessSecret, facebook: ()=>c.facebookPageId&&c.facebookAccessToken, instagram: ()=>c.instagramAccountId&&c.instagramAccessToken, linkedin: ()=>c.linkedinAccessToken, pinterest: ()=>c.pinterestAccessToken&&c.pinterestBoardId, tiktok: ()=>c.tiktokAccessToken, gbp: ()=>c.gbpAccountId&&c.gbpLocationId&&c.gbpAccessToken, reddit: ()=>c.redditClientId&&c.redditClientSecret&&c.redditRefreshToken }[p]?.());
}
function extractError(e) { return e.response?.data?.error?.message || e.response?.data?.detail || e.message; }
function makeOAuth(c) { return new OAuth({ consumer: { key: c.twitterApiKey, secret: c.twitterApiSecret }, signature_method: 'HMAC-SHA1', hash_function(b,k){ return crypto.createHmac('sha1',k).update(b).digest('base64'); } }); }
async function uploadTWMedia(ib,ct) { const c=data.config,o=makeOAuth(c),tk={key:c.twitterAccessToken,secret:c.twitterAccessSecret},u='https://upload.twitter.com/1.1/media/upload.json'; const iA=o.toHeader(o.authorize({url:u,method:'POST'},tk)); const iR=await axios.post(u,new URLSearchParams({command:'INIT',total_bytes:ib.length,media_type:ct,media_category:'tweet_image'}).toString(),{headers:{...iA,'Content-Type':'application/x-www-form-urlencoded'}}); const mid=iR.data.media_id_string; const cs=5*1024*1024;let seg=0; for(let o2=0;o2<ib.length;o2+=cs){const f=new FormData();f.append('command','APPEND');f.append('media_id',mid);f.append('segment_index',String(seg++));f.append('media',ib.slice(o2,o2+cs),{filename:'img.jpg',contentType:ct});const a=o.toHeader(o.authorize({url:u,method:'POST'},tk));await axios.post(u,f,{headers:{...a,...f.getHeaders()}});} const fA=o.toHeader(o.authorize({url:u,method:'POST'},tk));await axios.post(u,new URLSearchParams({command:'FINALIZE',media_id:mid}).toString(),{headers:{...fA,'Content-Type':'application/x-www-form-urlencoded'}});return mid; }
async function postToTwitter(text,ib,ct) { const c=data.config,o=makeOAuth(c),url='https://api.twitter.com/2/tweets'; const auth=o.toHeader(o.authorize({url,method:'POST'},{key:c.twitterAccessToken,secret:c.twitterAccessSecret})); const body={text}; if(ib){const mid=await uploadTWMedia(ib,ct);body.media={media_ids:[mid]};} return(await axios.post(url,body,{headers:{...auth,'Content-Type':'application/json'}})).data; }
async function postFB(msg){return(await axios.post(`https://graph.facebook.com/v19.0/${data.config.facebookPageId}/feed`,{message:msg,access_token:data.config.facebookAccessToken})).data;}
async function postFBImage(msg,ib,ct){const f=new FormData();f.append('source',ib,{filename:'img.jpg',ct});f.append('caption',msg);f.append('access_token',data.config.facebookAccessToken);return(await axios.post(`https://graph.facebook.com/v19.0/${data.config.facebookPageId}/photos`,f,{headers:f.getHeaders()})).data;}
async function postIG(caption,ib){const c=data.config,imageUrl=data.lastGenerated?.imageUrl;if(!imageUrl){addLog('info','instagram','⚠ Instagram needs a public image URL — skipped');return null;}const cr=await axios.post(`https://graph.facebook.com/v19.0/${c.instagramAccountId}/media`,null,{params:{image_url:imageUrl,caption,access_token:c.instagramAccessToken}});return(await axios.post(`https://graph.facebook.com/v19.0/${c.instagramAccountId}/media_publish`,null,{params:{creation_id:cr.data.id,access_token:c.instagramAccessToken}})).data;}
async function postLI(text,ib,ct){const c=data.config;const pr=await axios.get('https://api.linkedin.com/v2/userinfo',{headers:{Authorization:`Bearer ${c.linkedinAccessToken}`}});const urn=`urn:li:person:${pr.data.sub}`;if(ib){const reg=await axios.post('https://api.linkedin.com/v2/assets?action=registerUpload',{registerUploadRequest:{recipes:['urn:li:digitalmediaRecipe:feedshare-image'],owner:urn,serviceRelationships:[{relationshipType:'OWNER',identifier:'urn:li:userGeneratedContent'}]}},{headers:{Authorization:`Bearer ${c.linkedinAccessToken}`,'Content-Type':'application/json'}});const uploadUrl=reg.data.value.uploadMechanism['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest'].uploadUrl;const asset=reg.data.value.asset;await axios.put(uploadUrl,ib,{headers:{Authorization:`Bearer ${c.linkedinAccessToken}`,'Content-Type':ct}});return(await axios.post('https://api.linkedin.com/v2/ugcPosts',{author:urn,lifecycleState:'PUBLISHED',specificContent:{'com.linkedin.ugc.ShareContent':{shareCommentary:{text},shareMediaCategory:'IMAGE',media:[{status:'READY',description:{text:text.substring(0,200)},media:asset,title:{text:'Post'}}]}},visibility:{'com.linkedin.ugc.MemberNetworkVisibility':'PUBLIC'}},{headers:{Authorization:`Bearer ${c.linkedinAccessToken}`,'Content-Type':'application/json'}})).data;}return(await axios.post('https://api.linkedin.com/v2/ugcPosts',{author:urn,lifecycleState:'PUBLISHED',specificContent:{'com.linkedin.ugc.ShareContent':{shareCommentary:{text},shareMediaCategory:'NONE'}},visibility:{'com.linkedin.ugc.MemberNetworkVisibility':'PUBLIC'}},{headers:{Authorization:`Bearer ${c.linkedinAccessToken}`,'Content-Type':'application/json'}})).data;}
async function postPin(desc,ib,ct){const c=data.config,imageUrl=data.lastGenerated?.imageUrl;const body={board_id:c.pinterestBoardId,title:desc.substring(0,100),description:desc};if(imageUrl)body.media_source={source_type:'image_url',url:imageUrl};else if(ib)body.media_source={source_type:'image_base64',content_type:ct,data:ib.toString('base64')};return(await axios.post('https://api.pinterest.com/v5/pins',body,{headers:{Authorization:`Bearer ${c.pinterestAccessToken}`,'Content-Type':'application/json'}})).data;}
async function postTT(caption,ib,ct){const c=data.config;if(!ib){addLog('info','tiktok','⚠ TikTok requires an image — skipped');return null;}const initRes=await axios.post('https://open.tiktokapis.com/v2/post/publish/content/init/',{post_info:{title:caption,privacy_level:'PUBLIC_TO_EVERYONE',disable_duet:false,disable_comment:false,disable_stitch:false},source_info:{source:'FILE_UPLOAD',video_size:ib.length,chunk_size:ib.length,total_chunk_count:1},post_mode:'DIRECT_POST',media_type:'PHOTO'},{headers:{Authorization:`Bearer ${c.tiktokAccessToken}`,'Content-Type':'application/json'}});const{publish_id,upload_url}=initRes.data.data;await axios.put(upload_url,ib,{headers:{'Content-Type':ct,'Content-Range':`bytes 0-${ib.length-1}/${ib.length}`}});return{publish_id};}
async function postGBP(text){const c=data.config;return(await axios.post(`https://mybusiness.googleapis.com/v4/accounts/${c.gbpAccountId}/locations/${c.gbpLocationId}/localPosts`,{languageCode:'en',summary:text,callToAction:{actionType:'LEARN_MORE',url:c.gbpWebsiteUrl||''},topicType:'STANDARD'},{headers:{Authorization:`Bearer ${c.gbpAccessToken}`,'Content-Type':'application/json'}})).data;}

// ── Reddit OAuth2 + posting ───────────────────────────────────────────────────
// Reddit uses OAuth2 with a refresh token (permanent script app).
// Flow: exchange refresh_token → access_token → POST to /api/submit

async function getRedditAccessToken() {
  const c = data.config;
  if (!c.redditClientId || !c.redditClientSecret || !c.redditRefreshToken) {
    throw new Error('Reddit credentials not configured');
  }
  const r = await axios.post('https://www.reddit.com/api/v1/access_token',
    new URLSearchParams({ grant_type: 'refresh_token', refresh_token: c.redditRefreshToken }),
    {
      auth: { username: c.redditClientId, password: c.redditClientSecret },
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'User-Agent': `PostPilot/1.0 by ${c.redditUsername || 'PostPilotBot'}`,
      },
    }
  );
  if (!r.data.access_token) throw new Error('Reddit: failed to get access token — check credentials');
  return r.data.access_token;
}

async function pickSubreddit(postContent) {
  // AI picks the best subreddit from the user's defined list
  const subreddits = data.subreddits || [];
  if (!subreddits.length) throw new Error('No subreddits configured — add subreddits in Reddit Settings');
  if (subreddits.length === 1) return subreddits[0];

  // Use first available AI to pick
  const instruction = data.instruction || postContent;
  const prompt = `Given this social media post content:

"${postContent.substring(0, 500)}"

And this business context: ${instruction.substring(0, 300)}

From this list of subreddits: ${subreddits.join(', ')}

Which single subreddit would be MOST appropriate and receive the best community reception for this post? Consider subreddit rules, typical audience, and content fit.

Respond with ONLY the subreddit name (no r/ prefix), nothing else.`;

  const order = ['anthropic', 'openai', 'groq', 'deepseek', 'qwen', 'gemini'];
  for (const p of order) {
    if (data.config?.[AI_PROVIDERS[p]?.configKey]) {
      try {
        const picked = await generatePost({ instruction: prompt, platform: null, aiProvider: p, aiModel: null });
        const cleaned = picked.trim().replace(/^r\//, '').toLowerCase().replace(/[^a-z0-9_]/g, '');
        if (subreddits.includes(cleaned)) return cleaned;
        // fuzzy match
        const match = subreddits.find(s => s.toLowerCase() === cleaned);
        return match || subreddits[0];
      } catch(e) { /* try next */ }
    }
  }
  return subreddits[0]; // fallback to first
}

async function postReddit(content) {
  const c = data.config;

  // Parse AI output: first line = title, rest = body
  const lines = content.split('\n');
  const title = lines[0].trim().substring(0, 300);
  const body  = lines.slice(1).join('\n').trim();

  // Pick best subreddit
  const subreddit = await pickSubreddit(content);
  addLog('info', 'reddit', `📍 Posting to r/${subreddit}`);

  const accessToken = await getRedditAccessToken();

  // Build submission — text post with optional link
  const currentSlot = (data.slots || []).find(s => s.active); // get link from active slot if set
  const linkUrl = data._currentRedditLink || ''; // injected by pipeline

  let postData;
  if (linkUrl) {
    // Link post: title + URL
    postData = new URLSearchParams({
      api_type: 'json',
      kind: 'link',
      sr: subreddit,
      title,
      url: linkUrl,
      nsfw: 'false',
      spoiler: 'false',
    });
  } else {
    // Text (self) post: title + body
    postData = new URLSearchParams({
      api_type: 'json',
      kind: 'self',
      sr: subreddit,
      title,
      text: body || title,
      nsfw: 'false',
      spoiler: 'false',
    });
  }

  const r = await axios.post('https://oauth.reddit.com/api/submit', postData, {
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/x-www-form-urlencoded',
      'User-Agent': `PostPilot/1.0 by ${c.redditUsername || 'PostPilotBot'}`,
    },
  });

  const json = r.data?.json;
  if (json?.errors?.length) throw new Error(`Reddit API error: ${json.errors[0]}`);

  const postUrl = json?.data?.url || '';
  if (postUrl) addLog('success', 'reddit', `✓ Posted to r/${subreddit}: ${postUrl}`);
  return json?.data;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function getNextRun(time) {
  if (!time) return null;
  const [h, m] = time.split(':').map(Number);
  const next = new Date(); next.setUTCHours(h, m, 0, 0);
  if (next <= new Date()) next.setUTCDate(next.getUTCDate() + 1);
  return next.toISOString();
}

function addLog(status, platform, message) {
  if (!data.logs) data.logs = [];
  data.logs.push({ id: Date.now() + Math.random().toString(36).slice(2), timestamp: new Date().toISOString(), status, platform, message });
  if (data.logs.length > 500) data.logs = data.logs.slice(-500);
}

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`✅  PostPilot → http://localhost:${PORT}`));

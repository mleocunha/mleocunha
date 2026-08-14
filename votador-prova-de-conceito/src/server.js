import express from 'express';
import multer from 'multer';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runVotador, DEFAULTS, VOTADOR_BUILD } from './lib/runner.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '..');
const uploadsDir = path.join(rootDir, 'uploads');
const publicDir = path.join(__dirname, 'public');

fs.mkdirSync(uploadsDir, { recursive: true });
fs.mkdirSync(path.join(rootDir, 'results'), { recursive: true });
fs.mkdirSync(path.join(rootDir, 'credentials'), { recursive: true });

const upload = multer({ dest: uploadsDir });
const app = express();
const PORT = Number(process.env.PORT || 3847);

/** @type {{ running: boolean, summary: object|null, events: object[], runId: string|null, abort?: AbortController }} */
const state = {
  running: false,
  summary: null,
  events: [],
  runId: null,
  abort: undefined,
};

app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(express.static(publicDir));

app.get('/api/defaults', (_req, res) => {
  res.json({
    ...DEFAULTS,
    loginPaths: [
      { value: '/id.php', label: '/id.php' },
      { value: '/wp-login.php', label: '/wp-login.php' },
      { value: 'custom', label: 'Personalizado…' },
    ],
    macNote:
      'No macOS, autorize Automação/Acessibilidade para o Terminal (ou Cursor) e o Google Chrome em Ajustes do Sistema → Privacidade e Segurança.',
  });
});

app.get('/api/events', (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  res.flushHeaders?.();

  // Tell the UI which run (if any) is current, then replay only that run.
  res.write(
    `data: ${JSON.stringify({
      level: 'info',
      message: 'log_reset',
      runId: state.runId,
      build: VOTADOR_BUILD,
    })}\n\n`
  );
  if (state.runId) {
    for (const ev of state.events.slice(-200)) {
      if (ev.runId === state.runId) {
        res.write(`data: ${JSON.stringify(ev)}\n\n`);
      }
    }
  }

  const timer = setInterval(() => {
    res.write(`: ping\n\n`);
  }, 15000);

  const onEvent = (ev) => {
    res.write(`data: ${JSON.stringify(ev)}\n\n`);
  };
  const idx = listeners.push(onEvent) - 1;

  req.on('close', () => {
    clearInterval(timer);
    listeners.splice(idx, 1);
  });
});

const listeners = [];

function pushEvent(ev) {
  const stamped = {
    ts: new Date().toISOString(),
    runId: state.runId,
    ...ev,
  };
  // Keep the active runId authoritative even if caller passed one.
  stamped.runId = state.runId;
  state.events.push(stamped);
  if (state.events.length > 2000) {
    state.events.splice(0, state.events.length - 2000);
  }
  for (const fn of listeners) {
    try {
      fn(stamped);
    } catch {
      /* ignore */
    }
  }
}

app.get('/api/status', (_req, res) => {
  res.json({
    running: state.running,
    summary: state.summary,
    eventCount: state.events.length,
    runId: state.runId,
    build: VOTADOR_BUILD,
  });
});

const RESULT_DOWNLOAD_ALLOW = new Set([
  'falhas-reset-senha.csv',
  'falhas-login-email.csv',
  'falhas-login-voto.csv',
  'falhas-repetidas.json',
  'passwords.csv',
  'receipts.csv',
  'summary.json',
  'failures.ndjson',
  'events.ndjson',
]);

/**
 * Download a file from the last run's resultsDir (basename whitelist only).
 */
app.get('/api/results/:fileName', (req, res) => {
  const fileName = path.basename(String(req.params.fileName || ''));
  if (!RESULT_DOWNLOAD_ALLOW.has(fileName)) {
    res.status(400).json({ error: 'Arquivo não permitido para download.' });
    return;
  }
  const resultsDir = state.summary?.resultsDir;
  if (!resultsDir || typeof resultsDir !== 'string') {
    res.status(404).json({ error: 'Nenhum resultado disponível ainda.' });
    return;
  }
  const full = path.resolve(resultsDir, fileName);
  const root = path.resolve(resultsDir);
  if (!full.startsWith(root + path.sep) && full !== root) {
    res.status(400).json({ error: 'Caminho inválido.' });
    return;
  }
  if (!fs.existsSync(full)) {
    res.status(404).json({ error: `Arquivo não encontrado: ${fileName}` });
    return;
  }
  res.download(full, fileName);
});

app.post('/api/stop', (_req, res) => {
  if (state.abort) {
    state.abort.abort();
  }
  res.json({ ok: true });
});

app.post('/api/start', upload.single('csv'), async (req, res) => {
  if (state.running) {
    res.status(409).json({ error: 'Já existe uma execução em andamento.' });
    return;
  }

  const body = req.body || {};
  const csvPath = req.file?.path;
  if (!csvPath) {
    res.status(400).json({ error: 'Envie o CSV de cadastro eleitoral.' });
    return;
  }

  const config = {
    platformUrl: String(body.platformUrl || '').trim(),
    loginPath: String(body.loginPath || '/wp-login.php'),
    loginPathCustom: String(body.loginPathCustom || '').trim(),
    adminUser: String(body.adminUser || '').trim(),
    adminPassword: String(body.adminPassword || ''),
    csvPath,
    chromePath: String(body.chromePath || '').trim() || undefined,
    ignoreHTTPSErrors: body.ignoreHTTPSErrors === '1' || body.ignoreHTTPSErrors === 'true',
    windowsInitial: Number(
      body.windowsInitial ?? body.windows ?? DEFAULTS.windowsInitial
    ),
    windowsMax: Number(body.windowsMax ?? body.windows ?? DEFAULTS.windowsMax),
    tabsInitial: Number(
      body.tabsInitial ?? body.tabsPerWindow ?? DEFAULTS.tabsInitial
    ),
    tabsMax: Number(body.tabsMax ?? body.tabsPerWindow ?? DEFAULTS.tabsMax),
    tentativas: Number(body.tentativas || DEFAULTS.tentativas),
    insistencias: Number(body.insistencias || DEFAULTS.insistencias),
    limiteRetentativas: Number(body.limiteRetentativas || DEFAULTS.limiteRetentativas),
    passwordChangePoc:
      body.passwordChangePoc === '1' ||
      body.passwordChangePoc === 'true' ||
      body.passwordChangePoc === 'on',
    mailUrl: String(body.mailUrl || DEFAULTS.mailUrl).trim() || DEFAULTS.mailUrl,
  };

  if (!config.platformUrl) {
    cleanupUpload(csvPath);
    res.status(400).json({ error: 'URL da plataforma é obrigatória.' });
    return;
  }

  const runId = `run-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  state.running = true;
  state.summary = null;
  state.events = [];
  state.runId = runId;
  state.abort = new AbortController();

  // Drop previous-run lines from every open SSE client before new output.
  pushEvent({
    level: 'info',
    message: 'log_reset',
    runId,
    build: VOTADOR_BUILD,
  });

  res.json({ ok: true, message: 'Execução iniciada.', runId, build: VOTADOR_BUILD });

  try {
    const summary = await runVotador(config, {
      signal: state.abort.signal,
      onEvent: pushEvent,
    });
    state.summary = summary;
    pushEvent({ level: 'info', message: 'Execução finalizada', summary });
  } catch (err) {
    const message = String(err.message || err);
    pushEvent({ level: 'error', message: `Execução abortada: ${message}` });
    state.summary = { error: message };
  } finally {
    state.running = false;
    state.abort = undefined;
    cleanupUpload(csvPath);
  }
});

function cleanupUpload(filePath) {
  try {
    if (filePath && fs.existsSync(filePath)) {
      fs.unlinkSync(filePath);
    }
  } catch {
    /* ignore */
  }
}

app.listen(PORT, () => {
  // eslint-disable-next-line no-console
  console.log(`Votador PoC [${VOTADOR_BUILD}] → http://127.0.0.1:${PORT}`);
  // eslint-disable-next-line no-console
  console.log(
    'No macOS: Ajustes do Sistema → Privacidade e Segurança → Autorizar Automação/Acessibilidade para o Terminal e o Google Chrome.'
  );
});

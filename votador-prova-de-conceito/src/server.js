import express from 'express';
import multer from 'multer';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runVotador, DEFAULTS } from './lib/runner.js';

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

/** @type {{ running: boolean, summary: object|null, events: object[], abort?: AbortController }} */
const state = {
  running: false,
  summary: null,
  events: [],
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

  for (const ev of state.events.slice(-200)) {
    res.write(`data: ${JSON.stringify(ev)}\n\n`);
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
  state.events.push(ev);
  if (state.events.length > 2000) {
    state.events.splice(0, state.events.length - 2000);
  }
  for (const fn of listeners) {
    try {
      fn(ev);
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
  });
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
    windows: Number(body.windows || DEFAULTS.windows),
    tabsPerWindow: Number(body.tabsPerWindow || DEFAULTS.tabsPerWindow),
    tentativas: Number(body.tentativas || DEFAULTS.tentativas),
    insistencias: Number(body.insistencias || DEFAULTS.insistencias),
    limiteRetentativas: Number(body.limiteRetentativas || DEFAULTS.limiteRetentativas),
  };

  if (!config.platformUrl) {
    cleanupUpload(csvPath);
    res.status(400).json({ error: 'URL da plataforma é obrigatória.' });
    return;
  }

  state.running = true;
  state.summary = null;
  state.events = [];
  state.abort = new AbortController();

  res.json({ ok: true, message: 'Execução iniciada.' });

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
  console.log(`Votador PoC → http://127.0.0.1:${PORT}`);
  // eslint-disable-next-line no-console
  console.log(
    'No macOS: Ajustes do Sistema → Privacidade e Segurança → Autorizar Automação/Acessibilidade para o Terminal e o Google Chrome.'
  );
});

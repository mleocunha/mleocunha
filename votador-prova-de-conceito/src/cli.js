#!/usr/bin/env node
import path from 'node:path';
import { runVotador, DEFAULTS } from './lib/runner.js';

function usage() {
  console.log(`Uso:
  npm run vote -- --url https://plataforma.example --csv ./cadastro.csv --admin USER --admin-pass SECRET

Opções:
  --url              URL da plataforma (obrigatório)
  --csv              CSV de cadastro eleitoral (obrigatório)
  --admin            Usuário admin WP (obrigatório)
  --admin-pass       Senha admin (obrigatório; ou env RSES_ADMIN_PASS)
  --login            /id.php | /wp-login.php | caminho custom (default /wp-login.php)
  --chrome           Caminho do Google Chrome (opcional)
  --windows-initial  Janelas Chrome iniciais (default ${DEFAULTS.windowsInitial})
  --windows-max      Janelas Chrome máximas (default ${DEFAULTS.windowsMax})
  --tabs-initial     Contextos/janela iniciais (default ${DEFAULTS.tabsInitial})
  --tabs-max         Contextos/janela máximos (default ${DEFAULTS.tabsMax})
  --windows          (legado) fixa janelas inicial=máximo
  --tabs             (legado) fixa contextos inicial=máximo
  --tentativas       x — eleitores pulados/registrados no teste (default ${DEFAULTS.tentativas})
  --insistencias     n — retentativas por falha (default ${DEFAULTS.insistencias})
  --limite           y — teto por falha; ao atingir y nessa falha, para o teste (default ${DEFAULTS.limiteRetentativas})
  --ignore-https     Ignorar erros de certificado
  --password-change  Ativa PoC com troca de senha (SnappyMail)
  --mail-url         URL SnappyMail (default ${DEFAULTS.mailUrl})
`);
}

function arg(name, fallback) {
  const idx = process.argv.indexOf(`--${name}`);
  if (idx === -1) {
    return fallback;
  }
  return process.argv[idx + 1];
}

function hasFlag(name) {
  return process.argv.includes(`--${name}`);
}

if (hasFlag('help') || hasFlag('h')) {
  usage();
  process.exit(0);
}

const platformUrl = arg('url');
const csvPath = arg('csv');
const adminUser = arg('admin');
const adminPassword = arg('admin-pass', process.env.RSES_ADMIN_PASS || '');
const loginPath = arg('login', '/wp-login.php');

if (!platformUrl || !csvPath || !adminUser || !adminPassword) {
  usage();
  process.exit(1);
}

const summary = await runVotador({
  platformUrl,
  csvPath: path.resolve(csvPath),
  adminUser,
  adminPassword,
  loginPath,
  chromePath: arg('chrome') || undefined,
  windowsInitial: arg('windows-initial') != null
    ? Number(arg('windows-initial'))
    : undefined,
  windowsMax: arg('windows-max') != null ? Number(arg('windows-max')) : undefined,
  tabsInitial: arg('tabs-initial') != null ? Number(arg('tabs-initial')) : undefined,
  tabsMax: arg('tabs-max') != null ? Number(arg('tabs-max')) : undefined,
  // Legacy fixed concurrency when only --windows / --tabs are passed.
  windows: arg('windows') != null ? Number(arg('windows')) : undefined,
  tabsPerWindow: arg('tabs') != null ? Number(arg('tabs')) : undefined,
  tentativas: Number(arg('tentativas', DEFAULTS.tentativas)),
  insistencias: Number(arg('insistencias', DEFAULTS.insistencias)),
  limiteRetentativas: Number(arg('limite', DEFAULTS.limiteRetentativas)),
  ignoreHTTPSErrors: hasFlag('ignore-https'),
  passwordChangePoc: hasFlag('password-change'),
  mailUrl: arg('mail-url', DEFAULTS.mailUrl),
}, {
  onEvent: (ev) => {
    const ts = formatLocalClock(ev.ts);
    const line = `${ts} [${ev.level || 'info'}] ${ev.message || ''}`;
    if (ev.level === 'error') {
      console.error(line, ev.error || '');
    } else {
      console.log(line);
    }
  },
});

/**
 * Local wall-clock of the machine running the CLI (not UTC slice of ISO).
 * @param {string|undefined} iso
 */
function formatLocalClock(iso) {
  const d = iso ? new Date(iso) : new Date();
  if (Number.isNaN(d.getTime())) {
    return '';
  }
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

console.log(JSON.stringify(summary, null, 2));

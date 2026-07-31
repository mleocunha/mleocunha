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
  --windows          Janelas Chrome (default ${DEFAULTS.windows})
  --tabs             Contextos por janela (default ${DEFAULTS.tabsPerWindow})
  --tentativas       x — falhas de eleitor toleradas (default ${DEFAULTS.tentativas})
  --insistencias     n — retentativas por eleitor (default ${DEFAULTS.insistencias})
  --limite           y — teto global de retentativas (default ${DEFAULTS.limiteRetentativas})
  --ignore-https     Ignorar erros de certificado
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
  windows: Number(arg('windows', DEFAULTS.windows)),
  tabsPerWindow: Number(arg('tabs', DEFAULTS.tabsPerWindow)),
  tentativas: Number(arg('tentativas', DEFAULTS.tentativas)),
  insistencias: Number(arg('insistencias', DEFAULTS.insistencias)),
  limiteRetentativas: Number(arg('limite', DEFAULTS.limiteRetentativas)),
  ignoreHTTPSErrors: hasFlag('ignore-https'),
}, {
  onEvent: (ev) => {
    const line = `[${ev.level || 'info'}] ${ev.message || ''}`;
    if (ev.level === 'error') {
      console.error(line, ev.error || '');
    } else {
      console.log(line);
    }
  },
});

console.log(JSON.stringify(summary, null, 2));

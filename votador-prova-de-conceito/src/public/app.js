const form = document.getElementById('runForm');
const logEl = document.getElementById('log');
const runState = document.getElementById('runState');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const copyLogBtn = document.getElementById('copyLogBtn');
const saveLogBtn = document.getElementById('saveLogBtn');
const logActionStatus = document.getElementById('logActionStatus');
const loginPath = document.getElementById('loginPath');
const customLoginWrap = document.getElementById('customLoginWrap');
const macBanner = document.getElementById('macBanner');

/** Only append SSE lines that belong to this run (null = show nothing yet). */
let activeRunId = null;
/** Ignore stale SSE traffic until /api/start returns the new runId. */
let awaitingRunId = false;

const localTimeFmt = new Intl.DateTimeFormat(undefined, {
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
});

if (/Mac|iPhone|iPad/.test(navigator.platform) || navigator.userAgent.includes('Mac OS')) {
  macBanner.hidden = false;
}

loginPath.addEventListener('change', () => {
  customLoginWrap.classList.toggle('hidden', loginPath.value !== 'custom');
});

const passwordChangePoc = document.getElementById('passwordChangePoc');
const mailUrlWrap = document.getElementById('mailUrlWrap');
passwordChangePoc.addEventListener('change', () => {
  mailUrlWrap.classList.toggle('hidden', !passwordChangePoc.checked);
});

/**
 * Format event timestamp in the local timezone of this machine (browser = PoC host).
 * @param {string|undefined} iso
 */
function formatLocalTime(iso) {
  const d = iso ? new Date(iso) : new Date();
  if (Number.isNaN(d.getTime())) {
    return localTimeFmt.format(new Date());
  }
  return localTimeFmt.format(d);
}

/**
 * Filename-safe local stamp for downloads.
 */
function localFileStamp() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return [
    d.getFullYear(),
    pad(d.getMonth() + 1),
    pad(d.getDate()),
    '-',
    pad(d.getHours()),
    pad(d.getMinutes()),
    pad(d.getSeconds()),
  ].join('');
}

function clearLog() {
  logEl.replaceChildren();
  hideLogActionStatus();
}

function progressText() {
  return (logEl.innerText || '').trim();
}

function showLogActionStatus(message, isError = false) {
  logActionStatus.hidden = false;
  logActionStatus.textContent = message;
  logActionStatus.classList.toggle('is-error', isError);
  window.clearTimeout(showLogActionStatus._timer);
  showLogActionStatus._timer = window.setTimeout(() => {
    hideLogActionStatus();
  }, 2500);
}

function hideLogActionStatus() {
  logActionStatus.hidden = true;
  logActionStatus.textContent = '';
  logActionStatus.classList.remove('is-error');
}

function appendLog(ev) {
  if (!ev || ev.message === 'log_reset') {
    return;
  }
  const line = document.createElement('div');
  const level = ev.level || 'info';
  if (level === 'error') line.className = 'err';
  if (level === 'warn') line.className = 'warn';
  const ts = formatLocalTime(ev.ts);
  const extraBits = {};
  if (ev.build) extraBits.build = ev.build;
  if (ev.user_login) extraBits.user_login = ev.user_login;
  if (ev.election_id != null) extraBits.election_id = ev.election_id;
  if (ev.round_id != null) extraBits.round_id = ev.round_id;
  if (ev.receipt_hash) extraBits.receipt_hash = ev.receipt_hash;
  if (ev.error) extraBits.error = ev.error;
  if (ev.status) extraBits.status = ev.status;
  const extra = Object.keys(extraBits).length ? ` ${JSON.stringify(extraBits)}` : '';
  line.textContent = `${ts} [${level}] ${ev.message || ''}${extra}`;
  logEl.appendChild(line);
  logEl.scrollTop = logEl.scrollHeight;
}

function handleEvent(ev) {
  if (!ev || typeof ev !== 'object') {
    return;
  }

  if (ev.message === 'log_reset') {
    clearLog();
    activeRunId = ev.runId || null;
    awaitingRunId = false;
    return;
  }

  // While starting, drop everything until the server runId is known.
  if (awaitingRunId) {
    if (ev.runId) {
      activeRunId = ev.runId;
      awaitingRunId = false;
      clearLog();
    } else {
      return;
    }
  }

  if (!activeRunId) {
    return;
  }
  if (ev.runId && ev.runId !== activeRunId) {
    return;
  }

  appendLog(ev);
}

const es = new EventSource('/api/events');
es.onmessage = (msg) => {
  try {
    handleEvent(JSON.parse(msg.data));
  } catch {
    /* ignore */
  }
};

async function refreshStatus() {
  const res = await fetch('/api/status');
  const data = await res.json();
  if (data.running) {
    runState.textContent = 'executando';
    runState.className = 'pill running';
    startBtn.disabled = true;
    stopBtn.disabled = false;
  } else if (data.summary?.error) {
    runState.textContent = 'erro';
    runState.className = 'pill error';
    startBtn.disabled = false;
    stopBtn.disabled = true;
  } else if (data.summary) {
    runState.textContent = 'concluído';
    runState.className = 'pill';
    startBtn.disabled = false;
    stopBtn.disabled = true;
  } else {
    runState.textContent = 'ocioso';
    runState.className = 'pill';
    startBtn.disabled = false;
    stopBtn.disabled = true;
  }
}

setInterval(refreshStatus, 2000);
refreshStatus();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearLog();
  activeRunId = null;
  awaitingRunId = true;
  const fd = new FormData(form);
  startBtn.disabled = true;
  stopBtn.disabled = false;
  runState.textContent = 'iniciando';
  runState.className = 'pill running';

  const res = await fetch('/api/start', { method: 'POST', body: fd });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    awaitingRunId = false;
    activeRunId = null;
    appendLog({ level: 'error', message: data.error || 'Falha ao iniciar', ts: new Date().toISOString() });
    startBtn.disabled = false;
    stopBtn.disabled = true;
    runState.textContent = 'erro';
    runState.className = 'pill error';
    return;
  }

  if (data.runId) {
    activeRunId = data.runId;
    awaitingRunId = false;
    clearLog();
  }
});

stopBtn.addEventListener('click', async () => {
  await fetch('/api/stop', { method: 'POST' });
  appendLog({
    level: 'warn',
    message: 'Pedido de parada enviado…',
    ts: new Date().toISOString(),
  });
});

copyLogBtn.addEventListener('click', async () => {
  const text = progressText();
  if (!text) {
    showLogActionStatus('Nada para copiar ainda.', true);
    return;
  }
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    showLogActionStatus('Progresso copiado.');
  } catch (err) {
    showLogActionStatus(`Não foi possível copiar: ${err.message || err}`, true);
  }
});

saveLogBtn.addEventListener('click', () => {
  const text = progressText();
  if (!text) {
    showLogActionStatus('Nada para salvar ainda.', true);
    return;
  }
  const blob = new Blob([`${text}\n`], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `votador-progresso-${localFileStamp()}.txt`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
  showLogActionStatus('Arquivo de progresso baixado.');
});

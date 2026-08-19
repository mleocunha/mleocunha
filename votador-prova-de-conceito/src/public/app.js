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

/** Non-secret form fields kept across reloads (same idea as SnappyMail URL default). */
const FORM_STORAGE_KEY = 'votador-poc-form-v1';

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
  persistFormPrefs();
});

const passwordChangePoc = document.getElementById('passwordChangePoc');
const visualHighlight = document.getElementById('visualHighlight');
const mailUrlWrap = document.getElementById('mailUrlWrap');
const mailUrlHint = document.getElementById('mailUrlHint');
const platformUrlInput = form.elements.namedItem('platformUrl');
const mailUrlInput = form.elements.namedItem('mailUrl');

function syncMailUrlVisibility() {
  const on = passwordChangePoc.checked;
  mailUrlWrap.classList.toggle('hidden', !on);
  if (mailUrlHint) {
    mailUrlHint.hidden = !on;
  }
}

passwordChangePoc.addEventListener('change', () => {
  syncMailUrlVisibility();
  persistFormPrefs();
});
visualHighlight?.addEventListener('change', persistFormPrefs);

/**
 * Persist platform / mail URLs (and related prefs) in localStorage so reload keeps them.
 * Never stores admin password or CSV.
 */
function persistFormPrefs() {
  try {
    const data = {
      platformUrl: String(platformUrlInput?.value || '').trim(),
      mailUrl: String(mailUrlInput?.value || '').trim(),
      loginPath: String(loginPath?.value || ''),
      loginPathCustom: String(form.elements.namedItem('loginPathCustom')?.value || '').trim(),
      passwordChangePoc: Boolean(passwordChangePoc?.checked),
      visualHighlight: Boolean(visualHighlight?.checked),
      windowsInitial: String(form.elements.namedItem('windowsInitial')?.value || ''),
      windowsMax: String(form.elements.namedItem('windowsMax')?.value || ''),
      tabsInitial: String(form.elements.namedItem('tabsInitial')?.value || ''),
      tabsMax: String(form.elements.namedItem('tabsMax')?.value || ''),
    };
    localStorage.setItem(FORM_STORAGE_KEY, JSON.stringify(data));
  } catch {
    /* private mode / quota */
  }
}

function restoreFormPrefs() {
  try {
    const raw = localStorage.getItem(FORM_STORAGE_KEY);
    if (!raw) {
      return;
    }
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object') {
      return;
    }
    if (data.platformUrl && platformUrlInput) {
      platformUrlInput.value = data.platformUrl;
    }
    if (data.mailUrl && mailUrlInput) {
      mailUrlInput.value = data.mailUrl;
    }
    if (data.loginPath && loginPath) {
      loginPath.value = data.loginPath;
      customLoginWrap.classList.toggle('hidden', loginPath.value !== 'custom');
    }
    const customLogin = form.elements.namedItem('loginPathCustom');
    if (data.loginPathCustom && customLogin) {
      customLogin.value = data.loginPathCustom;
    }
    if (typeof data.passwordChangePoc === 'boolean' && passwordChangePoc) {
      passwordChangePoc.checked = data.passwordChangePoc;
      syncMailUrlVisibility();
    }
    if (typeof data.visualHighlight === 'boolean' && visualHighlight) {
      visualHighlight.checked = data.visualHighlight;
    }
    for (const key of ['windowsInitial', 'windowsMax', 'tabsInitial', 'tabsMax']) {
      const el = form.elements.namedItem(key);
      if (data[key] && el) {
        el.value = data[key];
      }
    }
  } catch {
    /* ignore corrupt storage */
  }
}

restoreFormPrefs();

for (const el of [platformUrlInput, mailUrlInput, form.elements.namedItem('loginPathCustom')]) {
  if (!el) {
    continue;
  }
  el.addEventListener('change', persistFormPrefs);
  el.addEventListener('blur', persistFormPrefs);
}

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
  if (!logActionStatus) {
    return;
  }
  logActionStatus.hidden = false;
  logActionStatus.textContent = message;
  logActionStatus.classList.toggle('is-error', isError);
  window.clearTimeout(showLogActionStatus._timer);
  showLogActionStatus._timer = window.setTimeout(() => {
    hideLogActionStatus();
  }, 2800);
}

function hideLogActionStatus() {
  if (!logActionStatus) {
    return;
  }
  logActionStatus.hidden = true;
  logActionStatus.textContent = '';
  logActionStatus.classList.remove('is-error');
}

/**
 * Reliable copy for local http://127.0.0.1 (Clipboard API can fail without gesture/permission).
 * @param {string} text
 */
async function copyTextReliable(text) {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
  } catch {
    /* fall through to execCommand */
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.top = '0';
  ta.style.left = '0';
  ta.style.width = '1px';
  ta.style.height = '1px';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  ta.setSelectionRange(0, ta.value.length);
  const ok = document.execCommand('copy');
  ta.remove();
  if (!ok) {
    throw new Error('execCommand("copy") recusou');
  }
}

function flashButton(btn, label, ms = 2000) {
  if (!btn) {
    return;
  }
  const prev = btn.textContent;
  btn.textContent = label;
  btn.classList.add('is-flash');
  window.clearTimeout(btn._flashTimer);
  btn._flashTimer = window.setTimeout(() => {
    btn.textContent = prev;
    btn.classList.remove('is-flash');
  }, ms);
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
  if (ev.user_email) extraBits.user_email = ev.user_email;
  if (ev.locale) extraBits.locale = ev.locale;
  if (ev.subject) extraBits.subject = ev.subject;
  if (ev.subjects) extraBits.subjects = ev.subjects;
  if (ev.count != null) extraBits.count = ev.count;
  if (ev.matched) extraBits.matched = ev.matched;
  if (ev.election_id != null) extraBits.election_id = ev.election_id;
  if (ev.round_id != null) extraBits.round_id = ev.round_id;
  if (ev.receipt_hash) extraBits.receipt_hash = ev.receipt_hash;
  if (ev.error) extraBits.error = ev.error;
  if (ev.status) extraBits.status = ev.status;
  if (ev.failure_kind) extraBits.failure_kind = ev.failure_kind;
  if (ev.failure_count != null) extraBits.failure_count = ev.failure_count;
  if (ev.exportedRows != null) extraBits.exportedRows = ev.exportedRows;
  if (ev.windows != null) extraBits.windows = ev.windows;
  if (ev.tabsPerWindow != null) extraBits.tabs = ev.tabsPerWindow;
  if (ev.workers != null) extraBits.workers = ev.workers;
  if (ev.windows_initial != null) extraBits.w0 = ev.windows_initial;
  if (ev.windows_max != null) extraBits.wMax = ev.windows_max;
  if (ev.tabs_initial != null) extraBits.t0 = ev.tabs_initial;
  if (ev.tabs_max != null) extraBits.tMax = ev.tabs_max;
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

const failureReports = document.getElementById('failureReports');
const failureReportLinks = document.getElementById('failureReportLinks');

function clearFailureReports() {
  if (failureReports) failureReports.hidden = true;
  if (failureReportLinks) failureReportLinks.replaceChildren();
}

/**
 * @param {object|null|undefined} summary
 */
function renderFailureReports(summary) {
  if (!failureReports || !failureReportLinks) return;
  const report = summary?.failureReport;
  const files = report?.files;
  if (!files || typeof files !== 'object') {
    clearFailureReports();
    return;
  }
  const order = ['password_reset', 'email_login', 'vote_login', 'combined'];
  failureReportLinks.replaceChildren();
  let any = false;
  for (const key of order) {
    const f = files[key];
    if (!f?.fileName) continue;
    any = true;
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = `/api/results/${encodeURIComponent(f.fileName)}`;
    a.download = f.fileName;
    a.textContent = f.label || f.fileName;
    const meta = document.createElement('span');
    meta.textContent = ` — ${f.count ?? 0} usuário(s) · ${f.fileName}`;
    li.append(a, meta);
    failureReportLinks.appendChild(li);
  }
  failureReports.hidden = !any;
}

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
    clearFailureReports();
  } else if (data.summary) {
    runState.textContent = 'concluído';
    runState.className = 'pill';
    startBtn.disabled = false;
    stopBtn.disabled = true;
    renderFailureReports(data.summary);
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
  persistFormPrefs();
  clearLog();
  clearFailureReports();
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
    flashButton(copyLogBtn, 'Vazio');
    return;
  }
  try {
    await copyTextReliable(text);
    showLogActionStatus('Progresso copiado.');
    flashButton(copyLogBtn, 'Copiado!');
  } catch (err) {
    showLogActionStatus(`Não foi possível copiar: ${err.message || err}`, true);
    flashButton(copyLogBtn, 'Falhou');
  }
});

saveLogBtn.addEventListener('click', () => {
  const text = progressText();
  if (!text) {
    showLogActionStatus('Nada para gravar ainda.', true);
    flashButton(saveLogBtn, 'Vazio');
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
  showLogActionStatus('Arquivo de progresso gravado.');
  flashButton(saveLogBtn, 'Gravado!');
});

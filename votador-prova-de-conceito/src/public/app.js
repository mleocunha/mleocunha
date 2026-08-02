const form = document.getElementById('runForm');
const logEl = document.getElementById('log');
const runState = document.getElementById('runState');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const loginPath = document.getElementById('loginPath');
const customLoginWrap = document.getElementById('customLoginWrap');
const macBanner = document.getElementById('macBanner');

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

function appendLog(ev) {
  const line = document.createElement('div');
  const level = ev.level || 'info';
  if (level === 'error') line.className = 'err';
  if (level === 'warn') line.className = 'warn';
  const ts = ev.ts ? ev.ts.slice(11, 19) : '';
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

const es = new EventSource('/api/events');
es.onmessage = (msg) => {
  try {
    appendLog(JSON.parse(msg.data));
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
  logEl.textContent = '';
  const fd = new FormData(form);
  startBtn.disabled = true;
  stopBtn.disabled = false;
  runState.textContent = 'iniciando';
  runState.className = 'pill running';

  const res = await fetch('/api/start', { method: 'POST', body: fd });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    appendLog({ level: 'error', message: data.error || 'Falha ao iniciar' });
    startBtn.disabled = false;
    stopBtn.disabled = true;
    runState.textContent = 'erro';
    runState.className = 'pill error';
  }
});

stopBtn.addEventListener('click', async () => {
  await fetch('/api/stop', { method: 'POST' });
  appendLog({ level: 'warn', message: 'Pedido de parada enviado…' });
});

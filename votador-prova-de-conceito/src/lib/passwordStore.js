import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_PATH = path.resolve(__dirname, '../../credentials/generated-passwords.csv');

/**
 * Persistent local map of user_login → generated password (gitignored).
 */
export function createPasswordStore(filePath = DEFAULT_PATH) {
  const storePath = filePath;
  /** @type {Map<string, { password: string, user_email: string, updated_at: string }>} */
  const map = new Map();

  function load() {
    map.clear();
    if (!fs.existsSync(storePath)) {
      return;
    }
    const text = fs.readFileSync(storePath, 'utf8').replace(/^\uFEFF/, '');
    const lines = text.split(/\r?\n/).filter(Boolean);
    if (lines.length < 2) {
      return;
    }
    const headers = lines[0].split(',').map((h) => h.trim().toLowerCase());
    const loginIdx = headers.indexOf('user_login');
    const passIdx = headers.indexOf('password');
    const emailIdx = headers.indexOf('user_email');
    const updatedIdx = headers.indexOf('updated_at');
    if (loginIdx < 0 || passIdx < 0) {
      return;
    }
    for (let i = 1; i < lines.length; i += 1) {
      const cols = splitCsvLine(lines[i]);
      const login = String(cols[loginIdx] ?? '').trim();
      const password = String(cols[passIdx] ?? '');
      if (!login || !password) {
        continue;
      }
      map.set(login, {
        password,
        user_email: emailIdx >= 0 ? String(cols[emailIdx] ?? '').trim() : '',
        updated_at: updatedIdx >= 0 ? String(cols[updatedIdx] ?? '') : '',
      });
    }
  }

  function persist() {
    fs.mkdirSync(path.dirname(storePath), { recursive: true });
    const lines = ['user_login,user_email,password,updated_at'];
    for (const [login, row] of map.entries()) {
      lines.push(
        [csvEscape(login), csvEscape(row.user_email), csvEscape(row.password), csvEscape(row.updated_at)].join(',')
      );
    }
    fs.writeFileSync(storePath, `${lines.join('\n')}\n`, 'utf8');
  }

  load();

  /** Logins for which a reset email was already requested in this process. */
  const mailResetSent = new Set();

  return {
    path: storePath,
    get(userLogin) {
      return map.get(userLogin) || null;
    },
    set(userLogin, password, userEmail = '') {
      map.set(userLogin, {
        password,
        user_email: userEmail,
        updated_at: new Date().toISOString(),
      });
      persist();
    },
    markMailResetSent(userLogin) {
      if (userLogin) {
        mailResetSent.add(String(userLogin));
      }
    },
    wasMailResetSent(userLogin) {
      return mailResetSent.has(String(userLogin || ''));
    },
    /** Copy current store into a run results directory. */
    exportTo(resultsDir) {
      fs.mkdirSync(resultsDir, { recursive: true });
      const dest = path.join(resultsDir, 'passwords.csv');
      if (fs.existsSync(storePath)) {
        fs.copyFileSync(storePath, dest);
      } else {
        fs.writeFileSync(dest, 'user_login,user_email,password,updated_at\n', 'utf8');
      }
      return dest;
    },
    size() {
      return map.size;
    },
  };
}

function csvEscape(value) {
  const s = String(value ?? '');
  if (/[",\n\r]/.test(s)) {
    return `"${s.replace(/"/g, '""')}"`;
  }
  return s;
}

function splitCsvLine(line) {
  const out = [];
  let cur = '';
  let q = false;
  for (let i = 0; i < line.length; i += 1) {
    const ch = line[i];
    if (q) {
      if (ch === '"' && line[i + 1] === '"') {
        cur += '"';
        i += 1;
      } else if (ch === '"') {
        q = false;
      } else {
        cur += ch;
      }
      continue;
    }
    if (ch === '"') {
      q = true;
      continue;
    }
    if (ch === ',') {
      out.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  out.push(cur);
  return out;
}

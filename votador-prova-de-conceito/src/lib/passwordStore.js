import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_PATH = path.resolve(__dirname, '../../credentials/generated-passwords.csv');

/**
 * Persistent local map of user_login → generated WordPress password (gitignored).
 * Email / Roundcube password is never stored here.
 *
 * Writes are synchronous and merge with any rows already on disk so parallel
 * workers in the same Node process (and reloads) do not clobber each other.
 */
export function createPasswordStore(filePath = DEFAULT_PATH) {
  const storePath = filePath;
  /** @type {Map<string, { password: string, user_email: string, updated_at: string }>} */
  const map = new Map();

  function readDiskMap() {
    /** @type {Map<string, { password: string, user_email: string, updated_at: string }>} */
    const disk = new Map();
    if (!fs.existsSync(storePath)) {
      return disk;
    }
    try {
      const text = fs.readFileSync(storePath, 'utf8').replace(/^\uFEFF/, '');
      const lines = text.split(/\r?\n/).filter(Boolean);
      if (lines.length < 2) {
        return disk;
      }
      const headers = lines[0].split(',').map((h) => h.trim().toLowerCase());
      const loginIdx = headers.indexOf('user_login');
      const passIdx = headers.indexOf('password');
      const emailIdx = headers.indexOf('user_email');
      const updatedIdx = headers.indexOf('updated_at');
      if (loginIdx < 0 || passIdx < 0) {
        return disk;
      }
      for (let i = 1; i < lines.length; i += 1) {
        const cols = splitCsvLine(lines[i]);
        const login = String(cols[loginIdx] ?? '').trim();
        const password = String(cols[passIdx] ?? '');
        if (!login || !password) {
          continue;
        }
        disk.set(login, {
          password,
          user_email: emailIdx >= 0 ? String(cols[emailIdx] ?? '').trim() : '',
          updated_at: updatedIdx >= 0 ? String(cols[updatedIdx] ?? '') : '',
        });
      }
    } catch {
      /* keep empty */
    }
    return disk;
  }

  function load() {
    map.clear();
    for (const [login, row] of readDiskMap().entries()) {
      map.set(login, row);
    }
  }

  function persist() {
    fs.mkdirSync(path.dirname(storePath), { recursive: true });
    const merged = readDiskMap();
    for (const [login, row] of map.entries()) {
      merged.set(login, row);
    }
    map.clear();
    for (const [login, row] of merged.entries()) {
      map.set(login, row);
    }
    const lines = ['user_login,user_email,password,updated_at'];
    for (const [login, row] of map.entries()) {
      lines.push(
        [csvEscape(login), csvEscape(row.user_email), csvEscape(row.password), csvEscape(row.updated_at)].join(',')
      );
    }
    fs.writeFileSync(storePath, `${lines.join('\n')}\n`, 'utf8');
  }

  load();

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
    /** Copy current store into a run results directory. */
    exportTo(resultsDir) {
      try {
        persist();
      } catch {
        /* ignore */
      }
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

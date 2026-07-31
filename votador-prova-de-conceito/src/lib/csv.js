import fs from 'node:fs';
import { parse } from 'node:path';

/**
 * Minimal RFC4180-ish CSV parser (comma delimiter, quoted fields).
 * @param {string} text
 * @returns {string[][]}
 */
export function parseCsvText(text) {
  const rows = [];
  let row = [];
  let cell = '';
  let i = 0;
  let inQuotes = false;
  const src = text.replace(/^\uFEFF/, '');

  while (i < src.length) {
    const ch = src[i];
    if (inQuotes) {
      if (ch === '"') {
        if (src[i + 1] === '"') {
          cell += '"';
          i += 2;
          continue;
        }
        inQuotes = false;
        i += 1;
        continue;
      }
      cell += ch;
      i += 1;
      continue;
    }
    if (ch === '"') {
      inQuotes = true;
      i += 1;
      continue;
    }
    if (ch === ',') {
      row.push(cell);
      cell = '';
      i += 1;
      continue;
    }
    if (ch === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
      i += 1;
      continue;
    }
    if (ch === '\r') {
      i += 1;
      continue;
    }
    cell += ch;
    i += 1;
  }
  if (cell.length || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows.filter((r) => r.some((c) => String(c).trim() !== ''));
}

/**
 * Parse electoral-roll style CSV into elector credentials.
 * Requires user_login + password (password must be last column).
 * @param {string} filePath
 * @returns {{ electors: Array<{user_login:string,password:string,user_email?:string}>, headers: string[] }}
 */
export function loadElectorsFromCsv(filePath) {
  const text = fs.readFileSync(filePath, 'utf8');
  const rows = parseCsvText(text);
  if (!rows.length) {
    throw new Error('CSV vazio.');
  }

  const headers = rows[0].map((h) => String(h).trim().toLowerCase());
  const loginIdx = headers.indexOf('user_login');
  const passIdx = headers.indexOf('password');
  const emailIdx = headers.indexOf('user_email');

  if (loginIdx < 0) {
    throw new Error('CSV precisa da coluna user_login.');
  }
  if (passIdx < 0) {
    throw new Error('CSV precisa da coluna password como última coluna.');
  }
  if (passIdx !== headers.length - 1) {
    throw new Error('A coluna password deve ser a última à direita.');
  }

  const electors = [];
  for (let r = 1; r < rows.length; r += 1) {
    const raw = [...rows[r]];
    const login = String(raw[loginIdx] ?? '').trim();
    // Spreadsheet exporters sometimes drop trailing empty columns, shifting
    // the final password left. Prefer the dedicated index; fall back to the
    // last cell when password is the rightmost header.
    let password = String(raw[passIdx] ?? '');
    if (
      !password &&
      passIdx === headers.length - 1 &&
      raw.length > loginIdx &&
      raw.length <= passIdx
    ) {
      password = String(raw[raw.length - 1] ?? '');
    }
    if (!login || !password) {
      continue;
    }
    electors.push({
      user_login: login,
      password,
      user_email: emailIdx >= 0 ? String(raw[emailIdx] ?? '').trim() : '',
      row: r + 1,
    });
  }

  if (!electors.length) {
    throw new Error('Nenhum eleitor válido encontrado no CSV.');
  }

  return { electors, headers, source: parse(filePath).base };
}

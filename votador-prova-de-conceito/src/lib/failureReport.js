import fs from 'node:fs';
import path from 'node:path';

/** @typedef {'password_reset'|'email_login'|'vote_login'|'other'} FailureKind */

export const FAILURE_KIND = {
  PASSWORD_RESET: 'password_reset',
  EMAIL_LOGIN: 'email_login',
  VOTE_LOGIN: 'vote_login',
  OTHER: 'other',
};

export const FAILURE_LABELS = {
  password_reset: 'Pedido de reset de senha',
  email_login: 'Login no e-mail (SnappyMail)',
  vote_login: 'Login para votar (WordPress)',
};

export const FAILURE_EXPORT_FILES = {
  password_reset: 'falhas-reset-senha.csv',
  email_login: 'falhas-login-email.csv',
  vote_login: 'falhas-login-voto.csv',
};

/**
 * @param {FailureKind} kind
 * @param {string} message
 * @returns {Error & { failureKind: FailureKind }}
 */
export function taggedError(kind, message) {
  const err = new Error(message);
  err.failureKind = kind;
  return err;
}

/**
 * @param {unknown} error
 * @returns {FailureKind}
 */
export function categorizeFailure(error) {
  if (error && typeof error === 'object' && error.failureKind) {
    const k = String(error.failureKind);
    if (k in FAILURE_LABELS) return /** @type {FailureKind} */ (k);
  }

  const msg = String(
    (error && typeof error === 'object' && 'message' in error && error.message) || error || ''
  );

  if (
    /Falha no login SnappyMail/i.test(msg) ||
    /ainda serve Roundcube/i.test(msg) ||
    /formulário de login e INBOX indisponíveis/i.test(msg)
  ) {
    return FAILURE_KIND.EMAIL_LOGIN;
  }

  if (
    /Plugin reportou erro ao enviar/i.test(msg) ||
    /Shortcode \[enviar_redefinicao_senha\]/i.test(msg) ||
    /E-mail de redefinição não encontrado/i.test(msg) ||
    /Link de redefinição WP/i.test(msg) ||
    /Nenhum link de redefinição/i.test(msg) ||
    /redefinição/i.test(msg)
  ) {
    return FAILURE_KIND.PASSWORD_RESET;
  }

  if (/Login falhou para/i.test(msg)) {
    return FAILURE_KIND.VOTE_LOGIN;
  }

  return FAILURE_KIND.OTHER;
}

/**
 * Tracks per-user failures by kind. Only rows with count >= minRepeats
 * (default 2 = "repetiu pelo menos uma vez") are exported as failures.
 */
export function createFailureTracker({ minRepeats = 2 } = {}) {
  /** @type {Map<string, {
   *   kind: FailureKind,
   *   user_login: string,
   *   user_email: string,
   *   count: number,
   *   first_error: string,
   *   last_error: string,
   *   first_at: string,
   *   last_at: string,
   *   attempts: number[]
   * }>} */
  const entries = new Map();

  /**
   * @param {{ kind?: FailureKind, error?: unknown, user_login?: string, user_email?: string, attempt?: number }} opts
   */
  function record(opts = {}) {
    const kind = opts.kind || categorizeFailure(opts.error);
    if (!kind || kind === FAILURE_KIND.OTHER) {
      return null;
    }
    const user_login = String(opts.user_login || '').trim();
    const user_email = String(opts.user_email || '').trim();
    if (!user_login && !user_email) {
      return null;
    }
    const key = `${kind}::${user_login || user_email}`;
    const msg = String(
      (opts.error && typeof opts.error === 'object' && 'message' in opts.error && opts.error.message) ||
        opts.error ||
        ''
    );
    const now = new Date().toISOString();
    const prev = entries.get(key);
    if (!prev) {
      const row = {
        kind,
        user_login,
        user_email,
        count: 1,
        first_error: msg,
        last_error: msg,
        first_at: now,
        last_at: now,
        attempts: opts.attempt != null ? [opts.attempt] : [],
      };
      entries.set(key, row);
      return row;
    }
    prev.count += 1;
    prev.last_error = msg || prev.last_error;
    prev.last_at = now;
    if (user_email && !prev.user_email) prev.user_email = user_email;
    if (user_login && !prev.user_login) prev.user_login = user_login;
    if (opts.attempt != null) prev.attempts.push(opts.attempt);
    return prev;
  }

  function all() {
    return [...entries.values()].sort(compareRows);
  }

  function repeated() {
    return all().filter((r) => r.count >= minRepeats);
  }

  function byKind(kind) {
    return repeated().filter((r) => r.kind === kind);
  }

  /**
   * @param {string} resultsDir
   */
  function exportTo(resultsDir) {
    fs.mkdirSync(resultsDir, { recursive: true });
    const repeatedRows = repeated();
    const files = {};

    for (const kind of Object.keys(FAILURE_EXPORT_FILES)) {
      const fileName = FAILURE_EXPORT_FILES[kind];
      const rows = repeatedRows.filter((r) => r.kind === kind);
      const dest = path.join(resultsDir, fileName);
      writeCsv(dest, rows);
      files[kind] = {
        path: dest,
        fileName,
        label: FAILURE_LABELS[kind],
        count: rows.length,
      };
    }

    const jsonName = 'falhas-repetidas.json';
    const jsonPath = path.join(resultsDir, jsonName);
    const payload = {
      minRepeats,
      generatedAt: new Date().toISOString(),
      totalAffectedUsers: new Set(repeatedRows.map((r) => r.user_login || r.user_email)).size,
      totalRows: repeatedRows.length,
      byKind: {
        password_reset: byKind(FAILURE_KIND.PASSWORD_RESET).length,
        email_login: byKind(FAILURE_KIND.EMAIL_LOGIN).length,
        vote_login: byKind(FAILURE_KIND.VOTE_LOGIN).length,
      },
      rows: repeatedRows.map(serializeRow),
    };
    fs.writeFileSync(jsonPath, JSON.stringify(payload, null, 2), 'utf8');
    files.combined = {
      path: jsonPath,
      fileName: jsonName,
      label: 'Todas as falhas repetidas (JSON)',
      count: repeatedRows.length,
    };

    return {
      minRepeats,
      totalRecorded: entries.size,
      exportedRows: repeatedRows.length,
      files,
      rows: repeatedRows.map(serializeRow),
    };
  }

  return { record, all, repeated, byKind, exportTo, size: () => entries.size };
}

function serializeRow(r) {
  return {
    kind: r.kind,
    label: FAILURE_LABELS[r.kind] || r.kind,
    user_login: r.user_login,
    user_email: r.user_email,
    count: r.count,
    first_error: r.first_error,
    last_error: r.last_error,
    first_at: r.first_at,
    last_at: r.last_at,
    attempts: r.attempts,
  };
}

function compareRows(a, b) {
  if (a.kind !== b.kind) return a.kind.localeCompare(b.kind);
  if (b.count !== a.count) return b.count - a.count;
  return String(a.user_login || a.user_email).localeCompare(String(b.user_login || b.user_email));
}

function csvEscape(value) {
  const s = String(value ?? '');
  if (/[",\n\r]/.test(s)) {
    return `"${s.replace(/"/g, '""')}"`;
  }
  return s;
}

function writeCsv(filePath, rows) {
  const header = [
    'tipo',
    'user_login',
    'user_email',
    'ocorrencias',
    'primeira_falha',
    'ultima_falha',
    'primeiro_erro',
    'ultimo_erro',
  ].join(',');
  const lines = [header];
  for (const r of rows) {
    lines.push(
      [
        csvEscape(FAILURE_LABELS[r.kind] || r.kind),
        csvEscape(r.user_login),
        csvEscape(r.user_email),
        r.count,
        csvEscape(r.first_at),
        csvEscape(r.last_at),
        csvEscape(r.first_error),
        csvEscape(r.last_error),
      ].join(',')
    );
  }
  fs.writeFileSync(filePath, `${lines.join('\n')}\n`, 'utf8');
}

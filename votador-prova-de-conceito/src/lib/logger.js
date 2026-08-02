import fs from 'node:fs';
import path from 'node:path';

/**
 * @param {string} resultsDir
 */
export function createRunLogger(resultsDir) {
  fs.mkdirSync(resultsDir, { recursive: true });
  const eventsPath = path.join(resultsDir, 'events.ndjson');
  const receiptsPath = path.join(resultsDir, 'receipts.csv');
  const summaryPath = path.join(resultsDir, 'summary.json');
  const failuresPath = path.join(resultsDir, 'failures.ndjson');

  if (!fs.existsSync(receiptsPath)) {
    fs.writeFileSync(
      receiptsPath,
      'timestamp,user_login,election_id,round_id,receipt_hash,status\n',
      'utf8'
    );
  }

  const listeners = new Set();

  function emit(event) {
    const payload = { ts: new Date().toISOString(), ...event };
    fs.appendFileSync(eventsPath, `${JSON.stringify(payload)}\n`, 'utf8');
    for (const fn of listeners) {
      try {
        fn(payload);
      } catch {
        /* ignore UI listener errors */
      }
    }
    return payload;
  }

  return {
    dir: resultsDir,
    on(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
    info(message, extra = {}) {
      return emit({ level: 'info', message, ...extra });
    },
    warn(message, extra = {}) {
      return emit({ level: 'warn', message, ...extra });
    },
    error(message, extra = {}) {
      const payload = emit({ level: 'error', message, ...extra });
      fs.appendFileSync(failuresPath, `${JSON.stringify(payload)}\n`, 'utf8');
      return payload;
    },
    receipt({ user_login, election_id, round_id, receipt_hash, status }) {
      const line = [
        new Date().toISOString(),
        csvEscape(user_login),
        election_id,
        round_id,
        csvEscape(receipt_hash || ''),
        csvEscape(status),
      ].join(',');
      fs.appendFileSync(receiptsPath, `${line}\n`, 'utf8');
      return emit({
        level: 'info',
        message: 'receipt',
        user_login,
        election_id,
        round_id,
        receipt_hash,
        status,
      });
    },
    writeSummary(summary) {
      fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2), 'utf8');
      return emit({ level: 'info', message: 'summary', summary });
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

import { tryWpLogin, readLoginError } from './wpLogin.js';

/**
 * Login as an elector and read data-rses-user-locale for the batch.
 *
 * Tries several electors and, for each, stored password then CSV password.
 * If nobody authenticates, falls back to a default locale (mail subject matching
 * already tries several languages) instead of aborting the whole run.
 *
 * @param {import('playwright').BrowserContext} context
 * @param {object} opts
 * @returns {Promise<string>}
 */
export async function discoverBatchLocale(context, opts) {
  const {
    loginUrl,
    elector,
    electors,
    passwordStore,
    platformUrl,
    logger,
    fallbackLocale = 'pt_BR',
    maxCandidates = 5,
  } = opts;

  const list = Array.isArray(electors) && electors.length
    ? electors
    : elector
      ? [elector]
      : [];
  const candidates = list.slice(0, Math.max(1, maxCandidates));
  const attempts = [];

  for (const candidate of candidates) {
    const passwords = uniquePasswords([
      passwordStore?.get?.(candidate.user_login)?.password,
      candidate.password,
    ]);
    if (!passwords.length) {
      attempts.push({
        user_login: candidate.user_login,
        detail: 'sem senha CSV nem local',
      });
      continue;
    }

    for (const { password, source } of passwords) {
      const page = await context.newPage();
      try {
        logger?.info?.('Descobrindo locale do lote…', {
          user_login: candidate.user_login,
          password_source: source,
          password_len: String(password || '').length,
        });
        const ok = await tryWpLogin(page, loginUrl, candidate.user_login, password);
        if (!ok) {
          const err = await readLoginError(page);
          const detail = err || 'ainda na página de login';
          attempts.push({
            user_login: candidate.user_login,
            password_source: source,
            detail,
          });
          logger?.warn?.('Locale: login falhou', {
            user_login: candidate.user_login,
            password_source: source,
            error: detail,
          });
          continue;
        }

        const locale = await readLocaleFromPage(page);
        logger?.info?.('Locale do lote', {
          user_login: candidate.user_login,
          locale,
          password_source: source,
        });
        await logoutQuietly(page, platformUrl);
        return locale;
      } finally {
        await page.close().catch(() => {});
      }
    }
  }

  const preview = attempts
    .slice(0, 5)
    .map((a) => `${a.user_login}${a.password_source ? `/${a.password_source}` : ''}: ${a.detail}`)
    .join(' | ');
  logger?.warn?.(
    `Não autenticou nenhum eleitor para descobrir locale; usando fallback ${fallbackLocale}`,
    {
      tried: attempts.length,
      fallbackLocale,
      preview,
    }
  );
  return String(fallbackLocale || 'pt_BR').replace('-', '_');
}

/**
 * @param {(string|undefined|null)[]} values
 * @returns {{ password: string, source: string }[]}
 */
function uniquePasswords(values) {
  const out = [];
  const seen = new Set();
  const labels = ['local', 'csv'];
  values.forEach((password, i) => {
    const p = String(password || '');
    if (!p || seen.has(p)) return;
    seen.add(p);
    out.push({ password: p, source: labels[i] || `src${i}` });
  });
  return out;
}

/**
 * @param {import('playwright').Page} page
 */
async function readLocaleFromPage(page) {
  let locale = 'en_US';
  const loc = page.locator('[data-rses-user-locale]').first();
  if (await loc.count()) {
    locale = (await loc.getAttribute('data-rses-user-locale')) || locale;
  } else {
    const htmlLang = await page.locator('html').getAttribute('lang');
    if (htmlLang) {
      locale = htmlLang.replace('-', '_');
    }
  }
  return String(locale).replace('-', '_');
}

/**
 * @param {import('playwright').Page} page
 * @param {string} [platformUrl]
 */
async function logoutQuietly(page, platformUrl) {
  const logout = page
    .locator('a[href*="action=logout"], a[href*="wp-login.php?action=logout"]')
    .first();
  if (await logout.count()) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {}),
      logout.click(),
    ]);
    return;
  }
  try {
    const u = new URL(platformUrl || page.url());
    await page
      .goto(`${u.origin}/wp-login.php?action=logout`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      })
      .catch(() => {});
  } catch {
    // ignore
  }
}

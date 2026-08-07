/**
 * Discover batch mail locale without requiring a successful elector WP login.
 * Prefer html[lang] on the login page; fall back to en_US.
 *
 * (Password-reset subjects still match the elector's WP user locale on the
 * server; Roundcube search also accepts all known catalog subjects.)
 *
 * @param {import('playwright').BrowserContext} context
 * @param {object} opts
 */
export async function discoverBatchLocale(context, opts) {
  const { loginUrl, logger } = opts;
  const page = await context.newPage();
  try {
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const htmlLang = await page.locator('html').getAttribute('lang');
    let locale = 'en_US';
    if (htmlLang) {
      locale = htmlLang.replace('-', '_');
    }

    // Normalize short forms.
    if (/^pt$/i.test(locale) || /^pt_/i.test(locale)) {
      locale = /^pt_PT$/i.test(locale) ? 'pt_PT' : 'pt_BR';
    } else if (/^en/i.test(locale)) {
      locale = 'en_US';
    }

    logger?.info?.('Locale do lote (página de login, sem autenticação WP)', {
      locale,
      loginUrl,
    });

    return locale.replace('-', '_');
  } finally {
    await page.close().catch(() => {});
  }
}

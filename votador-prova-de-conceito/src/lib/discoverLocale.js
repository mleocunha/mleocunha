/**
 * Login as the first elector and read data-rses-user-locale for the batch.
 *
 * @param {import('playwright').BrowserContext} context
 * @param {object} opts
 */
export async function discoverBatchLocale(context, opts) {
  const { loginUrl, elector, logger } = opts;
  const page = await context.newPage();
  try {
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.fill('#user_login', elector.user_login);
    await page.fill('#user_pass', elector.password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
      page.click('#wp-submit'),
    ]);

    if (/wp-login\.php/i.test(page.url())) {
      throw new Error(`Não foi possível autenticar o primeiro eleitor (${elector.user_login}) para descobrir o locale.`);
    }

    const loc = page.locator('[data-rses-user-locale]').first();
    let locale = 'en_US';
    if (await loc.count()) {
      locale = (await loc.getAttribute('data-rses-user-locale')) || locale;
    } else {
      const htmlLang = await page.locator('html').getAttribute('lang');
      if (htmlLang) {
        locale = htmlLang.replace('-', '_');
      }
    }

    logger?.info?.('Locale do lote (primeiro eleitor)', {
      user_login: elector.user_login,
      locale,
    });

    // Logout to free the session before parallel workers.
    const logout = page.locator('a[href*="action=logout"], a[href*="wp-login.php?action=logout"]').first();
    if (await logout.count()) {
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {}),
        logout.click(),
      ]);
    } else {
      const u = new URL(opts.platformUrl || page.url());
      await page.goto(`${u.origin}/wp-login.php?action=logout`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      }).catch(() => {});
    }

    return locale.replace('-', '_');
  } finally {
    await page.close().catch(() => {});
  }
}

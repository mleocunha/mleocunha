/**
 * WordPress login helpers that work for /wp-login.php, /id.php, and custom paths.
 */

/**
 * @param {string} loginUrl
 * @returns {string}
 */
export function loginPathname(loginUrl) {
  try {
    return new URL(loginUrl).pathname.replace(/\/+$/, '') || '/';
  } catch {
    const path = String(loginUrl || '').split('?')[0].replace(/\/+$/, '');
    return path || '/';
  }
}

/**
 * True when the browser is still on a login endpoint after submit.
 * @param {string} currentUrl
 * @param {string} loginUrl
 */
export function isStillOnLoginPage(currentUrl, loginUrl) {
  try {
    const current = new URL(currentUrl);
    const login = new URL(loginUrl, currentUrl);
    const curPath = current.pathname.replace(/\/+$/, '') || '/';
    const loginPath = login.pathname.replace(/\/+$/, '') || '/';
    if (curPath === loginPath) {
      return true;
    }
    // Core may bounce failed auth to wp-login.php even when entry was /id.php.
    if (/\/wp-login\.php$/i.test(curPath)) {
      return true;
    }
    return false;
  } catch {
    return /wp-login\.php/i.test(String(currentUrl || ''));
  }
}

/**
 * @param {import('playwright').Page} page
 */
export async function readLoginError(page) {
  const count = await page.locator('#login_error').count();
  if (!count) {
    return '';
  }
  return ((await page.locator('#login_error').innerText()) || '').trim();
}

/**
 * Attempt WordPress login. Returns false on #login_error or when still on the login URL.
 * @param {import('playwright').Page} page
 * @param {string} loginUrl
 * @param {string} userLogin
 * @param {string} password
 */
export async function tryWpLogin(page, loginUrl, userLogin, password) {
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#user_login', userLogin);
  await page.fill('#user_pass', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#wp-submit'),
  ]);

  if (await page.locator('#login_error').count()) {
    return false;
  }
  if (isStillOnLoginPage(page.url(), loginUrl)) {
    return false;
  }
  return true;
}

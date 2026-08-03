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
 * Attempt WordPress login. Waits for a definitive outcome (left login page OR
 * #login_error), so slow hosts under parallel workers are not treated as
 * invalid credentials mid-navigation.
 *
 * @param {import('playwright').Page} page
 * @param {string} loginUrl
 * @param {string} userLogin
 * @param {string} password
 */
export async function tryWpLogin(page, loginUrl, userLogin, password) {
  const login = String(userLogin || '').trim();
  const pass = String(password || '');

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.locator('#user_login').waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('#user_pass').waitFor({ state: 'visible', timeout: 30000 });

  await page.fill('#user_login', '');
  await page.fill('#user_pass', '');
  await page.fill('#user_login', login);
  await page.fill('#user_pass', pass);

  await page.click('#wp-submit');

  const loginPath = loginPathname(loginUrl);
  try {
    await page.waitForFunction(
      (lp) => {
        const err = document.querySelector('#login_error');
        if (err && (err.textContent || '').trim()) {
          return 'error';
        }
        const path = (location.pathname || '/').replace(/\/+$/, '') || '/';
        const want = String(lp || '/').replace(/\/+$/, '') || '/';
        if (path !== want && !/\/wp-login\.php$/i.test(path)) {
          return 'ok';
        }
        return false;
      },
      loginPath,
      { timeout: 60000 }
    );
  } catch {
    // Fall through to explicit checks below.
  }

  // One short settle for themes that paint #login_error after navigation ends.
  if (isStillOnLoginPage(page.url(), loginUrl) && !(await page.locator('#login_error').count())) {
    await page.waitForTimeout(500);
  }

  if (await page.locator('#login_error').count()) {
    return false;
  }
  if (isStillOnLoginPage(page.url(), loginUrl)) {
    return false;
  }
  return true;
}

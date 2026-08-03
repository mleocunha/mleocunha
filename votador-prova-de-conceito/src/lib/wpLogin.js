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
 * Set WP login fields without relying on focus/autofill races.
 * Headed Chrome password managers often inject the saved password into the
 * focused text field (#user_login) after Playwright fill() — which looks like
 * "senha no campo do login".
 *
 * @param {import('playwright').Page} page
 * @param {string} login
 * @param {string} pass
 */
async function fillWpCredentials(page, login, pass) {
  await page.evaluate(
    ({ userLogin, password }) => {
      const user =
        document.querySelector('#user_login') ||
        document.querySelector('input[name="log"]');
      const pwd =
        document.querySelector('#user_pass') ||
        document.querySelector('input[name="pwd"]');
      if (!user || !pwd) {
        throw new Error('Campos de login WordPress não encontrados.');
      }
      // Discourage browser password managers from rewriting the fields.
      user.setAttribute('autocomplete', 'off');
      pwd.setAttribute('autocomplete', 'off');
      user.value = '';
      pwd.value = '';
      user.dispatchEvent(new Event('input', { bubbles: true }));
      pwd.dispatchEvent(new Event('input', { bubbles: true }));
      user.value = userLogin;
      pwd.value = password;
      user.dispatchEvent(new Event('input', { bubbles: true }));
      pwd.dispatchEvent(new Event('input', { bubbles: true }));
      user.dispatchEvent(new Event('change', { bubbles: true }));
      pwd.dispatchEvent(new Event('change', { bubbles: true }));
    },
    { userLogin: login, password: pass }
  );

  // Re-assert right before submit — autofill can race after evaluate.
  const check = await page.evaluate(
    ({ userLogin, password }) => {
      const user =
        document.querySelector('#user_login') ||
        document.querySelector('input[name="log"]');
      const pwd =
        document.querySelector('#user_pass') ||
        document.querySelector('input[name="pwd"]');
      const loginVal = user ? String(user.value || '') : '';
      const passVal = pwd ? String(pwd.value || '') : '';
      if (loginVal !== userLogin || passVal !== password) {
        if (user) user.value = userLogin;
        if (pwd) pwd.value = password;
      }
      return {
        login: user ? String(user.value || '') : '',
        passLen: pwd ? String(pwd.value || '').length : 0,
        passOk: pwd ? String(pwd.value || '') === password : false,
      };
    },
    { userLogin: login, password: pass }
  );

  if (check.login !== login) {
    throw new Error(
      `Campo de usuário (#user_login) ficou com valor inesperado (len=${check.login.length}); esperado login len=${login.length}.`
    );
  }
  if (login && pass && check.login === pass) {
    throw new Error('Senha foi escrita no campo de usuário (#user_login); abortando submit.');
  }
  if (!check.passOk) {
    throw new Error(
      `Campo de senha (#user_pass) não confere antes do submit (len=${check.passLen}).`
    );
  }
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

  if (!login) {
    throw new Error('Login WordPress sem user_login.');
  }
  if (!pass) {
    throw new Error('Login WordPress sem senha.');
  }

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.locator('#user_login').waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('#user_pass').waitFor({ state: 'visible', timeout: 30000 });

  await fillWpCredentials(page, login, pass);

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

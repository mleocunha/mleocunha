import { generateSecurePassword } from './passwordGen.js';
import { ELECTOR_PASSWORD_RESET_SUBJECTS, subjectForLocale } from './mailSubjects.js';

const DEFAULT_MAIL_URL = 'https://relatasoft.com.br/mail/';

/**
 * PoC password-change flow (headed Chrome, never headless):
 * 1) On the WP login URL, open "Recuperar minha senha" / lostpassword
 * 2) Request reset for the elector (no WP session yet)
 * 3) Login Roundcube with user_email + mailPassword (CSV email password; never changed)
 * 4) Wait for the site's reset message, extract action=rp link
 * 5) Set a new WordPress password and return it
 *
 * @param {import('playwright').Page} page Elector page (not logged into WP yet).
 * @param {object} opts
 * @returns {Promise<string>} New WordPress password
 */
export async function resetPasswordViaRoundcube(page, opts) {
  const {
    loginUrl,
    mailUrl = DEFAULT_MAIL_URL,
    userLogin,
    userEmail,
    mailPassword,
    batchLocale,
    timeoutMs = 120000,
    logger,
  } = opts;

  if (!userEmail) {
    throw new Error('PoC com troca de senha exige user_email no CSV.');
  }
  if (!mailPassword) {
    throw new Error('PoC com troca de senha exige a senha de e-mail (coluna password do CSV).');
  }

  const subject = subjectForLocale(batchLocale);
  const knownSubjects = uniqueSubjects(batchLocale);

  logger?.info?.('Solicitando redefinição via Recuperar minha senha (antes do login WP)', {
    user_login: userLogin,
    user_email: userEmail,
    subject,
    locale: batchLocale,
  });

  await requestPasswordResetFromLogin(page, {
    loginUrl,
    userLogin,
    userEmail,
    logger,
  });

  logger?.info?.('Abrindo Roundcube headed (nova aba Chrome)', {
    mailUrl,
    user_email: userEmail,
  });

  const mailPage = await page.context().newPage();
  try {
    const resetLink = await findResetLinkInRoundcube(mailPage, {
      mailUrl,
      userEmail,
      mailPassword,
      subject,
      knownSubjects,
      timeoutMs,
      logger,
    });

    logger?.info?.('Link de redefinição encontrado; definindo nova senha WP…');
    const newPassword = generateSecurePassword(8);
    await setWordPressPassword(mailPage, resetLink, newPassword);
    await ensureLoggedOutOfWordPress(mailPage, loginUrl);
    return newPassword;
  } finally {
    await mailPage.close().catch(() => {});
  }
}

/**
 * Click "Recuperar minha senha" / Lost password on the login screen and submit.
 */
async function requestPasswordResetFromLogin(page, opts) {
  const { loginUrl, userLogin, userEmail, logger } = opts;

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Already on lostpassword? Skip the link click.
  const alreadyLost = /action=lostpassword/i.test(page.url());
  if (!alreadyLost) {
    const lostLink = page
      .locator(
        [
          'a[href*="action=lostpassword"]',
          '#nav a[href*="lostpassword"]',
          'a.rses-password-reset-link',
          'a:has-text("Recuperar minha senha")',
          'a:has-text("Recuperar a minha")',
          'a:has-text("Lost your password")',
          'a:has-text("Perdeu a senha")',
          'a:has-text("¿Olvidaste tu contraseña")',
        ].join(', ')
      )
      .first();

    await lostLink.waitFor({ state: 'visible', timeout: 30000 });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
      lostLink.click(),
    ]);
  }

  // If still not on the form, force the WP lostpassword URL.
  if (!(await page.locator('#user_login').count()) || !/lostpassword|action=lostpassword/i.test(page.url() + (await page.content().catch(() => '')))) {
    const u = new URL(loginUrl);
    // Prefer same path with action=lostpassword when entry is wp-login.php / id.php.
    if (/wp-login\.php$/i.test(u.pathname) || /\/id\.php$/i.test(u.pathname)) {
      u.searchParams.set('action', 'lostpassword');
      await page.goto(u.toString(), { waitUntil: 'domcontentloaded', timeout: 60000 });
    } else {
      u.pathname = u.pathname.replace(/\/+$/, '') || '';
      await page.goto(`${u.origin}/wp-login.php?action=lostpassword`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
    }
  }

  const userField = page.locator('#user_login, input[name="user_login"]').first();
  await userField.waitFor({ state: 'visible', timeout: 30000 });

  // Prefer login (WP accepts login or email); fall back to email.
  const identity = String(userLogin || userEmail || '').trim();
  await userField.fill(identity);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.locator('#wp-submit, button[type="submit"]').first().click(),
  ]);

  // Confirmation or bounce back with error.
  const err = page.locator('#login_error');
  if (await err.count()) {
    const text = ((await err.innerText()) || '').trim();
    throw new Error(`Falha ao solicitar redefinição de senha: ${text || 'erro no formulário'}`);
  }

  const confirm = page.locator('#login-message, .message, #login p.message').first();
  if (await confirm.count()) {
    logger?.info?.('Pedido de redefinição aceito pelo WordPress', {
      wp_notice: ((await confirm.innerText()) || '').trim().slice(0, 160),
    });
    return;
  }

  // Some skins only change the URL / show a generic notice — continue to Roundcube.
  logger?.info?.('Formulário de redefinição enviado; aguardando e-mail no Roundcube…');
}

async function findResetLinkInRoundcube(page, opts) {
  const { mailUrl, userEmail, mailPassword, subject, knownSubjects, timeoutMs, logger } = opts;

  await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await loginRoundcubeIfNeeded(page, { userEmail, mailPassword, logger });

  await page.locator('#messagelist, #mailboxlist, #layout-content').first().waitFor({
    state: 'visible',
    timeout: 60000,
  }).catch(() => {});

  await openInboxFolder(page);

  const deadline = Date.now() + timeoutMs;
  let lastSubjects = [];

  while (Date.now() < deadline) {
    await refreshRoundcubeInbox(page);

    const match = await findMatchingMessageRow(page, subject, knownSubjects);
    if (match) {
      await match.row.click();
      await waitForMessagePreview(page);

      const resetLink = await extractResetLink(page);
      if (resetLink) {
        // Mark read only after we have a link; password set happens on this page next.
        await markCurrentMessageRead(page);
        logger?.info?.('E-mail de redefinição aberto na INBOX', {
          subject: match.matchedSubject || subject,
          unread_preferred: true,
        });
        return resetLink;
      }
    }

    lastSubjects = await listVisibleSubjects(page);
    await page.waitForTimeout(2000);
  }

  throw new Error(
    `E-mail de redefinição não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s ` +
      `(procurava "${subject}" / assuntos conhecidos). Assuntos visíveis: ${JSON.stringify(lastSubjects.slice(0, 12))}`
  );
}

/**
 * Roundcube may already have a session from a previous insistência in the same
 * BrowserContext — then #rcmloginuser is absent and we must not wait for it.
 */
async function loginRoundcubeIfNeeded(page, { userEmail, mailPassword, logger }) {
  const inboxReady = page.locator('#messagelist, #mailboxlist, #layout-list, #layout-content');
  const loginUser = page.locator('#rcmloginuser');

  // Brief settle after goto.
  await page.waitForTimeout(500);

  if (await inboxReady.first().isVisible().catch(() => false)) {
    logger?.info?.('Roundcube já autenticado; reutilizando sessão');
    return;
  }

  // Some skins show a splash then redirect; wait for either login or inbox.
  try {
    await Promise.race([
      loginUser.waitFor({ state: 'visible', timeout: 30000 }),
      inboxReady.first().waitFor({ state: 'visible', timeout: 30000 }),
    ]);
  } catch {
    throw new Error(
      'Roundcube não mostrou login (#rcmloginuser) nem INBOX — confira a URL do webmail.'
    );
  }

  if (await inboxReady.first().isVisible().catch(() => false)) {
    logger?.info?.('Roundcube já autenticado; reutilizando sessão');
    return;
  }

  await loginUser.waitFor({ state: 'visible', timeout: 10000 });
  await page.fill('#rcmloginuser', userEmail);
  await page.fill('#rcmloginpwd', mailPassword);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#rcmloginsubmit'),
  ]);

  await page.waitForTimeout(800);
  if (await loginUser.isVisible().catch(() => false)) {
    const loginErr = page.locator('#login-form .boxerror, #login-form .error, .boxwarning').first();
    const detail = (await loginErr.count()) ? ((await loginErr.innerText()) || '').trim() : '';
    throw new Error(
      `Falha no login Roundcube (use a senha de e-mail do CSV, inalterada). ${detail}`.trim()
    );
  }
}

async function openInboxFolder(page) {
  const inbox = page
    .locator('#mailboxlist a, #folderlist-content a, .mailbox a')
    .filter({ hasText: /^(Inbox|INBOX|Caixa de entrada|Boîte|Bandeja)/i })
    .first();
  if (await inbox.count()) {
    await inbox.click().catch(() => {});
    await page.waitForTimeout(400);
  }
}

async function refreshRoundcubeInbox(page) {
  // Prefer Elastic toolbar checkmail / refresh over the generic "r" shortcut
  // (which can type into search when focus is wrong).
  const refreshed = await page.evaluate(() => {
    try {
      if (window.rcmail && typeof window.rcmail.command === 'function') {
        window.rcmail.command('checkmail');
        return 'command';
      }
    } catch {
      /* ignore */
    }
    return '';
  });

  if (refreshed) {
    await page.waitForTimeout(900);
    return;
  }

  const refreshBtn = page
    .locator(
      [
        'a.checkmail',
        'a.toolbar-button.refresh',
        'a.button.refresh',
        'button.refresh',
        'a[title*="Check" i]',
        'a[title*="Atualizar" i]',
        'a[title*="Refresh" i]',
        'a[aria-label*="Check" i]',
      ].join(', ')
    )
    .first();
  if (await refreshBtn.count()) {
    await refreshBtn.click().catch(() => {});
    await page.waitForTimeout(900);
    return;
  }

  await page.keyboard.press('r').catch(() => {});
  await page.waitForTimeout(600);
}

async function findMatchingMessageRow(page, preferredSubject, knownSubjects) {
  const rows = page.locator('#messagelist tr.message, #messagelist tbody tr, tr.message');
  const count = await rows.count();
  if (!count) {
    return null;
  }

  const preferred = String(preferredSubject || '').trim().toLowerCase();
  const known = (knownSubjects || []).map((s) => String(s).trim().toLowerCase()).filter(Boolean);

  // Prefer unread rows with the preferred subject, then any known subject, then any rp-looking subject.
  const ranked = [];
  for (let i = 0; i < count; i += 1) {
    const row = rows.nth(i);
    const text = ((await row.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    const lower = text.toLowerCase();
    const cls = (await row.getAttribute('class')) || '';
    const unread = /\bunread\b/i.test(cls);
    let score = 0;
    let matchedSubject = '';
    if (preferred && lower.includes(preferred)) {
      score = 300 + (unread ? 20 : 0);
      matchedSubject = preferredSubject;
    } else {
      for (const s of known) {
        if (s && lower.includes(s)) {
          score = 200 + (unread ? 20 : 0);
          matchedSubject = s;
          break;
        }
      }
    }
    if (!score && /redefin|password.?reset|restablec|réinitial|reset/i.test(text)) {
      score = 100 + (unread ? 20 : 0);
      matchedSubject = text.slice(0, 80);
    }
    if (score) {
      ranked.push({ row, score, matchedSubject, index: i });
    }
  }

  ranked.sort((a, b) => b.score - a.score || a.index - b.index);
  return ranked[0] || null;
}

async function listVisibleSubjects(page) {
  return page.evaluate(() => {
    const rows = Array.from(
      document.querySelectorAll('#messagelist tr.message, #messagelist tbody tr, tr.message')
    );
    return rows.slice(0, 20).map((tr) => {
      const sub = tr.querySelector('td.subject, .subject');
      return ((sub && sub.textContent) || tr.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    });
  }).catch(() => []);
}

async function waitForMessagePreview(page) {
  // Elastic uses #messagecontframe; older skins may use #messageframe.
  const frameSelectors = ['#messagecontframe', '#messageframe', 'iframe.iframe-content'];
  for (const sel of frameSelectors) {
    const frameEl = page.locator(sel).first();
    if (await frameEl.count()) {
      try {
        await page.waitForFunction(
          (selector) => {
            const el = document.querySelector(selector);
            if (!el || !el.contentDocument) {
              return false;
            }
            const body = el.contentDocument.body;
            if (!body) {
              return false;
            }
            const html = body.innerHTML || '';
            const text = body.innerText || '';
            return /action=rp|wp-login\.php/i.test(html + text) || text.trim().length > 40;
          },
          sel,
          { timeout: 8000 }
        );
        return;
      } catch {
        /* try next / fall through */
      }
    }
  }
  await page.waitForTimeout(1000);
}

async function extractResetLink(page) {
  const frames = [page, ...page.frames()];
  for (const frame of frames) {
    try {
      const href = await frame.evaluate(() => {
        const anchors = Array.from(document.querySelectorAll('a[href]'));
        for (const a of anchors) {
          const h = a.href || '';
          if (/wp-login\.php/i.test(h) && /[?&]action=rp\b/i.test(h)) {
            return h;
          }
        }
        const text = document.body ? document.body.innerText : '';
        const html = document.body ? document.body.innerHTML : '';
        const blob = `${text}\n${html}`;
        const match = blob.match(
          /https?:\/\/[^\s"'<>]+wp-login\.php\?[^\s"'<>]*action=rp[^\s"'<>]*/i
        );
        if (!match) {
          return '';
        }
        return match[0].replace(/&amp;/g, '&').replace(/[)>,.;]+$/, '');
      });
      if (href) {
        return href;
      }
    } catch {
      /* cross-origin or detached */
    }
  }
  return '';
}

async function markCurrentMessageRead(page) {
  const viaCommand = await page.evaluate(() => {
    try {
      if (window.rcmail && typeof window.rcmail.command === 'function') {
        window.rcmail.command('mark', 'read');
        return true;
      }
    } catch {
      /* ignore */
    }
    return false;
  });
  if (viaCommand) {
    return;
  }

  const btn = page
    .locator(
      'a.button.read, a.read, a[id*="markasread"], a[onclick*="mark"], button.read, a.markmessageread'
    )
    .first();
  if (await btn.count()) {
    await btn.click().catch(() => {});
    return;
  }

  const more = page.locator('a.markmessage, a#markmessagemenulink').first();
  if (await more.count()) {
    await more.click().catch(() => {});
    const read = page.locator('a:has-text("Read"), a:has-text("Lida"), a:has-text("Lido")').first();
    if (await read.count()) {
      await read.click().catch(() => {});
    }
  }
}

async function setWordPressPassword(page, resetLink, newPassword) {
  await page.goto(resetLink, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Expired / invalid key
  const loginError = page.locator('#login_error');
  if (await loginError.count()) {
    const text = ((await loginError.innerText()) || '').trim();
    if (/expired|invalid|expirad|inválid|nao é válido|não é válido/i.test(text)) {
      throw new Error(`Link de redefinição inválido ou expirado: ${text}`);
    }
  }

  // WP shows #pass1 (and often a visible #pass1-text). #pass2 is frequently
  // present but hidden — Playwright fill() refuses hidden fields.
  const pass1 = page.locator('#pass1, input[name="pass1"], #pass1-text').first();
  await pass1.waitFor({ state: 'attached', timeout: 30000 });

  await page.evaluate((pwd) => {
    const pass1El =
      document.querySelector('#pass1') ||
      document.querySelector('input[name="pass1"]');
    const pass1Text = document.querySelector('#pass1-text');
    const pass2El =
      document.querySelector('#pass2') ||
      document.querySelector('input[name="pass2"]');
    if (!pass1El && !pass1Text) {
      throw new Error('Campos de nova senha WordPress (#pass1) não encontrados.');
    }
    for (const el of [pass1El, pass1Text, pass2El]) {
      if (!el) continue;
      el.focus();
      el.value = '';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.value = pwd;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('keyup', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const weak = document.querySelector('#pw-weak');
    if (weak instanceof HTMLInputElement) {
      weak.checked = true;
      weak.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // Confirm values stuck (password managers / WP generate-password JS).
    const final1 = pass1El ? String(pass1El.value || '') : String(pass1Text?.value || '');
    if (final1 !== pwd) {
      if (pass1El) pass1El.value = pwd;
      if (pass1Text) pass1Text.value = pwd;
      if (pass2El) pass2El.value = pwd;
    }
  }, newPassword);

  // Re-assert once more right before submit (WP strength UI can rewrite fields).
  await page.evaluate((pwd) => {
    const pass1El = document.querySelector('#pass1') || document.querySelector('input[name="pass1"]');
    const pass2El = document.querySelector('#pass2') || document.querySelector('input[name="pass2"]');
    const pass1Text = document.querySelector('#pass1-text');
    if (pass1El) pass1El.value = pwd;
    if (pass1Text) pass1Text.value = pwd;
    if (pass2El) pass2El.value = pwd;
    const weak = document.querySelector('#pw-weak');
    if (weak instanceof HTMLInputElement) weak.checked = true;
  }, newPassword);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.locator('#wp-submit, button[type="submit"]').first().click(),
  ]);

  // Success: confirmation message, login form, or already redirected away from rp.
  await page.waitForTimeout(400);
  const body = await page.content();
  const url = page.url();
  const stillOnRp = /[?&]action=rp\b/i.test(url) || /[?&]action=resetpass\b/i.test(url);
  const hasLoginForm = (await page.locator('#user_login, #loginform').count()) > 0;
  const okText =
    /password.?has been reset|password.?updated|foi redefinida|redefinida com sucesso|your new password|senha foi|updated|check your email/i.test(
      body
    );

  if (stillOnRp && (await page.locator('#pass1, input[name="pass1"]').count()) && !okText) {
    const err = page.locator('#login_error');
    const detail = (await err.count()) ? ((await err.innerText()) || '').trim() : 'ainda no formulário rp';
    throw new Error(`Não foi possível confirmar a alteração de senha no WordPress: ${detail}`);
  }

  if (!okText && !hasLoginForm && stillOnRp) {
    throw new Error('Não foi possível confirmar a alteração de senha no WordPress após o submit.');
  }
}

/**
 * After rp, WP usually shows a login form (not an active session). Clear cookies anyway
 * so the subsequent login is clean.
 */
async function ensureLoggedOutOfWordPress(page, loginUrl) {
  await page.context().clearCookies();
  try {
    const u = new URL(loginUrl);
    await page.goto(`${u.origin}/wp-login.php?action=logout`, {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    }).catch(() => {});
  } catch {
    /* ignore */
  }
}

function uniqueSubjects(batchLocale) {
  const preferred = subjectForLocale(batchLocale);
  const all = [preferred, ...Object.values(ELECTOR_PASSWORD_RESET_SUBJECTS)];
  const seen = new Set();
  const out = [];
  for (const s of all) {
    const key = String(s || '').toLowerCase();
    if (!key || seen.has(key)) {
      continue;
    }
    seen.add(key);
    out.push(s);
  }
  return out;
}

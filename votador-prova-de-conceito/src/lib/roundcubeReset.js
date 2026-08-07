import { generateSecurePassword } from './passwordGen.js';
import { allResetSubjects, subjectForLocale } from './mailSubjects.js';

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

  // Only the newest reset mail AFTER this request should be opened.
  // Snapshot inbox identity after Roundcube login so we ignore older unread mail.
  const requestedAtMs = Date.now();

  await requestPasswordResetFromLogin(page, {
    loginUrl,
    userLogin,
    userEmail,
    logger,
  });

  // Give WP/mail delivery a moment before polling.
  await page.waitForTimeout(1500);

  logger?.info?.('Abrindo Roundcube headed (nova aba Chrome)', {
    mailUrl,
    user_email: userEmail,
  });

  const mailPage = await page.context().newPage();
  try {
    const resetLink = await findResetLinkInRoundcube(mailPage, {
      mailUrl,
      userEmail,
      userLogin,
      mailPassword,
      subject,
      knownSubjects,
      timeoutMs,
      requestedAtMs,
      logger,
    });

    logger?.info?.('Link de redefinição encontrado; definindo nova senha WP…', {
      login_in_link: safeLoginFromResetLink(resetLink),
    });
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
  const {
    mailUrl,
    userEmail,
    userLogin,
    mailPassword,
    subject,
    knownSubjects,
    timeoutMs,
    requestedAtMs = 0,
    logger,
  } = opts;

  await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await loginRoundcubeIfNeeded(page, { userEmail, mailPassword, logger });

  await waitForRoundcubeMessageList(page, logger);
  await openInboxFolder(page);

  // Identity of whatever is currently on top — we must wait for a NEWER unread mail.
  const baseline = await snapshotTopResetMessage(page, subject, knownSubjects);
  logger?.info?.('Baseline da INBOX antes de aguardar e-mail novo', {
    baseline_uid: baseline?.uid || null,
    baseline_unread: baseline?.unread ?? null,
  });

  const deadline = Date.now() + timeoutMs;
  let lastSubjects = [];

  while (Date.now() < deadline) {
    await refreshRoundcubeInbox(page);

    const match = await findNewestResetMessageRow(page, subject, knownSubjects);
    if (!match) {
      lastSubjects = await listVisibleSubjects(page);
      await page.waitForTimeout(2000);
      continue;
    }

    const isNewerThanBaseline =
      !baseline ||
      (match.uid && baseline.uid && String(match.uid) !== String(baseline.uid)) ||
      (match.uid && !baseline.uid) ||
      (!match.uid && match.fingerprint && match.fingerprint !== baseline.fingerprint);

    // Must be unread AND newer than what was already in the inbox when we started.
    if (!match.unread || !isNewerThanBaseline) {
      logger?.info?.('Aguardando e-mail de redefinição NOVO (não lido, mais recente que o baseline)…', {
        top_uid: match.uid || null,
        unread: match.unread,
        newer: isNewerThanBaseline,
      });
      lastSubjects = await listVisibleSubjects(page);
      await page.waitForTimeout(2000);
      continue;
    }

    logger?.info?.('Abrindo somente a mensagem de redefinição mais recente', {
      subject: match.matchedSubject || subject,
      list_index: match.index,
      uid: match.uid || null,
      unread: true,
    });
    await openMessageRow(page, match.row);
    await waitForMessagePreview(page, { userLogin });

    const resetLink = await extractResetLink(page, { userLogin });
    if (resetLink) {
      await markCurrentMessageRead(page);
      logger?.info?.('E-mail de redefinição aberto na INBOX', {
        subject: match.matchedSubject || subject,
        newest_only: true,
        uid: match.uid || null,
        requested_at_ms: requestedAtMs || null,
        login_in_link: safeLoginFromResetLink(resetLink),
      });
      return resetLink;
    }

    logger?.warn?.('Preview aberto, mas link action=rp válido para este login não encontrado; tentando de novo…');
    lastSubjects = await listVisibleSubjects(page);
    await page.waitForTimeout(2000);
  }

  throw new Error(
    `E-mail de redefinição NOVO não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s ` +
      `(procurava "${subject}"; só a mais recente não lida após o pedido). ` +
      `Assuntos visíveis: ${JSON.stringify(lastSubjects.slice(0, 12))}`
  );
}

/**
 * Roundcube Elastic often keeps #mailboxlist in the DOM but hidden (narrow layout).
 * Never wait on #mailboxlist visibility — use the message list / rows instead.
 */
async function waitForRoundcubeMessageList(page, logger) {
  const list = page.locator('#messagelist');
  const rows = page.locator('#messagelist tr.message, #messagelist tbody tr.message, tr.message');
  try {
    await Promise.race([
      list.waitFor({ state: 'visible', timeout: 60000 }),
      rows.first().waitFor({ state: 'visible', timeout: 60000 }),
    ]);
  } catch {
    throw new Error(
      'Roundcube autenticou, mas a lista de mensagens (#messagelist) não ficou visível.'
    );
  }
  logger?.info?.('Lista de mensagens Roundcube visível');
}

/**
 * Roundcube Elastic login page also has #layout-content — that is NOT a session.
 * Only skip login when #messagelist (or message rows) is visible and #rcmloginuser is not.
 * Do not use #mailboxlist for "logged in" — it is often present but hidden.
 */
async function loginRoundcubeIfNeeded(page, { userEmail, mailPassword, logger }) {
  const loginUser = page.locator('#rcmloginuser');
  const messageList = page.locator('#messagelist');
  const messageRows = page.locator('#messagelist tr.message, tr.message');

  await page.waitForTimeout(400);

  try {
    await Promise.race([
      loginUser.waitFor({ state: 'visible', timeout: 30000 }),
      messageList.waitFor({ state: 'visible', timeout: 30000 }),
      messageRows.first().waitFor({ state: 'visible', timeout: 30000 }),
    ]);
  } catch {
    throw new Error(
      'Roundcube não mostrou login (#rcmloginuser) nem INBOX (#messagelist) — confira a URL do webmail.'
    );
  }

  const onLogin = await loginUser.isVisible().catch(() => false);
  const inMailbox =
    (await messageList.isVisible().catch(() => false)) ||
    (await messageRows.first().isVisible().catch(() => false));

  if (inMailbox && !onLogin) {
    logger?.info?.('Roundcube já autenticado; reutilizando sessão');
    return;
  }

  if (!onLogin) {
    // Unexpected intermediate page — force reload of mail URL once.
    await page.goto(page.url(), { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await loginUser.waitFor({ state: 'visible', timeout: 30000 });
  }

  logger?.info?.('Login Roundcube (headed)', { user_email: userEmail });
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

  await waitForRoundcubeMessageList(page, logger);
}

async function openInboxFolder(page) {
  // Folder tree is often hidden in Elastic; only click if visible.
  const inbox = page
    .locator('#mailboxlist a, #folderlist-content a, .mailbox a')
    .filter({ hasText: /^(Inbox|INBOX|Caixa de entrada|Boîte|Bandeja)/i })
    .first();
  if ((await inbox.count()) && (await inbox.isVisible().catch(() => false))) {
    await inbox.click().catch(() => {});
    await page.waitForTimeout(400);
  }
}

/**
 * Click the message row in a Roundcube-friendly way (subject link preferred).
 * @param {import('playwright').Page} page
 * @param {import('playwright').Locator} row
 */
async function openMessageRow(page, row) {
  await row.scrollIntoViewIfNeeded().catch(() => {});
  const subjectLink = row.locator('td.subject a, .subject a, a.subject').first();
  if ((await subjectLink.count()) && (await subjectLink.isVisible().catch(() => false))) {
    await subjectLink.click({ timeout: 10000 });
  } else {
    await row.click({ timeout: 10000 });
  }
  // Elastic sometimes needs a second activation for the preview pane.
  await page.waitForTimeout(300);
  const previewHasBody = await page.evaluate(() => {
    const frame =
      document.querySelector('#messagecontframe') || document.querySelector('#messageframe');
    if (frame && frame.contentDocument && frame.contentDocument.body) {
      return (frame.contentDocument.body.innerText || '').trim().length > 20;
    }
    return false;
  }).catch(() => false);
  if (!previewHasBody) {
    await row.dblclick({ timeout: 5000 }).catch(() => {});
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

/**
 * Roundcube lists newest mail first by default. Among reset-subject matches,
 * open ONLY the topmost (most recent) row — never older reset messages.
 */
async function findNewestResetMessageRow(page, preferredSubject, knownSubjects) {
  const rows = page.locator('#messagelist tr.message, #messagelist tbody tr, tr.message');
  const count = await rows.count();
  if (!count) {
    return null;
  }

  const preferred = String(preferredSubject || '').trim().toLowerCase();
  const known = (knownSubjects || []).map((s) => String(s).trim().toLowerCase()).filter(Boolean);

  for (let i = 0; i < count; i += 1) {
    const row = rows.nth(i);
    const meta = await row.evaluate((tr) => {
      const text = (tr.innerText || '').replace(/\s+/g, ' ').trim();
      const cls = tr.className || '';
      const uid =
        tr.getAttribute('uid') ||
        tr.getAttribute('data-uid') ||
        tr.dataset?.uid ||
        (String(tr.id || '').match(/(\d+)/) || [])[1] ||
        '';
      const subjectEl = tr.querySelector('td.subject, .subject');
      const subjectText = ((subjectEl && subjectEl.textContent) || '').replace(/\s+/g, ' ').trim();
      const dateEl = tr.querySelector('td.date, .date');
      const dateText = ((dateEl && dateEl.textContent) || '').replace(/\s+/g, ' ').trim();
      return {
        text,
        cls,
        uid: String(uid || ''),
        subjectText,
        dateText,
        fingerprint: `${subjectText}|${dateText}|${text.slice(0, 80)}`,
      };
    });

    const lower = String(meta.text || '').toLowerCase();
    const unread = /\bunread\b/i.test(meta.cls);

    let matchedSubject = '';
    if (preferred && lower.includes(preferred)) {
      matchedSubject = preferredSubject;
    } else {
      for (const s of known) {
        if (s && lower.includes(s)) {
          matchedSubject = s;
          break;
        }
      }
    }
    if (!matchedSubject && /redefin|password.?reset|restablec|réinitial|elektoral|electoral|eleitor/i.test(lower)) {
      matchedSubject = meta.subjectText || meta.text.slice(0, 80);
    }

    if (matchedSubject) {
      return {
        row,
        matchedSubject,
        index: i,
        unread,
        uid: meta.uid,
        fingerprint: meta.fingerprint,
      };
    }
  }

  return null;
}

async function snapshotTopResetMessage(page, preferredSubject, knownSubjects) {
  return findNewestResetMessageRow(page, preferredSubject, knownSubjects);
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

async function waitForMessagePreview(page, { userLogin } = {}) {
  // Elastic uses #messagecontframe; older skins may use #messageframe.
  const frameSelectors = ['#messagecontframe', '#messageframe', 'iframe.iframe-content'];
  const loginNeedle = String(userLogin || '').trim();
  for (const sel of frameSelectors) {
    const frameEl = page.locator(sel).first();
    if (!(await frameEl.count())) {
      continue;
    }
    try {
      await page.waitForFunction(
        ({ selector, login }) => {
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
          const blob = `${html}\n${text}`;
          if (!/action=rp|wp-login\.php/i.test(blob)) {
            return text.trim().length > 40 ? 'body' : false;
          }
          if (login) {
            const decoded = blob.replace(/&amp;/g, '&');
            if (!decoded.includes(login) && !decoded.includes(encodeURIComponent(login))) {
              return false;
            }
          }
          return true;
        },
        { selector: sel, login: loginNeedle },
        { timeout: 12000 }
      );
      return;
    } catch {
      /* try next */
    }
  }
  await page.waitForTimeout(1000);
}

/**
 * Extract wp-login action=rp link for this elector. Prefer <a href>, unwrap
 * Roundcube redirects, rejoin line-wrapped plaintext URLs, and require login=.
 */
async function extractResetLink(page, { userLogin } = {}) {
  const wantLogin = String(userLogin || '').trim();
  const frames = [page, ...page.frames()];
  for (const frame of frames) {
    try {
      const href = await frame.evaluate((login) => {
        function unwrap(raw) {
          let h = String(raw || '').replace(/&amp;/g, '&').trim();
          // Roundcube / other redirectors: ...?_redirect=https%3A%2F%2F... or url=
          try {
            const u = new URL(h, location.href);
            for (const key of ['_redirect', 'redirect', 'url', 'u']) {
              const v = u.searchParams.get(key);
              if (v && /wp-login\.php/i.test(v)) {
                h = v;
                break;
              }
            }
          } catch {
            /* keep h */
          }
          return h.replace(/[)>,.;]+$/g, '');
        }

        function loginOf(h) {
          try {
            return decodeURIComponent(new URL(h).searchParams.get('login') || '');
          } catch {
            const m = String(h).match(/[?&]login=([^&]+)/i);
            return m ? decodeURIComponent(m[1]) : '';
          }
        }

        function acceptable(h) {
          if (!/wp-login\.php/i.test(h) || !/[?&]action=rp\b/i.test(h)) {
            return false;
          }
          if (!login) {
            return true;
          }
          return loginOf(h) === login;
        }

        const anchors = Array.from(document.querySelectorAll('a[href]'));
        for (const a of anchors) {
          const h = unwrap(a.href || a.getAttribute('href') || '');
          if (acceptable(h)) {
            return h;
          }
        }

        // Plain text: collapse soft line breaks inside URLs.
        let text = document.body ? document.body.innerText || '' : '';
        text = text.replace(/=\r?\n/g, '');
        text = text.replace(/https?:\/\/\S+(?:\r?\n\S+)*/g, (block) => block.replace(/\s+/g, ''));
        const html = document.body ? document.body.innerHTML || '' : '';
        const blob = `${text}\n${html}`.replace(/&amp;/g, '&');

        const matches = blob.match(/https?:\/\/[^\s"'<>]+wp-login\.php\?[^\s"'<>]*action=rp[^\s"'<>]*/gi) || [];
        for (const m of matches) {
          const h = unwrap(m);
          if (acceptable(h)) {
            return h;
          }
        }

        // Last resort: key + login scattered in text.
        if (login) {
          const keyMatch = blob.match(/[?&]key=([A-Za-z0-9]+)/);
          if (keyMatch) {
            const origin = location.origin;
            // Prefer site from any wp-login mention.
            const hostMatch = blob.match(/https?:\/\/[^/\s"'<>]+(?=\/wp-login\.php)/i);
            const base = hostMatch ? hostMatch[0] : '';
            if (base) {
              const built = `${base}/wp-login.php?action=rp&key=${keyMatch[1]}&login=${encodeURIComponent(login)}`;
              if (acceptable(built)) {
                return built;
              }
            }
            void origin;
          }
        }

        return '';
      }, wantLogin);
      if (href) {
        return href;
      }
    } catch {
      /* cross-origin or detached */
    }
  }
  return '';
}

function safeLoginFromResetLink(link) {
  try {
    return decodeURIComponent(new URL(link).searchParams.get('login') || '');
  } catch {
    return '';
  }
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
  const all = [preferred, ...allResetSubjects()];
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

import { generateSecurePassword } from './passwordGen.js';
import { allResetSubjects, subjectForLocale } from './mailSubjects.js';
import { readLoginError, tryWpLogin } from './wpLogin.js';

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
  const wpOrigin = originFromUrl(loginUrl);

  logger?.info?.('Preparando Roundcube antes do pedido de redefinição', {
    user_login: userLogin,
    user_email: userEmail,
    subject,
    locale: batchLocale,
    wp_origin: wpOrigin,
  });

  // Open Roundcube FIRST. Prefer a still-valid unread reset mail (avoids flooding
  // the inbox when previous PoC attempts left unread messages). Only call
  // lostpassword when no usable key remains — then wait for a UID that was not
  // in the pre-request baseline set.
  const mailPage = await page.context().newPage();
  try {
    await mailPage.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await loginRoundcubeIfNeeded(mailPage, { userEmail, mailPassword, logger });
    await waitForRoundcubeMessageList(mailPage, logger);
    await openInboxFolder(mailPage);

    let resetLink = await tryReuseUnreadResetLink(mailPage, {
      mailUrl,
      subject,
      knownSubjects,
      userLogin,
      wpOrigin,
      logger,
    });

    if (!resetLink) {
      const baseline = await snapshotResetMessageIds(mailPage, subject, knownSubjects);
      logger?.info?.('Baseline da INBOX (antes do lostpassword)', {
        baseline_uids: baseline.uids.size,
        baseline_top_uid: baseline.topUid || null,
      });

      logger?.info?.('Solicitando redefinição via Recuperar minha senha (antes do login WP)', {
        user_login: userLogin,
        user_email: userEmail,
        subject,
        locale: batchLocale,
      });
      const requestedAtMs = Date.now();
      await requestPasswordResetFromLogin(page, {
        loginUrl,
        userLogin,
        userEmail,
        logger,
      });
      await page.waitForTimeout(2000);

      resetLink = await waitForNewResetLinkInRoundcube(mailPage, {
        mailUrl,
        subject,
        knownSubjects,
        userLogin,
        wpOrigin,
        timeoutMs,
        requestedAtMs,
        baseline,
        logger,
      });
    }

    logger?.info?.('Link de redefinição encontrado; confirmando senha gerada pelo WordPress…', {
      login_in_link: safeLoginFromResetLink(resetLink),
      key_len: keyLenFromResetLink(resetLink),
      link_host: safeHostFromResetLink(resetLink),
    });
    // Use the password WordPress itself generates on the reset form (strong,
    // accepted by the strength meter). Do not force a votador-made pattern.
    const newPassword = await setWordPressPassword(mailPage, resetLink, logger);
    await ensureLoggedOutOfWordPress(mailPage, loginUrl);

    logger?.info?.('Verificando login WP com a senha recém-definida…', {
      user_login: userLogin,
      senha_len: newPassword.length,
      senha_source: 'wordpress-generated',
    });
    const verified = await tryWpLogin(mailPage, loginUrl, userLogin, newPassword);
    if (!verified) {
      const detail = await readLoginError(mailPage);
      throw new Error(
        `Senha definida no formulário rp, mas o login de verificação falhou` +
          ` para ${userLogin}: ${detail || 'credenciais rejeitadas'} ` +
          `[senha_len=${newPassword.length}]`
      );
    }
    logger?.info?.('Login de verificação OK; senha WP utilizável', {
      user_login: userLogin,
    });
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

/**
 * Poll an already-open Roundcube inbox for a reset mail whose UID was not in
 * the pre-lostpassword baseline set (not merely "different from top message").
 */
async function waitForNewResetLinkInRoundcube(page, opts) {
  const {
    mailUrl,
    subject,
    knownSubjects,
    userLogin,
    wpOrigin,
    timeoutMs,
    requestedAtMs = 0,
    baseline = null,
    logger,
  } = opts;

  const seenUids = baseline?.uids instanceof Set ? baseline.uids : new Set();
  const seenFingerprints =
    baseline?.fingerprints instanceof Set ? baseline.fingerprints : new Set();
  const deadline = Date.now() + timeoutMs;
  let lastSubjects = [];
  let poll = 0;

  while (Date.now() < deadline) {
    poll += 1;
    await refreshRoundcubeInbox(page, {
      mailUrl,
      forceReload: poll === 1 || poll % 2 === 0,
      logger,
      poll,
    });

    const match = await findNewestResetMessageRow(page, subject, knownSubjects);
    if (!match) {
      lastSubjects = await listVisibleSubjects(page);
      await page.waitForTimeout(1200);
      continue;
    }

    const uid = match.uid ? String(match.uid) : '';
    const isNewUid = uid ? !seenUids.has(uid) : false;
    const isNewFingerprint =
      !uid &&
      match.fingerprint &&
      !seenFingerprints.has(String(match.fingerprint));
    const isNew = isNewUid || isNewFingerprint || (seenUids.size === 0 && seenFingerprints.size === 0);

    if (!match.unread || !isNew) {
      logger?.info?.('Aguardando e-mail de redefinição NOVO (não lido, UID fora do baseline)…', {
        top_uid: uid || null,
        unread: match.unread,
        is_new: isNew,
        baseline_uids: seenUids.size,
        poll,
      });
      lastSubjects = await listVisibleSubjects(page);
      await page.waitForTimeout(1200);
      continue;
    }

    logger?.info?.('Abrindo somente a mensagem de redefinição mais recente', {
      subject: match.matchedSubject || subject,
      list_index: match.index,
      uid: uid || null,
      unread: true,
    });
    await openMessageRow(page, match.row);
    await waitForMessagePreview(page, { userLogin });

    const resetLink = await extractResetLink(page, { userLogin, wpOrigin });
    if (resetLink) {
      const usable = await probeResetLinkUsable(page, resetLink, logger);
      if (!usable) {
        logger?.warn?.('E-mail novo, mas a chave rp já é inválida; continuando a poll…', {
          uid: uid || null,
        });
        await refreshRoundcubeInbox(page, { mailUrl, forceReload: true, logger, poll });
        lastSubjects = await listVisibleSubjects(page);
        await page.waitForTimeout(1200);
        continue;
      }
      await markCurrentMessageRead(page);
      logger?.info?.('E-mail de redefinição aberto na INBOX', {
        subject: match.matchedSubject || subject,
        newest_only: true,
        uid: uid || null,
        requested_at_ms: requestedAtMs || null,
        login_in_link: safeLoginFromResetLink(resetLink),
        key_len: keyLenFromResetLink(resetLink),
      });
      return resetLink;
    }

    logger?.warn?.('Preview aberto, mas não foi possível montar link action=rp; tentando de novo…');
    lastSubjects = await listVisibleSubjects(page);
    await page.waitForTimeout(1200);
  }

  throw new Error(
    `E-mail de redefinição NOVO não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s ` +
      `(procurava mensagem com UID fora do baseline de ${seenUids.size} ids; assunto "${subject}"). ` +
      `Assuntos visíveis: ${JSON.stringify(lastSubjects.slice(0, 12))}`
  );
}

/**
 * If the inbox already has an unread reset mail with a still-valid key, use it
 * instead of requesting another lostpassword (stops the unread flood / refresh loop).
 */
async function tryReuseUnreadResetLink(page, opts) {
  const { mailUrl, subject, knownSubjects, userLogin, wpOrigin, logger } = opts;
  await refreshRoundcubeInbox(page, { mailUrl, forceReload: true, logger, poll: 0 });
  const match = await findNewestResetMessageRow(page, subject, knownSubjects);
  if (!match?.unread) {
    logger?.info?.('Nenhum e-mail de redefinição não lido reutilizável; será pedido lostpassword');
    return '';
  }

  logger?.info?.('Tentando reutilizar e-mail de redefinição não lido (chave ainda válida?)', {
    uid: match.uid || null,
    subject: match.matchedSubject || subject,
  });
  await openMessageRow(page, match.row);
  await waitForMessagePreview(page, { userLogin });
  const resetLink = await extractResetLink(page, { userLogin, wpOrigin });
  if (!resetLink) {
    logger?.info?.('Não foi possível extrair action=rp do não lido; pedindo lostpassword');
    await refreshRoundcubeInbox(page, { mailUrl, forceReload: true, logger, poll: 0 });
    return '';
  }

  const usable = await probeResetLinkUsable(page, resetLink, logger);
  if (!usable) {
    logger?.info?.('Chave do não lido expirada/inválida; pedindo lostpassword');
    await markCurrentMessageRead(page).catch(() => {});
    await refreshRoundcubeInbox(page, { mailUrl, forceReload: true, logger, poll: 0 });
    return '';
  }

  await markCurrentMessageRead(page).catch(() => {});
  logger?.info?.('Reutilizando e-mail de redefinição não lido com chave válida', {
    uid: match.uid || null,
    login_in_link: safeLoginFromResetLink(resetLink),
    key_len: keyLenFromResetLink(resetLink),
  });
  return resetLink;
}

/**
 * Visit action=rp and return true when the password form is usable.
 * Leaves the page on the reset form when usable so the caller can set the password.
 */
async function probeResetLinkUsable(page, resetLink, logger) {
  await page.goto(resetLink, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const loginError = page.locator('#login_error');
  if (await loginError.count()) {
    const text = ((await loginError.innerText()) || '').trim();
    if (/expired|expirou|expirad|invalid|inválid|nao é válido|não é válido|not valid/i.test(text)) {
      logger?.info?.('Probe rp: chave inválida/expirada', { detail: text.slice(0, 120) });
      return false;
    }
  }
  const pass1 = page.locator('#pass1, input[name="pass1"], #pass1-text').first();
  try {
    await pass1.waitFor({ state: 'attached', timeout: 15000 });
    return true;
  } catch {
    logger?.info?.('Probe rp: formulário #pass1 não apareceu');
    return false;
  }
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

/**
 * Refresh Roundcube so new INBOX mail appears quickly.
 * Prefer a real navigation to the inbox view (headed, visible) — checkmail
 * alone often lags for a long time on some servers.
 *
 * @param {import('playwright').Page} page
 * @param {{ mailUrl?: string, forceReload?: boolean, logger?: object, poll?: number }} [opts]
 */
async function refreshRoundcubeInbox(page, opts = {}) {
  const { mailUrl = '', forceReload = false, logger, poll = 0 } = opts;

  if (forceReload && mailUrl) {
    const inboxUrl = roundcubeInboxUrl(mailUrl);
    logger?.info?.('Atualizando Caixa de Entrada do Roundcube (reload da página)', {
      poll,
      inboxUrl,
    });
    await page.goto(inboxUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await waitForRoundcubeMessageList(page, logger).catch(() => {});
    await openInboxFolder(page);
    // Also poke checkmail after reload.
    await page.evaluate(() => {
      try {
        if (window.rcmail && typeof window.rcmail.command === 'function') {
          window.rcmail.command('checkmail');
        }
      } catch {
        /* ignore */
      }
    }).catch(() => {});
    await page.waitForTimeout(700);
    return;
  }

  // Soft refresh: toolbar / rcmail checkmail.
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
    await page.waitForTimeout(700);
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
        'a[aria-label*="Atualizar" i]',
      ].join(', ')
    )
    .first();
  if ((await refreshBtn.count()) && (await refreshBtn.isVisible().catch(() => false))) {
    await refreshBtn.click().catch(() => {});
    await page.waitForTimeout(700);
    return;
  }

  if (!refreshed) {
    // Focus list then Roundcube shortcut, or fall back to full inbox reload.
    await page.locator('#messagelist').click({ timeout: 2000 }).catch(() => {});
    await page.keyboard.press('r').catch(() => {});
    await page.waitForTimeout(500);
    if (mailUrl) {
      await refreshRoundcubeInbox(page, { mailUrl, forceReload: true, logger, poll });
    }
  }
}

function roundcubeInboxUrl(mailUrl) {
  try {
    const u = new URL(mailUrl);
    // Keep path (often /mail/ or /mail) and force the mail task + INBOX mailbox.
    u.search = '';
    u.searchParams.set('_task', 'mail');
    u.searchParams.set('_mbox', 'INBOX');
    // Cache-buster so the headed tab actually reloads the list.
    u.searchParams.set('_votador_refresh', String(Date.now()));
    return u.toString();
  } catch {
    const base = String(mailUrl || '').replace(/\/+$/, '');
    return `${base}/?_task=mail&_mbox=INBOX&_votador_refresh=${Date.now()}`;
  }
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
      const statusTitle =
        (tr.querySelector('.status, td.status, span.status')?.getAttribute('title') || '') +
        ' ' +
        (tr.getAttribute('title') || '') +
        ' ' +
        (tr.getAttribute('aria-label') || '');
      const unread =
        /\bunread\b/i.test(cls) ||
        (typeof tr.classList !== 'undefined' && tr.classList.contains('unread')) ||
        Boolean(tr.querySelector('.unread')) ||
        /unread|não\s*lid|nao\s*lid|unseen/i.test(statusTitle);
      return {
        text,
        cls,
        uid: String(uid || ''),
        subjectText,
        dateText,
        unread,
        fingerprint: `${subjectText}|${dateText}|${text.slice(0, 80)}`,
      };
    });

    const lower = String(meta.text || '').toLowerCase();

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
        unread: Boolean(meta.unread),
        uid: meta.uid,
        fingerprint: meta.fingerprint,
      };
    }
  }

  return null;
}

/**
 * Collect every visible reset-message UID/fingerprint before lostpassword so
 * the poll can accept ANY newly arrived message, not only “different from top”.
 */
async function snapshotResetMessageIds(page, preferredSubject, knownSubjects) {
  const preferred = String(preferredSubject || '').trim().toLowerCase();
  const known = (knownSubjects || []).map((s) => String(s).trim().toLowerCase()).filter(Boolean);
  const data = await page.evaluate(
    ({ preferredSubj, knownSubs }) => {
      const rows = Array.from(
        document.querySelectorAll('#messagelist tr.message, #messagelist tbody tr, tr.message')
      );
      const uids = [];
      const fingerprints = [];
      let topUid = '';
      for (const tr of rows) {
        const text = (tr.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
        const subjectEl = tr.querySelector('td.subject, .subject');
        const subjectText = ((subjectEl && subjectEl.textContent) || '').replace(/\s+/g, ' ').trim();
        const dateEl = tr.querySelector('td.date, .date');
        const dateText = ((dateEl && dateEl.textContent) || '').replace(/\s+/g, ' ').trim();
        let matched = false;
        if (preferredSubj && text.includes(preferredSubj)) matched = true;
        if (!matched) {
          for (const s of knownSubs) {
            if (s && text.includes(s)) {
              matched = true;
              break;
            }
          }
        }
        if (!matched && /redefin|password.?reset|restablec|réinitial|elektoral|electoral|eleitor/i.test(text)) {
          matched = true;
        }
        if (!matched) continue;
        const uid =
          tr.getAttribute('uid') ||
          tr.getAttribute('data-uid') ||
          tr.dataset?.uid ||
          (String(tr.id || '').match(/(\d+)/) || [])[1] ||
          '';
        const fp = `${subjectText}|${dateText}|${text.slice(0, 80)}`;
        if (uid) uids.push(String(uid));
        fingerprints.push(fp);
        if (!topUid && uid) topUid = String(uid);
      }
      return { uids, fingerprints, topUid };
    },
    { preferredSubj: preferred, knownSubs: known }
  );

  return {
    uids: new Set(data.uids || []),
    fingerprints: new Set(data.fingerprints || []),
    topUid: data.topUid || '',
  };
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
  // Do NOT require the full user_login in the body — mail clients truncate
  // dotted logins in auto-links; we rebuild the URL from key + CSV login.
  const frameSelectors = ['#messagecontframe', '#messageframe', 'iframe.iframe-content'];
  void userLogin;
  for (const sel of frameSelectors) {
    const frameEl = page.locator(sel).first();
    if (!(await frameEl.count())) {
      continue;
    }
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
          const blob = `${html}\n${text}`;
          if (/action=rp|wp-login\.php|[?&]key=/i.test(blob)) {
            return true;
          }
          return text.trim().length > 40;
        },
        sel,
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
 * Build a clean wp-login action=rp URL.
 *
 * Logins with many dots (vinicius.machado.nascimento.4981) are often truncated
 * by mail clients when auto-linking. Always prefer key from the message + the
 * known user_login from the CSV.
 */
async function extractResetLink(page, { userLogin, wpOrigin } = {}) {
  const wantLogin = String(userLogin || '').trim();
  const origin = String(wpOrigin || '').replace(/\/+$/, '');
  const frames = [page, ...page.frames()];
  for (const frame of frames) {
    try {
      const href = await frame.evaluate(
        ({ login, forcedOrigin }) => {
          function unwrap(raw) {
            let h = String(raw || '').replace(/&amp;/g, '&').trim();
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

          function findKey(blob) {
            const m =
              String(blob).match(/[?&]key=([A-Za-z0-9]+)/i) ||
              String(blob).match(/\bkey=([A-Za-z0-9]{8,})\b/i);
            return m ? m[1] : '';
          }

          function findBase(blob) {
            const hostMatch = String(blob).match(/https?:\/\/[^/\s"'<>]+(?=\/wp-login\.php)/i);
            if (hostMatch) {
              return hostMatch[0];
            }
            return String(forcedOrigin || '').replace(/\/+$/, '');
          }

          function build(base, key, login) {
            if (!base || !key || !login) {
              return '';
            }
            return `${base}/wp-login.php?action=rp&key=${encodeURIComponent(key)}&login=${encodeURIComponent(login)}`;
          }

          let text = document.body ? document.body.innerText || '' : '';
          text = text.replace(/=\r?\n/g, '');
          text = text.replace(/https?:\/\/\S+(?:\r?\n\S+)*/g, (block) => block.replace(/\s+/g, ''));
          const html = document.body ? document.body.innerHTML || '' : '';
          const blob = `${text}\n${html}`.replace(/&amp;/g, '&');

          // 1) Prefer rebuilding from key + known login (avoids truncated login= in auto-links).
          const key = findKey(blob);
          const base = findBase(blob);
          const rebuilt = build(base, key, login);
          if (rebuilt) {
            return rebuilt;
          }

          // 2) Fallback: raw anchors that already include action=rp.
          const anchors = Array.from(document.querySelectorAll('a[href]'));
          for (const a of anchors) {
            const h = unwrap(a.href || a.getAttribute('href') || '');
            if (/wp-login\.php/i.test(h) && /[?&]action=rp\b/i.test(h) && /[?&]key=/i.test(h)) {
              const k = findKey(h);
              const b = findBase(h) || base || forcedOrigin;
              const fixed = build(b, k, login);
              if (fixed) {
                return fixed;
              }
              return h;
            }
          }

          return '';
        },
        { login: wantLogin, forcedOrigin: origin }
      );
      if (href) {
        return href;
      }
    } catch {
      /* cross-origin or detached */
    }
  }
  return '';
}

function originFromUrl(url) {
  try {
    return new URL(url).origin;
  } catch {
    return '';
  }
}

function safeLoginFromResetLink(link) {
  try {
    return decodeURIComponent(new URL(link).searchParams.get('login') || '');
  } catch {
    return '';
  }
}

function keyLenFromResetLink(link) {
  try {
    return String(new URL(link).searchParams.get('key') || '').length;
  } catch {
    return 0;
  }
}

function safeHostFromResetLink(link) {
  try {
    return new URL(link).host;
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

/**
 * Open the WP reset form and save the password WordPress generates (preferred).
 * Returns the password that was submitted.
 *
 * @returns {Promise<string>}
 */
async function setWordPressPassword(page, resetLink, logger) {
  await page.goto(resetLink, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const loginError = page.locator('#login_error');
  if (await loginError.count()) {
    const text = ((await loginError.innerText()) || '').trim();
    if (/expired|expirou|expirad|invalid|inválid|nao é válido|não é válido|not valid/i.test(text)) {
      throw new Error(`Link de redefinição inválido ou expirado: ${text}`);
    }
  }

  const pass1 = page.locator('#pass1, input[name="pass1"], #pass1-text').first();
  try {
    await pass1.waitFor({ state: 'attached', timeout: 30000 });
  } catch {
    throw new Error(
      'Formulário de nova senha WordPress (#pass1) não apareceu após o link action=rp.'
    );
  }

  // Let WP user-profile.js auto-generate a strong password, or click Generate.
  await page.waitForTimeout(600);
  const hasGenerated = await page.evaluate(() => {
    const pass1El =
      document.querySelector('#pass1') || document.querySelector('input[name="pass1"]');
    const pass1Text = document.querySelector('#pass1-text');
    const val = String(pass1El?.value || pass1Text?.value || '');
    return val.length >= 8;
  });
  if (!hasGenerated) {
    const genBtn = page
      .locator(
        '.wp-generate-pw, button.wp-generate-pw, button.button.wp-generate-pw, .generate-pw'
      )
      .first();
    if ((await genBtn.count()) && (await genBtn.isVisible().catch(() => false))) {
      await genBtn.click().catch(() => {});
      await page.waitForTimeout(500);
    }
  }

  // Prefer the value WP put in the field (already accepted by the strength meter).
  let password = await page.evaluate(() => {
    const pass1El =
      document.querySelector('#pass1') || document.querySelector('input[name="pass1"]');
    const pass1Text = document.querySelector('#pass1-text');
    return String(pass1El?.value || pass1Text?.value || '').trim();
  });

  let source = 'wordpress-generated';
  if (!password || password.length < 8) {
    password = generateSecurePassword(16);
    source = 'votador-fallback';
    logger?.warn?.('WordPress não preencheu senha gerada; usando fallback do Votador', {
      senha_len: password.length,
    });
  } else {
    logger?.info?.('Usando senha gerada pelo WordPress no formulário de redefinição', {
      senha_len: password.length,
    });
  }

  const navPromise = page
    .waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 })
    .catch(() => null);

  const submitResult = await page.evaluate((pwd) => {
    const nativeSet = Object.getOwnPropertyDescriptor(
      window.HTMLInputElement.prototype,
      'value'
    )?.set;
    const setVal = (el, value) => {
      if (!el) return;
      if (nativeSet) nativeSet.call(el, value);
      else el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('keyup', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const pass1El =
      document.querySelector('#pass1') || document.querySelector('input[name="pass1"]');
    const pass1Text = document.querySelector('#pass1-text');
    const pass2El =
      document.querySelector('#pass2') || document.querySelector('input[name="pass2"]');
    if (!pass1El && !pass1Text) {
      return { ok: false, reason: 'missing-pass1' };
    }

    setVal(pass1El, pwd);
    setVal(pass1Text, pwd);
    setVal(pass2El, pwd);

    const weakRow = document.querySelector('.pw-weak');
    if (weakRow) {
      weakRow.classList.remove('hidden');
      weakRow.style.display = '';
    }
    const weak = document.querySelector('#pw-weak');
    if (weak instanceof HTMLInputElement) {
      weak.checked = true;
      weak.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const submit = document.querySelector('#wp-submit');
    if (submit) {
      submit.disabled = false;
      submit.removeAttribute('disabled');
      submit.classList.remove('disabled');
    }

    const form =
      document.querySelector('#resetpassform') ||
      (pass1El && pass1El.form) ||
      (pass1Text && pass1Text.form) ||
      document.querySelector('form');
    if (!form) {
      return { ok: false, reason: 'missing-form' };
    }

    let named = form.querySelector('input[name="pass1"]');
    if (!named) {
      named = document.createElement('input');
      named.type = 'hidden';
      named.name = 'pass1';
      form.appendChild(named);
    }
    setVal(named, pwd);
    const named2 = form.querySelector('input[name="pass2"]');
    if (named2) setVal(named2, pwd);

    if (String(named.value || '') !== pwd) {
      return { ok: false, reason: 'value-mismatch', gotLen: String(named.value || '').length };
    }

    HTMLFormElement.prototype.submit.call(form);
    return { ok: true, passLen: pwd.length };
  }, password);

  if (!submitResult?.ok) {
    throw new Error(
      `Não foi possível enviar a nova senha no WordPress (${submitResult?.reason || 'unknown'}).`
    );
  }

  await navPromise;
  await page.waitForTimeout(500);

  const bodyText = ((await page.locator('body').innerText().catch(() => '')) || '').trim();
  const url = page.url();
  const err = page.locator('#login_error');
  if (await err.count()) {
    const detail = ((await err.innerText()) || '').trim();
    throw new Error(`WordPress recusou a nova senha: ${detail || 'erro no formulário'}`);
  }

  const successBanner =
    /password has been reset|your password has been reset|senha foi redefinida|palavra-passe foi redefinida|contraseña ha sido restablecida|mot de passe a été réinitialisé/i.test(
      bodyText
    );

  const stillOnResetForm =
    (/[?&]action=rp\b/i.test(url) || /[?&]action=resetpass\b/i.test(url)) &&
    (await page.locator('#pass1, input[name="pass1"]').count()) > 0;

  if (stillOnResetForm && !successBanner) {
    throw new Error(
      'Ainda no formulário de redefinição após o submit — a senha provavelmente não foi gravada.'
    );
  }

  if (!successBanner) {
    const hasLogin = (await page.locator('#user_login, #loginform').count()) > 0;
    const hasPass1 = (await page.locator('#pass1, input[name="pass1"]').count()) > 0;
    if (!(hasLogin && !hasPass1)) {
      throw new Error(
        `Não foi possível confirmar a alteração de senha no WordPress (url=${String(url).slice(0, 140)}).`
      );
    }
    logger?.warn?.(
      'Banner de sucesso da redefinição não encontrado; seguindo com formulário de login visível',
      { senha_source: source }
    );
  } else {
    logger?.info?.('WordPress confirmou a redefinição de senha no formulário rp', {
      senha_source: source,
      senha_len: password.length,
    });
  }

  return password;
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

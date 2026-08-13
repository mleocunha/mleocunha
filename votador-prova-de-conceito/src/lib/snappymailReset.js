import { generateSecurePassword } from './passwordGen.js';
import {
  looksLikeResetSubject,
  subjectForLocale,
  subjectsToMatch,
} from './mailSubjects.js';

/** Stock default (RelataSoft lab). Prefer webmail.<domínio-do-e-mail> when different. */
const DEFAULT_MAIL_URL = 'https://webmail.relatasoft.com.br/';

/**
 * Pick SnappyMail URL: if the configured host is the stock RelataSoft default but the
 * elector mailbox is on another domain, use https://webmail.<domain>/ instead.
 *
 * @param {string} configured
 * @param {string} userEmail
 * @returns {{ url: string, derived: boolean, emailDomain: string }}
 */
export function resolveMailUrlForEmail(configured, userEmail) {
  const emailDomain = String(userEmail || '')
    .split('@')[1]
    ?.trim()
    .toLowerCase() || '';
  const fallback = String(configured || DEFAULT_MAIL_URL).trim() || DEFAULT_MAIL_URL;
  if (!emailDomain) {
    return { url: fallback, derived: false, emailDomain: '' };
  }
  const derivedUrl = `https://webmail.${emailDomain}/`;
  try {
    const host = new URL(fallback).hostname.toLowerCase();
    const stockRelata = host === 'webmail.relatasoft.com.br' || host === 'relatasoft.com.br';
    const hostMatchesMail =
      host === `webmail.${emailDomain}` || host.endsWith(`.${emailDomain}`) || host === emailDomain;
    if (stockRelata && !hostMatchesMail && !emailDomain.endsWith('relatasoft.com.br')) {
      return { url: derivedUrl, derived: true, emailDomain };
    }
  } catch {
    return { url: derivedUrl, derived: true, emailDomain };
  }
  return { url: fallback, derived: false, emailDomain };
}

/**
 * Trigger WP shortcode reset, read SnappyMail INBOX, set new WP password.
 *
 * @param {import('playwright').Page} page Already logged into WordPress as the elector.
 * @param {object} opts
 */
export async function resetPasswordViaSnappyMail(page, opts) {
  const {
    mailUrl = DEFAULT_MAIL_URL,
    userEmail,
    currentPassword,
    batchLocale,
    timeoutMs = 120000,
    logger,
    skipSend = false,
  } = opts;

  const resolved = resolveMailUrlForEmail(mailUrl, userEmail);
  const effectiveMailUrl = resolved.url;
  const subject = subjectForLocale(batchLocale);
  const subjectCandidates = subjectsToMatch(batchLocale);
  logger?.info?.(skipSend ? 'A procurar e-mail de redefinição já enviado' : 'Disparando e-mail de redefinição', {
    user_email: userEmail,
    subject,
    subjects: subjectCandidates,
    locale: batchLocale,
    mail_url: effectiveMailUrl,
    mail_url_configured: mailUrl,
    mail_url_derived: resolved.derived,
    skip_send: Boolean(skipSend),
  });
  if (resolved.derived) {
    logger?.info?.(
      `URL SnappyMail ajustada para o domínio do e-mail (${resolved.emailDomain})`,
      { mail_url: effectiveMailUrl }
    );
  }

  if (!skipSend) {
    await ensureOnWelcomeWithResetForm(page);

    // Hidden input — Playwright fill() requires visibility; set value via DOM.
    const localeField = page.locator('#rses-poc-mail-locale');
    await localeField.waitFor({ state: 'attached', timeout: 15000 });
    await localeField.evaluate((el, value) => {
      el.value = value;
    }, batchLocale || '');

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
      page.locator('[data-rses-password-reset-submit]').click(),
    ]);

    const status = page.locator('[data-rses-password-reset-status="enviada"]');
    const err = page.locator('[data-rses-password-reset-status="erro"]');
    await Promise.race([
      status.waitFor({ state: 'visible', timeout: 60000 }),
      err.waitFor({ state: 'visible', timeout: 60000 }),
    ]);
    if (await err.count()) {
      throw new Error('Plugin reportou erro ao enviar e-mail de redefinição.');
    }
  }

  const mailPage = await page.context().newPage();
  try {
    const resetLink = await findResetLinkInSnappyMail(mailPage, {
      mailUrl: effectiveMailUrl,
      userEmail,
      currentPassword,
      subject,
      subjectCandidates,
      timeoutMs: skipSend ? Math.min(timeoutMs, 45000) : timeoutMs,
      logger,
    });

    logger?.info?.('Link de redefinição encontrado; definindo nova senha…');
    const newPassword = generateSecurePassword(8);
    await setWordPressPassword(mailPage, resetLink, newPassword);
    return newPassword;
  } finally {
    await mailPage.close().catch(() => {});
  }
}

async function ensureOnWelcomeWithResetForm(page) {
  const form = page.locator('#rses-password-reset-form, [data-rses-password-reset="form"]');
  if (await form.count()) {
    return;
  }
  throw new Error(
    'Shortcode [enviar_redefinicao_senha] não encontrado na página. Insira-o na página de boas-vindas.'
  );
}

async function findResetLinkInSnappyMail(page, opts) {
  const {
    mailUrl,
    userEmail,
    currentPassword,
    subject,
    subjectCandidates = [subject],
    timeoutMs,
    logger,
  } = opts;

  await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await rejectRoundcubeSurface(page);
  // Always re-auth so we do not read a stale / wrong mailbox view.
  await snappyLogoutIfNeeded(page, mailUrl, logger);
  await loginToSnappyMail(page, userEmail, currentPassword, mailUrl, logger);
  await dismissSnappyStartupPopups(page, { userEmail, logger, waitForMs: 5000 });
  await openInboxFolder(page);

  const deadline = Date.now() + timeoutMs;
  let lastSubjectsLog = 0;
  let searchedOnce = false;
  let peekedJunk = false;

  while (Date.now() < deadline) {
    await dismissSnappyStartupPopups(page, { userEmail, logger, quiet: true });
    await reloadMessageList(page);

    const subjectsNow = await listVisibleSubjects(page);
    if (Date.now() - lastSubjectsLog > 15000) {
      lastSubjectsLog = Date.now();
      logger?.info?.('SnappyMail INBOX (assuntos visíveis)', {
        user_email: userEmail,
        count: subjectsNow.length,
        subjects: subjectsNow.slice(0, 12),
      });
    }

    const resetLink = await tryOpenResetFromList(page, {
      subjectCandidates,
      logger,
    });
    if (resetLink) {
      return resetLink;
    }

    // Use SnappyMail search once for the preferred subject.
    if (!searchedOnce && Date.now() > deadline - timeoutMs + 8000) {
      searchedOnce = true;
      await searchMailbox(page, subject);
      const viaSearch = await tryOpenResetFromList(page, { subjectCandidates, logger });
      if (viaSearch) {
        return viaSearch;
      }
      await openInboxFolder(page);
    }

    // Mid-wait: also open newest unread messages and scan body for rp link.
    if (Date.now() > deadline - timeoutMs / 2) {
      const viaScan = await scanRecentMessagesForResetLink(page, logger);
      if (viaScan) {
        return viaScan;
      }
      if (!peekedJunk) {
        peekedJunk = true;
        await openMaybeJunkFolder(page);
      }
    }

    await page.waitForTimeout(2000);
  }

  const finalSubjects = await listVisibleSubjects(page);
  throw new Error(
    `E-mail de redefinição não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s ` +
      `(procurava "${subject}"; visíveis: ${finalSubjects.slice(0, 8).join(' | ') || 'nenhum'}).`
  );
}

/**
 * @param {import('playwright').Page} page
 * @param {{ subjectCandidates: string[], logger?: object }} opts
 */
async function tryOpenResetFromList(page, opts) {
  const { subjectCandidates, logger } = opts;
  const items = page.locator('.messageListItem');
  const n = await items.count();
  for (let i = 0; i < n; i += 1) {
    const item = items.nth(i);
    const text = ((await item.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    const subjectHit =
      subjectCandidates.some((s) => s && text.includes(s)) || looksLikeResetSubject(text);
    if (!subjectHit) {
      continue;
    }
    await item.click({ force: true });
    await page.locator('.bodyText, .b-message, .messageView').first().waitFor({
      state: 'visible',
      timeout: 15000,
    }).catch(() => {});
    await page.waitForTimeout(500);
    const link = await extractResetLink(page);
    if (link) {
      await markCurrentMessageSeen(page);
      logger?.info?.('E-mail de redefinição aberto na INBOX (SnappyMail)', {
        matched: text.slice(0, 120),
      });
      return link;
    }
  }
  return '';
}

/**
 * Open a few recent messages and look for wp-login.php?action=rp regardless of subject.
 * @param {import('playwright').Page} page
 * @param {object} [logger]
 */
async function scanRecentMessagesForResetLink(page, logger) {
  const items = page.locator('.messageListItem');
  const n = Math.min(await items.count(), 8);
  for (let i = 0; i < n; i += 1) {
    const item = items.nth(i);
    await item.click({ force: true });
    await page.waitForTimeout(500);
    const link = await extractResetLink(page);
    if (link) {
      await markCurrentMessageSeen(page);
      const text = ((await item.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
      logger?.info?.('Link de reset encontrado ao varrer mensagens recentes', {
        matched: text.slice(0, 120),
      });
      return link;
    }
  }
  return '';
}

async function listVisibleSubjects(page) {
  return page.evaluate(() => {
    const nodes = Array.from(document.querySelectorAll('.messageListItem .subjectParent, .messageListItem'));
    const out = [];
    for (const n of nodes) {
      const t = (n.textContent || '').replace(/\s+/g, ' ').trim();
      if (t && !out.includes(t)) {
        out.push(t.slice(0, 160));
      }
      if (out.length >= 20) {
        break;
      }
    }
    return out;
  }).catch(() => []);
}

async function searchMailbox(page, query) {
  const search = page.locator('input.inputSearch').first();
  if (!(await search.count())) {
    return;
  }
  await search.click({ force: true }).catch(() => {});
  await search.fill('');
  await search.pressSequentially(String(query || ''), { delay: 10 }).catch(async () => {
    await search.fill(String(query || ''));
  });
  await search.press('Enter').catch(() => {});
  await page.waitForTimeout(1200);
}

/**
 * If a prior attempt left the mailbox open, log out so we re-authenticate cleanly.
 * @param {import('playwright').Page} page
 * @param {string} mailUrl
 * @param {object} [logger]
 */
async function snappyLogoutIfNeeded(page, mailUrl, logger) {
  const inboxReady = page.locator('.messageList, .messageListPlace, .b-folders').first();
  const loginBtn = page.locator('button.buttonLogin').first();
  const loggedIn =
    (await inboxReady.isVisible().catch(() => false)) &&
    !(await loginBtn.isVisible().catch(() => false));
  if (!loggedIn) {
    return;
  }

  logger?.info?.('SnappyMail: a terminar sessão anterior antes de novo login');
  const loggedOut = await page
    .evaluate(() => {
      try {
        if (typeof window.rl?.logout === 'function') {
          window.rl.logout();
          return true;
        }
      } catch {
        /* ignore */
      }
      return false;
    })
    .catch(() => false);

  if (!loggedOut) {
    const logoutLink = page
      .locator('a')
      .filter({ hasText: /^(Sair|Logout|Sign out|Terminar sessão|Encerrar)$/i })
      .first();
    if (await logoutLink.count()) {
      await logoutLink.click({ force: true }).catch(() => {});
    } else {
      const u = new URL(mailUrl);
      u.searchParams.set('logout', '1');
      await page.goto(u.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
    }
  }

  await loginBtn.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
  if (!(await loginBtn.isVisible().catch(() => false))) {
    await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
  }
}

/**
 * SnappyMail/Knockout: type into bound fields (fill() often leaves observables empty).
 *
 * @param {import('playwright').Page} page
 * @param {string} userEmail
 * @param {string} currentPassword
 * @param {string} mailUrl
 * @param {object} [logger]
 */
async function loginToSnappyMail(page, userEmail, currentPassword, mailUrl, logger) {
  // Prefer login-form scoped selectors so Identity popup Email is not mistaken for login.
  const emailInput = page.locator('form:has(button.buttonLogin) input[name="Email"]').first();
  const passInput = page.locator('form:has(button.buttonLogin) input[name="Password"]').first();
  const loginBtn = page.locator('button.buttonLogin').first();
  const inboxReady = page.locator('.messageList, .messageListPlace, .b-folders').first();

  // Retries reuse the same browser context — session cookie may already be logged in.
  const gate = await Promise.race([
    loginBtn.waitFor({ state: 'visible', timeout: 60000 }).then(() => 'login'),
    inboxReady.waitFor({ state: 'visible', timeout: 60000 }).then(() => 'inbox'),
  ]).catch(() => null);

  const loginVisible = await loginBtn.isVisible().catch(() => false);
  const inboxVisible = await inboxReady.isVisible().catch(() => false);

  if ((gate === 'inbox' || inboxVisible) && !loginVisible) {
    logger?.info?.('SnappyMail já autenticado; a reutilizar sessão', {
      mail_url: mailUrl,
      user_email: userEmail,
    });
    return;
  }

  if (!loginVisible) {
    throw new Error(
      `Falha no login SnappyMail em ${mailUrl} — formulário de login e INBOX indisponíveis`
    );
  }

  await emailInput.waitFor({ state: 'visible', timeout: 15000 });
  await typeIntoKnockoutField(emailInput, userEmail);
  await typeIntoKnockoutField(passInput, currentPassword);

  if (await loginBtn.count()) {
    await loginBtn.click();
  } else {
    await passInput.press('Enter');
  }

  const started = Date.now();
  while (Date.now() - started < 60000) {
    const inboxUp = await inboxReady.isVisible().catch(() => false);
    const loginStill = await loginBtn.isVisible().catch(() => false);
    if (inboxUp && !loginStill) {
      logger?.info?.('Login SnappyMail OK', { mail_url: mailUrl, user_email: userEmail });
      return;
    }
    const submitting = (await page.locator('form.submitting').count()) > 0;
    if (!submitting && Date.now() - started > 1200) {
      const errText = await readSnappyLoginError(page);
      // Meaningful alert, or idle login form after several seconds → fail.
      if (errText || (loginStill && Date.now() - started > 8000)) {
        break;
      }
    }
    await page.waitForTimeout(200);
  }

  const inboxUp = await inboxReady.isVisible().catch(() => false);
  const loginStill = await loginBtn.isVisible().catch(() => false);
  if (inboxUp && !loginStill) {
    logger?.info?.('Login SnappyMail OK', { mail_url: mailUrl, user_email: userEmail });
    return;
  }

  const errText = await readSnappyLoginError(page);
  const domain = String(userEmail || '').split('@')[1] || '';
  const hints = [
    `Falha no login SnappyMail em ${mailUrl}`,
    errText ? `servidor: ${errText}` : 'credenciais rejeitadas ou webmail sem domínio IMAP',
  ];
  if (/whitelist|not whitelisted|não é permitida|nao e permitida|AccountNotAllowed/i.test(errText)) {
    hints.push(
      `Whitelist SnappyMail bloqueou ${userEmail}: no Admin do SnappyMail → Domains → ${domain || 'domínio'} → White List, deixe em branco (permite todos) ou inclua este endereço; depois tente de novo`
    );
  } else if (domain) {
    hints.push(`Confirme mailbox ${userEmail} (senha = CSV) e URL https://webmail.${domain}/`);
  } else {
    hints.push('Confirme e-mail/senha do CSV e a URL do SnappyMail');
  }
  throw new Error(hints.join(' — '));
}

/**
 * First login often opens "Update Identity" (~1s after identities load).
 * Fill Name/Email/Label and Save so the modal does not block INBOX automation.
 * If Save fails (HTML5 validity), force-close via × + Ask Yes.
 *
 * @param {import('playwright').Page} page
 * @param {{ userEmail?: string, logger?: object, quiet?: boolean, waitForMs?: number }} [opts]
 */
async function dismissSnappyStartupPopups(page, opts = {}) {
  const { userEmail, logger, quiet } = opts;
  const waitForMs = Number.isFinite(opts.waitForMs) ? opts.waitForMs : quiet ? 0 : 5000;
  const displayName =
    String(userEmail || '')
      .split('@')[0]
      .replace(/[._+-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim() || 'Eleitor';
  const email = String(userEmail || '').trim();

  const deadline = Date.now() + Math.max(0, waitForMs) + 10000;
  const waitUntil = Date.now() + Math.max(0, waitForMs);
  let handledIdentity = false;
  let saveAttempts = 0;

  while (Date.now() < deadline) {
    const identityForm = page.locator('#identityform, form#identityform').first();
    const identityVisible = await identityForm.isVisible().catch(() => false);

    if (identityVisible) {
      if (!quiet && !handledIdentity) {
        logger?.info?.('SnappyMail: a fechar popup de identidade…');
      }
      handledIdentity = true;
      saveAttempts += 1;

      await identityForm
        .evaluate(
          (form, vals) => {
            const setVal = (sel, val) => {
              const el = form.querySelector(sel);
              if (!el || val == null || val === '') {
                return;
              }
              el.focus();
              el.value = val;
              el.dispatchEvent(new Event('input', { bubbles: true }));
              el.dispatchEvent(new Event('change', { bubbles: true }));
              if (typeof InputEvent === 'function') {
                el.dispatchEvent(new InputEvent('input', { bubbles: true, data: val }));
              }
            };
            // Editing main identity requires Email in the DOM (reportValidity).
            setVal('input[name="Name"]', vals.name);
            setVal('input[name="Email"]', vals.email);
            setVal('input[name="Label"]', vals.label);
          },
          { name: displayName, email, label: displayName }
        )
        .catch(() => {});

      await page.waitForTimeout(150);

      const saveBtn = page.locator('button.buttonAddIdentity').first();
      if (await saveBtn.isVisible().catch(() => false)) {
        await saveBtn.click({ force: true }).catch(() => {});
      } else {
        await identityForm
          .evaluate((form) => {
            if (typeof form.requestSubmit === 'function') {
              form.requestSubmit();
            } else {
              form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
          })
          .catch(() => {});
      }

      const closed = await identityForm
        .waitFor({ state: 'hidden', timeout: 4000 })
        .then(() => true)
        .catch(() => false);

      if (closed) {
        if (!quiet) {
          logger?.info?.('SnappyMail: popup de identidade fechado');
        }
        await page.waitForTimeout(200);
        continue;
      }

      // Save stuck (often empty required Email) — force-close so INBOX is usable.
      if (saveAttempts >= 2) {
        if (!quiet) {
          logger?.warn?.('SnappyMail: Save da identidade falhou; a forçar fecho');
        }
        await page
          .locator('.popups .modal:visible header a.close, .modal:visible header a.close')
          .first()
          .click({ force: true })
          .catch(() => {});
        await page.waitForTimeout(300);
        const askYes = page.locator('button.buttonYes').first();
        if (await askYes.isVisible().catch(() => false)) {
          await askYes.click({ force: true }).catch(() => {});
        }
        await identityForm.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(200);
        continue;
      }

      await page.waitForTimeout(400);
      continue;
    }

    // "Want to close this window?" / similar Ask popup
    const askYes = page.locator('button.buttonYes').first();
    const askVisible = await askYes.isVisible().catch(() => false);
    if (askVisible) {
      if (!quiet) {
        logger?.info?.('SnappyMail: a confirmar diálogo Ask…');
      }
      await askYes.click({ force: true }).catch(() => {});
      await page.waitForTimeout(250);
      continue;
    }

    // Identity popup is delayed ~1s after login — keep polling briefly.
    if (!handledIdentity && Date.now() < waitUntil) {
      await page.waitForTimeout(300);
      continue;
    }
    break;
  }

  // Last resort: any leftover modal close buttons
  const stillIdentity = await page.locator('#identityform').isVisible().catch(() => false);
  if (stillIdentity) {
    if (!quiet) {
      logger?.warn?.('SnappyMail: identidade ainda visível após tentativas');
    }
  }
}

/**
 * @param {import('playwright').Locator} locator
 * @param {string} value
 */
async function typeIntoKnockoutField(locator, value) {
  await locator.click();
  await locator.evaluate((el) => {
    el.focus();
    el.value = '';
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });
  // pressSequentially feeds Knockout textInput; fill() often does not.
  await locator.pressSequentially(String(value ?? ''), { delay: 12 });
  const ok = await locator.evaluate((el, expected) => el.value === expected, String(value ?? ''));
  if (!ok) {
    await locator.evaluate((el, expected) => {
      el.value = expected;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      if (typeof InputEvent === 'function') {
        el.dispatchEvent(new InputEvent('input', { bubbles: true, data: expected }));
      }
    }, String(value ?? ''));
  }
}

/**
 * @param {import('playwright').Page} page
 */
async function readSnappyLoginError(page) {
  return page.evaluate(() => {
    const alert = document.querySelector('.alert');
    if (!alert) {
      return '';
    }
    const hidden = alert.hasAttribute('hidden') || getComputedStyle(alert).display === 'none';
    if (hidden) {
      return '';
    }
    const parts = [];
    const main = alert.querySelector('span:not(.close)');
    if (main?.textContent?.trim()) {
      parts.push(main.textContent.trim());
    }
    const add = alert.querySelector('p');
    if (add?.textContent?.trim()) {
      const t = add.textContent.replace(/\s+/g, ' ').trim();
      if (!/^mensagem do servidor\s*:?\s*$/i.test(t)) {
        parts.push(t);
      }
    }
    const raw = (alert.textContent || '').replace(/\s+/g, ' ').replace(/^×\s*/, '').trim();
    if (!parts.length && raw && !/^mensagem do servidor\s*:?\s*$/i.test(raw)) {
      parts.push(raw);
    }
    return parts.join(' — ');
  });
}

/**
 * Fail fast if --mail-url still points at Roundcube after the cut-over.
 * @param {import('playwright').Page} page
 */
async function rejectRoundcubeSurface(page) {
  const title = await page.title().catch(() => '');
  const hasRc =
    /roundcube/i.test(title) ||
    (await page.locator('#rcmloginuser, #login-form #rcmloginpwd, form#login-form').count()) > 0;
  if (hasRc) {
    throw new Error(
      'A URL de webmail ainda serve Roundcube. Use SnappyMail (ex.: https://webmail.<domínio>/) via --mail-url / campo URL.'
    );
  }
}

async function openInboxFolder(page) {
  const inbox = page
    .locator(
      [
        '.b-folders-system li:has(.isInbox), .b-folders li.isInbox',
        '.b-folders a, .b-folders li, .b-folders span',
      ].join(', ')
    )
    .filter({ hasText: /^(Inbox|INBOX|Caixa de entrada|Entrada)$/i })
    .first();
  if (await inbox.count()) {
    await inbox.click({ force: true }).catch(() => {});
    await page.waitForTimeout(400);
  }
}

async function openMaybeJunkFolder(page) {
  const junk = page
    .locator('.b-folders a, .b-folders li, .b-folders span')
    .filter({ hasText: /^(Junk|Spam|Lixo eletr[oó]nico|Indesejável|Indesejado)$/i })
    .first();
  if (await junk.count()) {
    await junk.click({ force: true }).catch(() => {});
    await page.waitForTimeout(400);
  }
}

async function reloadMessageList(page) {
  const reloadBtn = page
    .locator(
      [
        'a.btn.onCheckedHide:has(.icon-spinner)',
        'a.btn[data-i18n="[title]MESSAGE_LIST/BUTTON_RELOAD"]',
        'a.btn[title*="Reload" i], a.btn[title*="Atualizar" i], a.btn[title*="Recarreg" i]',
      ].join(', ')
    )
    .first();
  if (await reloadBtn.count()) {
    await reloadBtn.click().catch(() => {});
    await page.waitForTimeout(500);
  }
}

async function extractResetLink(page) {
  const frames = [page, ...page.frames()];
  for (const frame of frames) {
    try {
      const href = await frame.evaluate(() => {
        const roots = [];
        const bodyText = document.querySelector('.bodyText');
        if (bodyText) {
          roots.push(bodyText);
        }
        roots.push(document);

        for (const root of roots) {
          const anchors = Array.from(root.querySelectorAll('a[href]'));
          for (const a of anchors) {
            const h = a.href || '';
            if (/wp-login\.php/i.test(h) && /action=rp/i.test(h)) {
              return h;
            }
          }
          const text = root.innerText || root.textContent || '';
          const match = text.match(
            /https?:\/\/[^\s"'<>]+wp-login\.php\?[^\s"'<>]*action=rp[^\s"'<>]*/i
          );
          if (match) {
            return match[0];
          }
        }
        return '';
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

async function markCurrentMessageSeen(page) {
  // Opening a message usually marks it seen; reinforce via list "mark as read" menu.
  const more = page.locator('#more-list-dropdown-id, a.dropdown-toggle.fontastic').first();
  if (await more.count()) {
    await more.click().catch(() => {});
    const setSeen = page
      .locator(
        'menu.dropdown-menu a[data-i18n="MESSAGE_LIST/MENU_SET_SEEN"], menu.dropdown-menu a'
      )
      .filter({ hasText: /lida|read|visto|seen/i })
      .first();
    if (await setSeen.count()) {
      await setSeen.click().catch(() => {});
    }
  }
}

async function setWordPressPassword(page, resetLink, newPassword) {
  await page.goto(resetLink, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Expired / invalid key — WP shows an error, not the password form.
  const pass1 = page.locator('#pass1, input[name="pass1"]').first();
  const appeared = await Promise.race([
    pass1.waitFor({ state: 'visible', timeout: 30000 }).then(() => 'form'),
    page
      .waitForFunction(() => {
        const err = document.querySelector('#login_error');
        if (err && (err.textContent || '').trim()) {
          return true;
        }
        const body = (document.body?.innerText || '').toLowerCase();
        return /expired|inválid|invalid|not allowed|não é válid/i.test(body);
      }, { timeout: 30000 })
      .then(() => 'error')
      .catch(() => null),
  ]).catch(() => null);

  if (appeared !== 'form' || !(await pass1.isVisible().catch(() => false))) {
    const detail =
      ((await page.locator('#login_error').innerText().catch(() => '')) || '').trim() ||
      'link inválido/expirado ou formulário de nova senha ausente';
    throw new Error(`Não foi possível abrir o formulário de nova senha WP: ${detail}`);
  }

  // WP's password-generator JS often overwrites fill() — set via evaluate and
  // disable generation hooks when present.
  await page.evaluate((pwd) => {
    const p1 = document.querySelector('#pass1, input[name="pass1"]');
    const p2 = document.querySelector('#pass2, input[name="pass2"]');
    const gen = document.querySelector('.wp-generate-pw, button.wp-generate-pw');
    if (gen && typeof gen.click === 'function' && p1 && p1.type === 'hidden') {
      // Reveal password fields if WP hid them behind "Generate password".
      gen.click();
    }
    const apply = (el) => {
      if (!el) {
        return;
      }
      el.removeAttribute('readonly');
      el.value = pwd;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    apply(document.querySelector('#pass1, input[name="pass1"]'));
    apply(document.querySelector('#pass2, input[name="pass2"]'));
    const weak = document.querySelector('#pw-weak');
    if (weak && !weak.checked) {
      weak.checked = true;
      weak.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, newPassword);

  // Re-assert values (generator can race).
  await page.waitForTimeout(200);
  await page.evaluate((pwd) => {
    for (const sel of ['#pass1', 'input[name="pass1"]', '#pass2', 'input[name="pass2"]']) {
      const el = document.querySelector(sel);
      if (el) {
        el.value = pwd;
      }
    }
    const weak = document.querySelector('#pw-weak');
    if (weak) {
      weak.checked = true;
    }
  }, newPassword);

  const beforeUrl = page.url();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.locator('#wp-submit, button[type="submit"]').first().click(),
  ]);

  // Confirm we left the reset form (success → login or "password reset" message).
  const stillForm = await page.locator('#pass1, input[name="pass1"]').isVisible().catch(() => false);
  const errText = ((await page.locator('#login_error').innerText().catch(() => '')) || '').trim();
  if (stillForm || errText) {
    throw new Error(
      `WordPress não confirmou a nova senha (${errText || 'formulário ainda visível'}; url=${page.url()}; before=${beforeUrl}).`
    );
  }
}

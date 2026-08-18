import { generateSecurePassword } from './passwordGen.js';
import {
  looksLikeResetSubject,
  subjectForLocale,
  subjectsToMatch,
} from './mailSubjects.js';
import { FAILURE_KIND, taggedError } from './failureReport.js';

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
 * Trigger WP lostpassword reset, read SnappyMail INBOX for a *fresh* mail,
 * set new WP password. Password-change PoC always sends a new request.
 *
 * @param {import('playwright').Page} page Any page in the elector context.
 * @param {object} opts
 */
export async function resetPasswordViaSnappyMail(page, opts) {
  const {
    mailUrl = DEFAULT_MAIL_URL,
    userEmail,
    userLogin,
    currentPassword,
    batchLocale,
    timeoutMs = 120000,
    logger,
    skipSend = false,
    /** @type {'shortcode'|'lostpassword'} */
    sendVia = 'lostpassword',
    loginUrl,
    requireFreshMail = true,
  } = opts;

  const resolved = resolveMailUrlForEmail(mailUrl, userEmail);
  const effectiveMailUrl = resolved.url;
  const subject = subjectForLocale(batchLocale);
  const subjectCandidates = subjectsToMatch(batchLocale);
  logger?.info?.(skipSend ? 'A procurar e-mail de redefinição já enviado' : 'Disparando e-mail de redefinição', {
    user_email: userEmail,
    user_login: userLogin || undefined,
    subject,
    subjects: subjectCandidates,
    locale: batchLocale,
    mail_url: effectiveMailUrl,
    mail_url_configured: mailUrl,
    mail_url_derived: resolved.derived,
    skip_send: Boolean(skipSend),
    send_via: skipSend ? 'skip' : sendVia,
    require_fresh: Boolean(requireFreshMail && !skipSend),
  });
  if (resolved.derived) {
    logger?.info?.(
      `URL SnappyMail ajustada para o domínio do e-mail (${resolved.emailDomain})`,
      { mail_url: effectiveMailUrl }
    );
  }

  /** @type {string[]} */
  let baselineSubjects = [];
  const mailPage = await page.context().newPage();
  try {
    // Login to mailbox first so we can snapshot INBOX before requesting reset.
    await mailPage.goto(effectiveMailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await rejectRoundcubeSurface(mailPage);
    await snappyLogoutIfNeeded(mailPage, effectiveMailUrl, logger);
    await loginToSnappyMail(mailPage, userEmail, currentPassword, effectiveMailUrl, logger);
    await dismissSnappyStartupPopups(mailPage, { userEmail, logger, waitForMs: 5000 });
    await openInboxFolder(mailPage);
    baselineSubjects = await listVisibleSubjects(mailPage);
    logger?.info?.('INBOX baseline antes do pedido de reset', {
      user_email: userEmail,
      count: baselineSubjects.length,
      top: baselineSubjects.slice(0, 3),
    });

    if (!skipSend) {
      if (sendVia === 'shortcode') {
        await ensureOnWelcomeWithResetForm(page);
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
          throw taggedError(
            FAILURE_KIND.PASSWORD_RESET,
            'Plugin reportou erro ao enviar e-mail de redefinição.'
          );
        }
      } else {
        await requestWpLostPassword(page, {
          loginUrl,
          userLogin,
          userEmail,
          logger,
        });
      }
    }

    const resetLinks = await findResetLinksInSnappyMail(mailPage, {
      mailUrl: effectiveMailUrl,
      userEmail,
      currentPassword,
      subject,
      subjectCandidates,
      timeoutMs: skipSend ? Math.min(timeoutMs, 45000) : timeoutMs,
      logger,
      alreadyLoggedIn: true,
      baselineSubjects: requireFreshMail && !skipSend ? baselineSubjects : null,
      maxLinks: 1,
    });

    logger?.info?.('Link(s) de redefinição encontrados; definindo nova senha…', {
      user_email: userEmail,
      links: resetLinks.length,
    });
    const newPassword = generateSecurePassword(8);
    let lastErr = null;
    for (let i = 0; i < resetLinks.length; i += 1) {
      try {
        await setWordPressPassword(mailPage, resetLinks[i], newPassword, logger);
        return newPassword;
      } catch (err) {
        lastErr = err;
        logger?.warn?.('Falha ao aplicar link de redefinição; a tentar o seguinte', {
          user_email: userEmail,
          index: i + 1,
          of: resetLinks.length,
          error: String(err.message || err),
        });
      }
    }
    throw lastErr || taggedError(FAILURE_KIND.PASSWORD_RESET, 'Nenhum link de redefinição utilizável.');
  } finally {
    await mailPage.close().catch(() => {});
  }
}

/**
 * Request a WP password-reset email without an authenticated elector session
 * (wp-login.php?action=lostpassword). RSES still customizes the mail subject.
 *
 * @param {import('playwright').Page} page
 * @param {{ loginUrl?: string, userLogin?: string, userEmail?: string, logger?: object }} opts
 */
export async function requestWpLostPassword(page, opts = {}) {
  const userLogin = String(opts.userLogin || '').trim();
  const userEmail = String(opts.userEmail || '').trim();
  const identity = userLogin || userEmail;
  if (!identity) {
    throw taggedError(
      FAILURE_KIND.PASSWORD_RESET,
      'Pedido lostpassword exige user_login ou user_email.'
    );
  }

  const lostUrl = lostPasswordUrl(opts.loginUrl, page.url());
  opts.logger?.info?.('Pedindo redefinição via WP lostpassword (sem sessão do eleitor)', {
    user_login: userLogin || undefined,
    user_email: userEmail || undefined,
    lost_url: lostUrl,
  });

  await page.goto(lostUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const userField = page.locator('#user_login, input[name="user_login"]').first();
  await userField.waitFor({ state: 'visible', timeout: 30000 });
  await userField.fill('');
  await userField.fill(identity);

  const submit = page.locator('#wp-submit, input[type="submit"], button[type="submit"]').first();
  // WP often stays on the same document or soft-navigates — do not hang on navigation.
  await submit.click();
  await page.waitForTimeout(1200);
  try {
    await page.waitForLoadState('domcontentloaded', { timeout: 15000 });
  } catch {
    // ignore
  }

  const err = ((await page.locator('#login_error').innerText().catch(() => '')) || '').trim();
  if (err) {
    throw taggedError(
      FAILURE_KIND.PASSWORD_RESET,
      `WP lostpassword recusou o pedido para ${identity}: ${err}`
    );
  }

  const body = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
  const url = page.url();
  const looksSent =
    /checkemail=confirm/i.test(url) ||
    /password reset|redefini|enviad|sent|e-mail|email|verifique|check your/i.test(body);
  if (!looksSent) {
    opts.logger?.warn?.('lostpassword sem confirmação explícita; seguirei para o SnappyMail', {
      url,
    });
  } else {
    opts.logger?.info?.('Pedido lostpassword aceito pelo WordPress', { url });
  }
}

/**
 * @param {string} [loginUrl]
 * @param {string} [fallbackUrl]
 */
function lostPasswordUrl(loginUrl, fallbackUrl) {
  const base = String(loginUrl || fallbackUrl || '').trim();
  try {
    const u = new URL(base);
    // Keep custom login path host; lostpassword is always core wp-login.php.
    return `${u.origin}/wp-login.php?action=lostpassword`;
  } catch {
    return '/wp-login.php?action=lostpassword';
  }
}

async function ensureOnWelcomeWithResetForm(page) {
  const form = page.locator('#rses-password-reset-form, [data-rses-password-reset="form"]');
  if (await form.count()) {
    return;
  }
  throw taggedError(
    FAILURE_KIND.PASSWORD_RESET,
    'Shortcode [enviar_redefinicao_senha] não encontrado na página. Insira-o na página de boas-vindas.'
  );
}

async function findResetLinksInSnappyMail(page, opts) {
  const {
    mailUrl,
    userEmail,
    currentPassword,
    subject,
    subjectCandidates = [subject],
    timeoutMs,
    logger,
    alreadyLoggedIn = false,
    baselineSubjects = null,
    maxLinks = 1,
  } = opts;

  if (!alreadyLoggedIn) {
    await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await rejectRoundcubeSurface(page);
    await snappyLogoutIfNeeded(page, mailUrl, logger);
    await loginToSnappyMail(page, userEmail, currentPassword, mailUrl, logger);
    await dismissSnappyStartupPopups(page, { userEmail, logger, waitForMs: 5000 });
    await openInboxFolder(page);
  }

  const baseline = Array.isArray(baselineSubjects) ? baselineSubjects : null;
  const baselineSet = new Set(baseline || []);
  const requireFresh = Boolean(baseline);
  const deadline = Date.now() + timeoutMs;
  let lastSubjectsLog = 0;
  let searchedOnce = false;
  let peekedJunk = false;
  let waitedPastBaseline = false;

  while (Date.now() < deadline) {
    await dismissSnappyStartupPopups(page, { userEmail, logger, quiet: true });
    await reloadMessageList(page);

    const subjectsNow = await listVisibleSubjects(page);
    if (Date.now() - lastSubjectsLog > 10000) {
      lastSubjectsLog = Date.now();
      logger?.info?.('SnappyMail INBOX (assuntos visíveis)', {
        user_email: userEmail,
        count: subjectsNow.length,
        subjects: subjectsNow.slice(0, 12),
        waiting_fresh: requireFresh && !waitedPastBaseline,
      });
    }

    if (requireFresh) {
      const freshTop = subjectsNow.find(
        (s) => s && !baselineSet.has(s) && looksLikeResetSubject(s)
      );
      if (!freshTop) {
        await page.waitForTimeout(2000);
        continue;
      }
      if (!waitedPastBaseline) {
        waitedPastBaseline = true;
        logger?.info?.('Novo e-mail detectado após o pedido de reset', {
          user_email: userEmail,
          top: freshTop.slice(0, 120),
        });
      }
    }

    const links = await collectResetLinksFromList(page, {
      subjectCandidates,
      logger,
      maxLinks,
      preferUnseenFirst: requireFresh,
    });
    if (links.length) {
      return links;
    }

    if (!searchedOnce && Date.now() > deadline - timeoutMs + 10000) {
      searchedOnce = true;
      await searchMailbox(page, subject);
      const viaSearch = await collectResetLinksFromList(page, {
        subjectCandidates,
        logger,
        maxLinks,
        preferUnseenFirst: requireFresh,
      });
      if (viaSearch.length) {
        return viaSearch;
      }
      await openInboxFolder(page);
    }

    if (Date.now() > deadline - timeoutMs / 2) {
      const viaScan = await collectResetLinksFromList(page, {
        subjectCandidates: [],
        logger,
        maxLinks,
        anyRecent: true,
        preferUnseenFirst: true,
      });
      if (viaScan.length) {
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
  throw taggedError(
    FAILURE_KIND.PASSWORD_RESET,
    `E-mail de redefinição não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s ` +
      `(procurava "${subject}"; visíveis: ${finalSubjects.slice(0, 8).join(' | ') || 'nenhum'}).`
  );
}

/**
 * Collect reset links from newest matching messages first.
 * @param {import('playwright').Page} page
 * @param {{ subjectCandidates: string[], logger?: object, maxLinks?: number, anyRecent?: boolean }} opts
 * @returns {Promise<string[]>}
 */
async function collectResetLinksFromList(page, opts) {
  const {
    subjectCandidates = [],
    logger,
    maxLinks = 1,
    anyRecent = false,
    preferUnseenFirst = false,
  } = opts;
  const items = page.locator('.messageListItem');
  const n = await items.count();
  const limit = anyRecent ? Math.min(n, 8) : Math.min(n, preferUnseenFirst ? 6 : n);
  /** @type {string[]} */
  const links = [];
  const seen = new Set();

  /** @type {number[]} */
  const order = [];
  for (let i = 0; i < limit; i += 1) order.push(i);
  if (preferUnseenFirst) {
    const ranked = [];
    for (const i of order) {
      const item = items.nth(i);
      const cls = (await item.getAttribute('class').catch(() => '')) || '';
      const text = ((await item.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
      const unseen = /unseen|isNew|newMessage|flag-unseen/i.test(cls) || /☐|unread/i.test(text);
      ranked.push({ i, unseen });
    }
    ranked.sort((a, b) => Number(b.unseen) - Number(a.unseen) || a.i - b.i);
    order.length = 0;
    for (const r of ranked) order.push(r.i);
  }

  for (const i of order) {
    if (links.length >= maxLinks) {
      break;
    }
    const item = items.nth(i);
    const text = ((await item.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    if (!anyRecent) {
      const subjectHit =
        subjectCandidates.some((s) => s && text.includes(s)) || looksLikeResetSubject(text);
      if (!subjectHit) {
        continue;
      }
    }
    await item.click({ force: true });
    await page.locator('.bodyText, .b-message, .messageView').first().waitFor({
      state: 'visible',
      timeout: 15000,
    }).catch(() => {});
    await page.waitForTimeout(900);
    const link = await extractResetLink(page);
    if (link && !seen.has(link)) {
      seen.add(link);
      links.push(link);
      await markCurrentMessageSeen(page);
      logger?.info?.('E-mail de redefinição aberto na INBOX (SnappyMail)', {
        matched: text.slice(0, 120),
        link_index: links.length,
      });
    }
  }
  return links;
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
    throw taggedError(
      FAILURE_KIND.EMAIL_LOGIN,
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
  throw taggedError(FAILURE_KIND.EMAIL_LOGIN, hints.join(' — '));
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
    throw taggedError(
      FAILURE_KIND.EMAIL_LOGIN,
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

/**
 * Drop WordPress auth cookies so action=rp shows the password form instead of
 * redirecting away because the elector is still logged in on this context.
 * @param {import('playwright').Page} page
 */
async function clearWordPressAuthCookies(page) {
  const cookies = await page.context().cookies();
  const keep = cookies.filter((c) => {
    const n = String(c.name || '');
    return !/^wordpress(_logged_in|_sec)?(_|$)/i.test(n) && !/^wp-settings/i.test(n);
  });
  await page.context().clearCookies();
  if (keep.length) {
    await page.context().addCookies(keep);
  }
}

/**
 * @param {import('playwright').Page} page
 * @param {string} resetLink
 * @param {string} newPassword
 * @param {object} [logger]
 */
async function setWordPressPassword(page, resetLink, newPassword, logger) {
  await clearWordPressAuthCookies(page);
  logger?.info?.('Abrindo formulário WP de nova senha (cookies de sessão WP limpos)');

  await page.goto(resetLink, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Modern WP may hide pass1 behind "Generate Password".
  const genBtn = page.locator('.wp-generate-pw, button.wp-generate-pw').first();
  if (await genBtn.isVisible().catch(() => false)) {
    await genBtn.click({ force: true }).catch(() => {});
    await page.waitForTimeout(300);
  }

  const pass1 = page.locator('#pass1, input[name="pass1"]').first();
  const appeared = await Promise.race([
    pass1.waitFor({ state: 'attached', timeout: 20000 }).then(() => 'form'),
    page
      .waitForFunction(() => {
        const err = document.querySelector('#login_error');
        if (err && (err.textContent || '').trim()) {
          return true;
        }
        const body = (document.body?.innerText || '').toLowerCase();
        return /expired|inválid|invalid|not allowed|não é válid|já foi usada/i.test(body);
      }, { timeout: 20000 })
      .then(() => 'error')
      .catch(() => null),
  ]).catch(() => null);

  const hasPass1 = (await pass1.count()) > 0;
  if (appeared === 'error' || !hasPass1) {
    const detail =
      ((await page.locator('#login_error').innerText().catch(() => '')) || '').trim() ||
      `url=${page.url()}`;
    throw taggedError(
      FAILURE_KIND.PASSWORD_RESET,
      `Link de redefinição WP inutilizável: ${detail}`
    );
  }

  // Set password via DOM (pass2 is often hidden; fill() would hang).
  await page.evaluate((pwd) => {
    const reveal = document.querySelector('.wp-generate-pw, button.wp-generate-pw');
    const p1 = document.querySelector('#pass1, input[name="pass1"]');
    if (reveal && p1 && (p1.type === 'hidden' || getComputedStyle(p1).display === 'none')) {
      reveal.click();
    }
    const apply = (el) => {
      if (!el) {
        return;
      }
      el.removeAttribute('readonly');
      el.removeAttribute('disabled');
      el.style.display = '';
      el.style.visibility = 'visible';
      el.value = pwd;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    apply(document.querySelector('#pass1, input[name="pass1"]'));
    apply(document.querySelector('#pass2, input[name="pass2"]'));
    const weak = document.querySelector('#pw-weak');
    if (weak) {
      weak.checked = true;
      weak.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, newPassword);

  await page.waitForTimeout(250);
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
    page.locator('#wp-submit').first().click({ force: true }),
  ]);

  const errText = ((await page.locator('#login_error').innerText().catch(() => '')) || '').trim();
  const stillPassForm = await page.locator('#pass1, input[name="pass1"]').isVisible().catch(() => false);
  if (errText || stillPassForm) {
    throw taggedError(
      FAILURE_KIND.PASSWORD_RESET,
      `WordPress não confirmou a nova senha (${errText || 'formulário ainda visível'}; url=${page.url()}; before=${beforeUrl}).`
    );
  }
}

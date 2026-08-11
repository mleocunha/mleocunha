import { generateSecurePassword } from './passwordGen.js';
import { subjectForLocale } from './mailSubjects.js';

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
  } = opts;

  const resolved = resolveMailUrlForEmail(mailUrl, userEmail);
  const effectiveMailUrl = resolved.url;
  const subject = subjectForLocale(batchLocale);
  logger?.info?.('Disparando e-mail de redefinição', {
    user_email: userEmail,
    subject,
    locale: batchLocale,
    mail_url: effectiveMailUrl,
    mail_url_configured: mailUrl,
    mail_url_derived: resolved.derived,
  });
  if (resolved.derived) {
    logger?.info?.(
      `URL SnappyMail ajustada para o domínio do e-mail (${resolved.emailDomain})`,
      { mail_url: effectiveMailUrl }
    );
  }

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

  const mailPage = await page.context().newPage();
  try {
    const resetLink = await findResetLinkInSnappyMail(mailPage, {
      mailUrl: effectiveMailUrl,
      userEmail,
      currentPassword,
      subject,
      timeoutMs,
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
  const { mailUrl, userEmail, currentPassword, subject, timeoutMs, logger } = opts;

  await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await rejectRoundcubeSurface(page);
  await loginToSnappyMail(page, userEmail, currentPassword, mailUrl, logger);
  await openInboxFolder(page);

  const deadline = Date.now() + timeoutMs;
  let resetLink = '';

  while (Date.now() < deadline) {
    await reloadMessageList(page);

    const row = page
      .locator('.messageListItem')
      .filter({ has: page.locator('.subjectParent', { hasText: subject }) })
      .first();

    if (await row.count()) {
      await row.click();
      await page.locator('.bodyText, .b-message, .messageView').first().waitFor({
        state: 'visible',
        timeout: 15000,
      }).catch(() => {});
      await page.waitForTimeout(600);

      resetLink = await extractResetLink(page);
      if (resetLink) {
        await markCurrentMessageSeen(page);
        logger?.info?.('E-mail de redefinição aberto na INBOX (SnappyMail)', { subject });
        return resetLink;
      }
    }

    await page.waitForTimeout(2000);
  }

  throw new Error(`E-mail "${subject}" não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s.`);
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
  const emailInput = page.locator('input[name="Email"]').first();
  await emailInput.waitFor({ state: 'visible', timeout: 60000 });

  await typeIntoKnockoutField(emailInput, userEmail);
  const passInput = page.locator('input[name="Password"]').first();
  await typeIntoKnockoutField(passInput, currentPassword);

  const loginBtn = page.locator('button.buttonLogin').first();
  if (await loginBtn.count()) {
    await loginBtn.click();
  } else {
    await passInput.press('Enter');
  }

  const inboxReady = page.locator('.messageList, .messageListPlace, .b-folders').first();
  const started = Date.now();
  while (Date.now() - started < 60000) {
    if (await inboxReady.isVisible().catch(() => false)) {
      logger?.info?.('Login SnappyMail OK', { mail_url: mailUrl, user_email: userEmail });
      return;
    }
    const submitting = (await page.locator('form.submitting').count()) > 0;
    if (!submitting && Date.now() - started > 1200) {
      const errText = await readSnappyLoginError(page);
      const emailStill = await emailInput.isVisible().catch(() => false);
      // Meaningful alert, or idle login form after several seconds → fail.
      if (errText || (emailStill && Date.now() - started > 8000)) {
        break;
      }
    }
    await page.waitForTimeout(200);
  }

  if (await inboxReady.isVisible().catch(() => false)) {
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
    await inbox.click().catch(() => {});
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

  const pass1 = page.locator('#pass1, input[name="pass1"]').first();
  await pass1.waitFor({ state: 'visible', timeout: 30000 });
  await pass1.fill(newPassword);

  const pass2 = page.locator('#pass2, input[name="pass2"]').first();
  if (await pass2.count()) {
    await pass2.fill(newPassword);
  }

  const weak = page.locator('#pw-weak');
  if (await weak.count()) {
    await weak.check({ force: true }).catch(() => {});
  }

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.locator('#wp-submit, button[type="submit"]').first().click(),
  ]);
}

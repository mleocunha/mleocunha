import { generateSecurePassword } from './passwordGen.js';
import { subjectForLocale } from './mailSubjects.js';

/** Default after abandoning Roundcube at /mail/ — SnappyMail subserver. */
const DEFAULT_MAIL_URL = 'https://webmail.relatasoft.com.br/';

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

  const subject = subjectForLocale(batchLocale);
  logger?.info?.('Disparando e-mail de redefinição', {
    user_email: userEmail,
    subject,
    locale: batchLocale,
    mail_url: mailUrl,
  });

  await ensureOnWelcomeWithResetForm(page);

  await page.locator('#rses-poc-mail-locale').fill(batchLocale || '');
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
      mailUrl,
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

  const emailInput = page.locator('input[name="Email"]').first();
  await emailInput.waitFor({ state: 'visible', timeout: 60000 });
  await emailInput.fill(userEmail);
  await page.locator('input[name="Password"]').first().fill(currentPassword);

  const loginBtn = page.locator('button.buttonLogin, button[data-i18n="LOGIN/BUTTON_SIGN_IN"]').first();
  if (await loginBtn.count()) {
    await loginBtn.click();
  } else {
    await page.locator('input[name="Password"]').first().press('Enter');
  }

  // SnappyMail is a SPA — no full navigation on login.
  const inboxReady = page.locator('.messageList, .messageListPlace, .b-folders').first();
  const loginError = page.locator('.alert:visible, form.errorAnimated').first();
  await Promise.race([
    inboxReady.waitFor({ state: 'visible', timeout: 60000 }),
    loginError.waitFor({ state: 'visible', timeout: 60000 }).catch(() => {}),
  ]);

  if (await page.locator('input[name="Email"]').isVisible().catch(() => false)) {
    const errText = ((await loginError.textContent().catch(() => '')) || '').trim();
    throw new Error(
      errText
        ? `Falha no login SnappyMail: ${errText}`
        : 'Falha no login SnappyMail (e-mail/senha).'
    );
  }

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
      'A URL de webmail ainda serve Roundcube. Use SnappyMail (ex.: https://webmail.relatasoft.com.br/) via --mail-url / campo URL.'
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

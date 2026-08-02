import { generateSecurePassword } from './passwordGen.js';
import { subjectForLocale } from './mailSubjects.js';

const DEFAULT_MAIL_URL = 'https://relatasoft.com.br/mail/';

/**
 * Trigger WP shortcode reset, read Roundcube INBOX, set new WP password.
 *
 * @param {import('playwright').Page} page Already logged into WordPress as the elector.
 * @param {object} opts
 */
export async function resetPasswordViaRoundcube(page, opts) {
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
    const resetLink = await findResetLinkInRoundcube(mailPage, {
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
  // Already on a page without shortcode — caller should navigate to welcome first.
  throw new Error(
    'Shortcode [enviar_redefinicao_senha] não encontrado na página. Insira-o na página de boas-vindas.'
  );
}

async function findResetLinkInRoundcube(page, opts) {
  const { mailUrl, userEmail, currentPassword, subject, timeoutMs, logger } = opts;

  await page.goto(mailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#rcmloginuser', userEmail);
  await page.fill('#rcmloginpwd', currentPassword);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#rcmloginsubmit'),
  ]);

  if (await page.locator('#rcmloginuser').count()) {
    throw new Error('Falha no login Roundcube (e-mail/senha).');
  }

  // Prefer INBOX mailbox if a folder list is present.
  const inbox = page.locator('#mailboxlist a, .mailbox a, a').filter({ hasText: /^Inbox$|^INBOX$|^Caixa/i }).first();
  if (await inbox.count()) {
    await inbox.click().catch(() => {});
    await page.waitForTimeout(500);
  }

  const deadline = Date.now() + timeoutMs;
  let resetLink = '';

  while (Date.now() < deadline) {
    await page.keyboard.press('r').catch(() => {}); // Roundcube refresh shortcut when focused
    const refreshBtn = page.locator('a.checkmail, a.toolbar-button.refresh, button.refresh').first();
    if (await refreshBtn.count()) {
      await refreshBtn.click().catch(() => {});
    }

    const row = page
      .locator('#messagelist tr, #messagelist tbody tr, tr.message')
      .filter({ hasText: subject })
      .first();

    if (await row.count()) {
      await row.click();
      await page.waitForTimeout(800);

      resetLink = await extractResetLink(page);
      if (resetLink) {
        await markCurrentMessageRead(page);
        logger?.info?.('E-mail de redefinição aberto na INBOX', { subject });
        return resetLink;
      }
    }

    await page.waitForTimeout(2000);
  }

  throw new Error(`E-mail "${subject}" não encontrado na INBOX em ${Math.round(timeoutMs / 1000)}s.`);
}

async function extractResetLink(page) {
  const frames = [page, ...page.frames()];
  for (const frame of frames) {
    try {
      const href = await frame.evaluate(() => {
        const anchors = Array.from(document.querySelectorAll('a[href]'));
        for (const a of anchors) {
          const h = a.href || '';
          if (/wp-login\.php/i.test(h) && /action=rp/i.test(h)) {
            return h;
          }
        }
        const text = document.body ? document.body.innerText : '';
        const match = text.match(/https?:\/\/[^\s"'<>]+wp-login\.php\?[^\s"'<>]*action=rp[^\s"'<>]*/i);
        return match ? match[0] : '';
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
  const btn = page
    .locator(
      'a.button.read, a.read, a[id*="markasread"], a[onclick*="mark"], button.read, a.markmessageread'
    )
    .first();
  if (await btn.count()) {
    await btn.click().catch(() => {});
    return;
  }
  // Fallback: Roundcube mark menu
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

  // Success often lands on rp login confirmation or wp-login.
  const body = await page.content();
  if (/password.?reset|redefin|has been reset|foi redefinida|updated/i.test(body) || /wp-login\.php/i.test(page.url())) {
    return;
  }
}

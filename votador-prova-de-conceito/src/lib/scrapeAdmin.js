import { joinUrl } from './urls.js';

/**
 * Discover open elections via admin JSON dump (preferred) or embedded script.
 * @param {import('playwright').BrowserContext} context
 * @param {{ platformUrl: string, adminUser: string, adminPassword: string, loginUrl: string, log?: Function }} opts
 */
export async function scrapeOpenElections(context, opts) {
  const page = await context.newPage();
  const log = opts.log || (() => {});

  try {
    await loginAsAdmin(page, opts);
    log({ level: 'info', message: 'Admin autenticado; buscando eleições abertas…' });

    const dumpUrl = joinUrl(
      opts.platformUrl,
      '/wp-admin/admin-post.php?action=rses_dump_open_elections'
    );

    const response = await page.goto(dumpUrl, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    const contentType = response?.headers()?.['content-type'] || '';
    if (contentType.includes('application/json')) {
      const data = await response.json();
      return normalizeSnapshot(data);
    }

    // Fallback: elections list page embeds #rses-open-elections-json
    const listUrl = joinUrl(
      opts.platformUrl,
      '/wp-admin/admin.php?page=rses-elections'
    );
    await page.goto(listUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const raw = await page
      .locator('#rses-open-elections-json')
      .textContent({ timeout: 15000 });
    const data = JSON.parse(raw || '{}');
    return normalizeSnapshot(data);
  } finally {
    await page.close().catch(() => {});
  }
}

async function loginAsAdmin(page, opts) {
  await page.goto(opts.loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#user_login', opts.adminUser);
  await page.fill('#user_pass', opts.adminPassword);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#wp-submit'),
  ]);

  const url = page.url();
  if (/wp-login\.php/i.test(url) && (await page.locator('#login_error').count())) {
    const err = (await page.locator('#login_error').innerText()).trim();
    throw new Error(`Falha no login admin: ${err || 'credenciais inválidas'}`);
  }
}

function normalizeSnapshot(data) {
  const elections = Array.isArray(data?.elections) ? data.elections : [];
  const rounds = [];
  for (const el of elections) {
    for (const round of el.rounds || []) {
      rounds.push({
        election_id: Number(el.id),
        election_title: String(el.title || ''),
        round_id: Number(round.id),
        round_title: String(round.title || ''),
        questions: round.questions || [],
      });
    }
  }
  return {
    journey: {
      welcome: data?.journey?.welcome || '',
      booth: data?.journey?.booth || '',
      thank_you: data?.journey?.thank_you || '',
    },
    elections,
    rounds,
  };
}

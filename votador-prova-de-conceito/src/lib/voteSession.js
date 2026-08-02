import { boothUrlFor } from './urls.js';
import { resetPasswordViaRoundcube } from './roundcubeReset.js';

/**
 * Run one elector through all open rounds.
 * @param {import('playwright').BrowserContext} context
 * @param {object} opts
 */
export async function voteElector(context, opts) {
  const {
    elector,
    loginUrl,
    openRounds,
    journeyCache,
    logger,
    passwordChangePoc = false,
    mailUrl,
    batchLocale,
    passwordStore,
  } = opts;

  const page = await context.newPage();
  page.setDefaultTimeout(45000);

  // Accept native confirm() on ballot submit without artificial delay.
  page.on('dialog', async (dialog) => {
    try {
      await dialog.accept();
    } catch {
      /* already handled */
    }
  });

  const votes = [];

  try {
    const auth = await authenticateElector(page, {
      loginUrl,
      elector,
      passwordChangePoc,
      mailUrl,
      batchLocale,
      passwordStore,
      journeyCache,
      logger,
    });

    let journey = { ...journeyCache.current };
    if (!journey.welcome || !journey.booth) {
      journey = await discoverJourneyFromWelcome(page, journey, logger);
      if (journey.welcome || journey.booth || journey.thank_you) {
        journeyCache.current = { ...journeyCache.current, ...journey };
        logger.info('URLs da jornada em cache', { journey: journeyCache.current });
      }
    } else if (journey.welcome) {
      await page.goto(journey.welcome, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
    }

    // After password reset we may still be on welcome; ensure journey cache welcome.
    if (!journeyCache.current.welcome && journey.welcome) {
      journeyCache.current.welcome = journey.welcome;
    }
    if (auth.welcomeHint) {
      journeyCache.current.welcome = auth.welcomeHint;
    }

    const rounds = await resolveRoundsForElector(page, openRounds, journey);
    if (!rounds.length) {
      logger.warn('Nenhuma eleição aberta para o eleitor', {
        user_login: elector.user_login,
      });
      return { votes, journey: journeyCache.current };
    }

    for (const round of rounds) {
      const result = await castOneBallot(page, {
        elector,
        round,
        boothBase: journey.booth || journeyCache.current.booth,
        logger,
      });
      votes.push(result);

      if (result.thank_you_url) {
        const ty = stripQuery(result.thank_you_url);
        if (ty) {
          journeyCache.current.thank_you = ty;
        }
      }
      if (result.welcome_url) {
        journeyCache.current.welcome = result.welcome_url;
      }

      // Return to welcome between elections when available.
      const welcome = journeyCache.current.welcome || journey.welcome;
      if (welcome && rounds.indexOf(round) < rounds.length - 1) {
        await page.goto(welcome, { waitUntil: 'domcontentloaded', timeout: 60000 });
      }
    }

    return { votes, journey: journeyCache.current };
  } finally {
    await page.close().catch(() => {});
  }
}

/**
 * Login; optionally reset via Roundcube when PoC password-change is enabled.
 */
async function authenticateElector(page, opts) {
  const {
    loginUrl,
    elector,
    passwordChangePoc,
    mailUrl,
    batchLocale,
    passwordStore,
    journeyCache,
    logger,
  } = opts;

  if (!passwordChangePoc) {
    await loginWithPassword(page, loginUrl, elector.user_login, elector.password);
    return { password: elector.password, didReset: false };
  }

  const stored = passwordStore?.get(elector.user_login);
  if (stored?.password) {
    const ok = await tryLogin(page, loginUrl, elector.user_login, stored.password);
    if (ok) {
      logger.info('Usando senha gerada anteriormente (sem novo reset)', {
        user_login: elector.user_login,
      });
      return { password: stored.password, didReset: false };
    }
  }

  const csvOk = await tryLogin(page, loginUrl, elector.user_login, elector.password);
  if (!csvOk) {
    throw new Error(
      `Login falhou para ${elector.user_login}: nem a senha gerada local nem a do CSV funcionaram.`
    );
  }

  // Ensure welcome (shortcode lives there).
  let welcome = journeyCache.current.welcome;
  if (!welcome) {
    const discovered = await discoverJourneyFromWelcome(page, {}, logger);
    welcome = discovered.welcome || stripQuery(page.url());
    if (discovered.booth) {
      journeyCache.current.booth = discovered.booth;
    }
    if (discovered.thank_you) {
      journeyCache.current.thank_you = discovered.thank_you;
    }
    journeyCache.current.welcome = welcome;
  } else {
    await page.goto(welcome, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  const newPassword = await resetPasswordViaRoundcube(page, {
    mailUrl,
    userEmail: elector.user_email,
    currentPassword: elector.password,
    batchLocale,
    timeoutMs: 120000,
    logger,
  });

  passwordStore?.set(elector.user_login, newPassword, elector.user_email || '');
  logger.info('Nova senha gerada e gravada localmente', {
    user_login: elector.user_login,
  });

  // Re-login with the new password before voting.
  await page.context().clearCookies();
  await loginWithPassword(page, loginUrl, elector.user_login, newPassword);
  if (welcome) {
    await page.goto(welcome, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  return { password: newPassword, didReset: true, welcomeHint: welcome };
}

async function tryLogin(page, loginUrl, userLogin, password) {
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#user_login', userLogin);
  await page.fill('#user_pass', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#wp-submit'),
  ]);
  return !/wp-login\.php/i.test(page.url());
}

async function loginWithPassword(page, loginUrl, userLogin, password) {
  const ok = await tryLogin(page, loginUrl, userLogin, password);
  if (!ok) {
    const err = await page.locator('#login_error').innerText().catch(() => '');
    throw new Error(`Login falhou para ${userLogin}: ${err.trim() || 'credenciais inválidas'}`);
  }
}

async function discoverJourneyFromWelcome(page, seed, logger) {
  const journey = { ...seed };

  // Prefer embedded JSON from welcome shortcode.
  const jsonEl = page.locator('#rses-open-elections-json');
  if (await jsonEl.count()) {
    try {
      const data = JSON.parse((await jsonEl.textContent()) || '{}');
      if (data?.journey) {
        journey.welcome = data.journey.welcome || journey.welcome;
        journey.booth = data.journey.booth || journey.booth;
        journey.thank_you = data.journey.thank_you || journey.thank_you;
      }
    } catch (e) {
      logger.warn('JSON de eleições abertas inválido na boas-vindas', {
        error: String(e.message || e),
      });
    }
  }

  if (!journey.welcome && /voter-welcome|rses-journey/i.test(page.url() + (await page.content().catch(() => '')))) {
    journey.welcome = stripQuery(page.url());
  }

  const boothLink = page.locator('a.rses-open-election-link, a.rses-journey-btn--primary').first();
  if (!journey.booth && (await boothLink.count())) {
    const href = await boothLink.getAttribute('href');
    if (href) {
      journey.booth = stripQuery(new URL(href, page.url()).toString());
    }
  }

  return journey;
}

async function resolveRoundsForElector(page, openRounds, journey) {
  // Prefer live welcome list (respects already-voted markers).
  const links = page.locator('a.rses-open-election-link');
  const count = await links.count();
  if (count > 0) {
    const fromDom = [];
    for (let i = 0; i < count; i += 1) {
      const link = links.nth(i);
      const electionId = Number(await link.getAttribute('data-rses-election-id'));
      const roundId = Number(await link.getAttribute('data-rses-round-id'));
      const already = (await link.getAttribute('data-rses-already-voted')) === '1';
      if (electionId && roundId) {
        fromDom.push({
          election_id: electionId,
          round_id: roundId,
          already_voted: already,
        });
      }
    }
    // Still visit already-voted rounds to capture receipt hash when present.
    return fromDom;
  }

  return (openRounds || []).map((r) => ({
    election_id: r.election_id,
    round_id: r.round_id,
    already_voted: false,
  }));
}

async function castOneBallot(page, { elector, round, boothBase, logger }) {
  if (!boothBase) {
    throw new Error('URL da cabina (booth) desconhecida — configure Redirecionamentos no plugin.');
  }

  const url = boothUrlFor(boothBase, round.election_id, round.round_id);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Already voted → collect receipt if shown.
  const receiptRoot = page.locator('[data-rses-booth="receipt"]');
  if (await receiptRoot.count()) {
    const hash =
      (await page.locator('[data-rses-receipt-hash], .rses-receipt-hash').first().textContent()) ||
      '';
    const status = 'already_voted';
    logger.receipt({
      user_login: elector.user_login,
      election_id: round.election_id,
      round_id: round.round_id,
      receipt_hash: hash.trim(),
      status,
    });
    return {
      election_id: round.election_id,
      round_id: round.round_id,
      receipt_hash: hash.trim(),
      status,
    };
  }

  const form = page.locator('#rses-ballot-form');
  if (!(await form.count())) {
    throw new Error(
      `Cabina sem boletim (election=${round.election_id}, round=${round.round_id})`
    );
  }

  await fillRandomBallot(page);
  await page.locator('.rses-submit-vote').click();

  // Wait for thank-you or booth receipt.
  await page.waitForFunction(
    () =>
      Boolean(
        document.querySelector('[data-rses-journey="thank-you"]') ||
          document.querySelector('#rses-journey-receipt-hash') ||
          document.querySelector('[data-rses-booth="receipt"]') ||
          new URLSearchParams(location.search).get('rses_receipt')
      ),
    { timeout: 60000 }
  );

  let receipt_hash = '';
  const thankHash = page.locator('#rses-journey-receipt-hash, [data-rses-receipt-hash]').first();
  if (await thankHash.count()) {
    receipt_hash = ((await thankHash.textContent()) || '').trim();
  }
  if (!receipt_hash) {
    try {
      receipt_hash = new URL(page.url()).searchParams.get('rses_receipt') || '';
    } catch {
      receipt_hash = '';
    }
  }

  const status = receipt_hash ? 'voted' : 'voted_no_receipt';
  logger.receipt({
    user_login: elector.user_login,
    election_id: round.election_id,
    round_id: round.round_id,
    receipt_hash,
    status,
  });

  let welcome_url = '';
  const cont = page.locator('[data-rses-continue-voting]');
  if (await cont.count()) {
    const href = await cont.getAttribute('href');
    if (href) {
      welcome_url = stripQuery(new URL(href, page.url()).toString());
    }
  }

  return {
    election_id: round.election_id,
    round_id: round.round_id,
    receipt_hash,
    status,
    thank_you_url: page.url(),
    welcome_url,
  };
}

async function fillRandomBallot(page) {
  const questions = page.locator('fieldset.rses-question');
  const qCount = await questions.count();

  for (let qi = 0; qi < qCount; qi += 1) {
    const q = questions.nth(qi);
    const type = (await q.getAttribute('data-rses-question-type')) || '';
    const min = Math.max(0, Number(await q.getAttribute('data-rses-min')) || 0);
    const maxAttr = Number(await q.getAttribute('data-rses-max'));

    if (type === 'numeric') {
      const input = q.locator('.rses-numeric-input');
      const value = String(Math.floor(Math.random() * 10));
      await input.fill(value);
      continue;
    }

    const inputs = q.locator('.rses-choice-input');
    const n = await inputs.count();
    if (!n) {
      continue;
    }

    const isMulti = (await inputs.first().getAttribute('type')) === 'checkbox';
    if (!isMulti) {
      const pick = Math.floor(Math.random() * n);
      await inputs.nth(pick).check({ force: true });
      continue;
    }

    const max = Number.isFinite(maxAttr) && maxAttr > 0 ? Math.min(maxAttr, n) : n;
    const need = Math.max(min > 0 ? min : 1, 1);
    const take = randInt(need, Math.max(need, max));
    const idxs = shuffle([...Array(n).keys()]).slice(0, take);
    for (const idx of idxs) {
      await inputs.nth(idx).check({ force: true });
    }
  }
}

function randInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function shuffle(arr) {
  for (let i = arr.length - 1; i > 0; i -= 1) {
    const j = Math.floor(Math.random() * (i + 1));
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
}

function stripQuery(url) {
  try {
    const u = new URL(url);
    u.search = '';
    u.hash = '';
    return u.toString();
  } catch {
    return url;
  }
}

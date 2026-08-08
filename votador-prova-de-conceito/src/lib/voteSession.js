import { boothUrlFor } from './urls.js';
import { resetPasswordViaRoundcube } from './roundcubeReset.js';
import { readLoginError, tryWpLogin } from './wpLogin.js';

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
      if (fillJourneyIfEmpty(journeyCache, journey)) {
        logger.info('URLs da jornada em cache', { journey: journeyCache.current });
      }
    } else if (journey.welcome) {
      await page.goto(journey.welcome, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
    }

    // After password reset we may still be on welcome; ensure journey cache welcome.
    fillJourneyIfEmpty(journeyCache, {
      welcome: auth.welcomeHint || journey.welcome || '',
    });

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
        journeyCache,
        logger,
      });
      votes.push(result);

      if (result.thank_you_url) {
        fillJourneyIfEmpty(journeyCache, {
          thank_you: stripQuery(result.thank_you_url),
        });
      }
      if (result.welcome_url) {
        fillJourneyIfEmpty(journeyCache, { welcome: result.welcome_url });
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
 *
 * PoC order (legal headed Chrome):
 *   stored WP password? → login → vote
 *   else: Recuperar minha senha (no WP session) → Roundcube (CSV email password
 *   unchanged) → set WP password → CSV → logout → login → vote
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

  if (!elector.user_email) {
    throw new Error(
      `PoC com troca de senha exige user_email no CSV (${elector.user_login}).`
    );
  }

  const stored = passwordStore?.get(elector.user_login);
  if (stored?.password) {
    const ok = await tryLogin(page, loginUrl, elector.user_login, stored.password);
    if (ok) {
      logger.info('Usando senha WP gerada anteriormente (sem novo reset)', {
        user_login: elector.user_login,
      });
      return { password: stored.password, didReset: false };
    }
    passwordStore?.delete?.(elector.user_login);
    logger.warn('Senha gerada anterior não autenticou; iniciando reset via Roundcube', {
      user_login: elector.user_login,
    });
  }

  // Do NOT log into WordPress with the CSV password first.
  // CSV `password` is the Roundcube / mailbox secret and stays unchanged.
  const newPassword = await resetPasswordViaRoundcube(page, {
    loginUrl,
    mailUrl,
    userLogin: elector.user_login,
    userEmail: elector.user_email,
    mailPassword: elector.password,
    batchLocale,
    timeoutMs: 120000,
    logger,
  });

  // Fresh session: reset already verified login once; login again then vote.
  // Only persist the password after this login succeeds (avoids reset loops on
  // a password that never authenticated).
  logger.info('Login WordPress com a senha nova (após reset); em seguida vota', {
    user_login: elector.user_login,
  });
  await page.context().clearCookies();
  await loginWithPassword(page, loginUrl, elector.user_login, newPassword);

  passwordStore?.set(elector.user_login, newPassword, elector.user_email || '');
  logger.info('Nova senha WP gerada e gravada localmente (senha de e-mail inalterada)', {
    user_login: elector.user_login,
  });

  let welcome = journeyCache.current.welcome;
  if (welcome) {
    await page.goto(welcome, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  return { password: newPassword, didReset: true, welcomeHint: welcome || '' };
}

async function tryLogin(page, loginUrl, userLogin, password) {
  return tryWpLogin(page, loginUrl, userLogin, password);
}

async function loginWithPassword(page, loginUrl, userLogin, password) {
  const ok = await tryLogin(page, loginUrl, userLogin, password);
  if (!ok) {
    const err = await readLoginError(page);
    const detail = err || 'credenciais inválidas (ainda na página de login)';
    throw new Error(
      `Login falhou para ${userLogin}: ${detail} [senha_len=${String(password || '').length}]`
    );
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

  // Prefer explicit open-election links only — never generic primary CTAs
  // (those may be "Sign in" or "Continue voting").
  const boothLink = page.locator('a.rses-open-election-link').first();
  if (!journey.booth && (await boothLink.count())) {
    const href = await boothLink.getAttribute('href');
    if (href) {
      journey.booth = stripQuery(new URL(href, page.url()).toString());
    }
  }

  return journey;
}

/**
 * Fill missing journey URLs only (safe under concurrent workers).
 * @param {{ current: Record<string,string> }} cache
 * @param {Record<string,string>} patch
 * @returns {boolean} true if any field was written
 */
function fillJourneyIfEmpty(cache, patch) {
  let wrote = false;
  if (!cache.current) {
    cache.current = {};
  }
  for (const key of ['welcome', 'booth', 'thank_you']) {
    const value = patch?.[key];
    if (value && !cache.current[key]) {
      cache.current[key] = value;
      wrote = true;
    }
  }
  return wrote;
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

async function castOneBallot(page, { elector, round, boothBase, journeyCache, logger }) {
  if (!boothBase) {
    throw new Error('URL da cabina (booth) desconhecida — configure Redirecionamentos no plugin.');
  }

  const url = boothUrlFor(boothBase, round.election_id, round.round_id);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // First elector maps every booth cell on the page (open ballot vs closed/not-yet).
  let cells = journeyCache?.current?.boothCells;
  if (!Array.isArray(cells) || !cells.length) {
    cells = await discoverBoothCells(page);
    if (journeyCache?.current) {
      journeyCache.current.boothCells = cells;
    }
    logger.info('Células da cabina descobertas', {
      cells: cells.map((c) => ({
        index: c.index,
        kind: c.kind,
        election_id: c.election_id,
        round_id: c.round_id,
        has_form: c.has_form,
      })),
    });
  }

  const cell = findBoothCell(cells, round);
  if (cell && (cell.kind === 'closed' || cell.kind === 'empty') && !cell.has_form) {
    throw new Error(
      `Célula da cabina ainda não está aberta para votar (election=${round.election_id}, round=${round.round_id}, kind=${cell.kind})`
    );
  }

  // Already voted → collect receipt if shown for this round.
  const scopedReceipt = page.locator(
    `[data-rses-booth="receipt"][data-rses-election-id="${round.election_id}"][data-rses-round-id="${round.round_id}"]`
  );
  if (await scopedReceipt.count()) {
    const hash =
      (await scopedReceipt.locator('[data-rses-receipt-hash], .rses-receipt-hash').first().textContent()) ||
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

  const form = ballotForm(page, round, cells);
  if (!(await form.count())) {
    throw new Error(
      `Cabina sem boletim votável para election=${round.election_id}, round=${round.round_id}. Células: ${JSON.stringify(cells)}`
    );
  }

  await fillRandomBallot(page, round, cells);
  await form.locator('.rses-submit-vote').click();

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

/**
 * Inspect all booth cells currently on the page.
 * @param {import('playwright').Page} page
 */
async function discoverBoothCells(page) {
  return page.evaluate(() => {
    const cells = [];
    document.querySelectorAll('.rses-booth').forEach((el, index) => {
      const form = el.querySelector('form.rses-ballot-form');
      const kindAttr = el.getAttribute('data-rses-booth') || '';
      let kind = kindAttr;
      if (!kind) {
        kind = form ? 'ballot' : el.querySelector('.rses-message') ? 'message' : 'unknown';
      }
      const eid =
        Number(el.getAttribute('data-rses-election-id')) ||
        Number(form?.getAttribute('data-rses-election-id')) ||
        Number(form?.querySelector('input[name="election_id"]')?.value) ||
        0;
      const rid =
        Number(el.getAttribute('data-rses-round-id')) ||
        Number(form?.getAttribute('data-rses-round-id')) ||
        Number(form?.querySelector('input[name="round_id"]')?.value) ||
        0;
      cells.push({
        index,
        kind,
        election_id: eid,
        round_id: rid,
        has_form: Boolean(form),
        form_id: form?.id || '',
      });
    });
    return cells;
  });
}

/**
 * @param {Array<object>} cells
 * @param {{ election_id: number, round_id: number }} round
 */
function findBoothCell(cells, round) {
  const eid = Number(round.election_id);
  const rid = Number(round.round_id);
  return (
    (cells || []).find((c) => c.election_id === eid && c.round_id === rid) ||
    (cells || []).find((c) => c.round_id === rid) ||
    null
  );
}

/**
 * Ballot form for this election/round cell only — never grab another booth on the page.
 *
 * @param {import('playwright').Page} page
 * @param {{ election_id: number, round_id: number }} round
 * @param {Array<object>} [cells]
 */
function ballotForm(page, round, cells = []) {
  const eid = Number(round.election_id);
  const rid = Number(round.round_id);
  const cell = findBoothCell(cells, round);

  // Prefer the discovered cell index — stable even when the page hosts several booths
  // or when an older plugin build duplicated the same form id via GET override.
  if (cell && cell.has_form && Number.isInteger(cell.index)) {
    return page.locator('.rses-booth').nth(cell.index).locator('form.rses-ballot-form');
  }

  return page.locator(
    [
      `.rses-booth[data-rses-booth="ballot"][data-rses-election-id="${eid}"][data-rses-round-id="${rid}"] form.rses-ballot-form`,
      `form.rses-ballot-form[data-rses-election-id="${eid}"][data-rses-round-id="${rid}"]`,
      `form#rses-ballot-form-${rid}`,
    ].join(', ')
  );
}

async function fillRandomBallot(page, round, cells = []) {
  const form = ballotForm(page, round, cells);
  await form.waitFor({ state: 'visible', timeout: 60000 });
  // Let booth CSS/JS (is-checked sync) settle before interacting.
  await page.waitForTimeout(150);

  const questions = form.locator('fieldset.rses-question');
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
      await selectBallotChoice(inputs.nth(pick));
      continue;
    }

    const max = Number.isFinite(maxAttr) && maxAttr > 0 ? Math.min(maxAttr, n) : n;
    const need = Math.max(min > 0 ? min : 1, 1);
    const take = randInt(need, Math.max(need, max));
    const idxs = shuffle([...Array(n).keys()]).slice(0, take);
    for (const idx of idxs) {
      await selectBallotChoice(inputs.nth(idx));
    }
  }
}

/**
 * Select a booth radio/checkbox.
 *
 * Never use Playwright locator.check() here: custom CSS sets opacity:0 on
 * .rses-choice-input and check({ force }) clicks without leaving it checked.
 * Set the DOM property first (reliable), then sync the visible label UI.
 *
 * @param {import('playwright').Locator} input
 */
async function selectBallotChoice(input) {
  const ok = await input.evaluate((el) => {
    if (!(el instanceof HTMLInputElement)) {
      return false;
    }
    if (el.checked) {
      return true;
    }

    // Radios: clear siblings in the same named group first.
    if (el.type === 'radio' && el.name) {
      const root = el.form || el.ownerDocument;
      const group = Array.from(root.querySelectorAll('input[type="radio"]')).filter(
        (sibling) => sibling instanceof HTMLInputElement && sibling.name === el.name
      );
      group.forEach((sibling) => {
        if (sibling !== el) {
          sibling.checked = false;
          sibling.closest('label.rses-choice')?.classList.remove('is-checked');
        }
      });
    }

    el.checked = true;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.closest('label.rses-choice')?.classList.add('is-checked');
    return el.checked;
  });

  if (!ok) {
    const id = (await input.getAttribute('id')) || '?';
    throw new Error(`Não foi possível marcar a opção do boletim (${id})`);
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

import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { loadElectorsFromCsv } from './csv.js';
import { createRunLogger } from './logger.js';
import { scrapeOpenElections } from './scrapeAdmin.js';
import { resolveLoginUrl } from './urls.js';
import { voteElector } from './voteSession.js';
import { createPasswordStore } from './passwordStore.js';
import { startDisplayCaffeinate } from './caffeinate.js';
import { createAdaptivePool } from './adaptiveConcurrency.js';
import { createFailureTracker, categorizeFailure } from './failureReport.js';
import { createVisualDirector } from './visualHighlight.js';
import { resolveRampUpConfig, RAMP_UP_PRESETS } from './rampUp.js';

/** Bumped when PoC runtime behaviour changes — look for this in startup logs. */
export const VOTADOR_BUILD = 'reset-show-site-1';

export const DEFAULTS = {
  /** @deprecated use windowsInitial / windowsMax */
  windows: 5,
  /** @deprecated use tabsInitial / tabsMax */
  tabsPerWindow: 5,
  windowsInitial: 1,
  windowsMax: 5,
  tabsInitial: 1,
  tabsMax: 5,
  /** Adaptive ramp-up speed: slow | normal | fast | aggressive */
  rampUpSpeed: 'normal',
  /** Max electors skipped/logged after their insistências cycle (x). */
  tentativas: 50,
  /** Retry attempts per failed elector (n). */
  insistencias: 3,
  /**
   * Per-failure insistências ceiling (y). When a single elector's failed
   * attempts reach y, the whole run stops ("n == y" on that failure).
   */
  limiteRetentativas: 3,
  passwordChangePoc: false,
  visualHighlight: false,
  mailUrl: 'https://webmail.relatasoft.com.br/',
};

export { RAMP_UP_PRESETS, resolveRampUpConfig };

/**
 * Resolve adaptive bounds. Legacy `windows` / `tabsPerWindow` alone → fixed
 * concurrency (initial = max). Explicit initial/max win when provided.
 * @param {object} config
 */
export function resolveConcurrencyConfig(config = {}) {
  const hasAdaptive =
    config.windowsInitial != null ||
    config.windowsMax != null ||
    config.tabsInitial != null ||
    config.tabsMax != null;

  if (!hasAdaptive && (config.windows != null || config.tabsPerWindow != null)) {
    const w = Math.max(1, Number(config.windows ?? DEFAULTS.windows));
    const t = Math.max(1, Number(config.tabsPerWindow ?? DEFAULTS.tabsPerWindow));
    return {
      windowsInitial: w,
      windowsMax: w,
      tabsInitial: t,
      tabsMax: t,
      fixed: true,
    };
  }

  let windowsInitial = Number(config.windowsInitial ?? DEFAULTS.windowsInitial);
  let windowsMax = Number(config.windowsMax ?? config.windows ?? DEFAULTS.windowsMax);
  let tabsInitial = Number(config.tabsInitial ?? DEFAULTS.tabsInitial);
  let tabsMax = Number(config.tabsMax ?? config.tabsPerWindow ?? DEFAULTS.tabsMax);

  windowsInitial = Math.max(1, windowsInitial || 1);
  windowsMax = Math.max(windowsInitial, windowsMax || windowsInitial);
  tabsInitial = Math.max(1, tabsInitial || 1);
  tabsMax = Math.max(tabsInitial, tabsMax || tabsInitial);

  return {
    windowsInitial,
    windowsMax,
    tabsInitial,
    tabsMax,
    fixed: windowsInitial === windowsMax && tabsInitial === tabsMax,
  };
}

/**
 * @param {object} config
 * @param {{ onEvent?: Function, signal?: AbortSignal }} [hooks]
 */
export async function runVotador(config, hooks = {}) {
  const conc = resolveConcurrencyConfig(config);
  const cfg = {
    ...DEFAULTS,
    ...config,
    ...conc,
    tentativas: Number(config.tentativas ?? DEFAULTS.tentativas),
    insistencias: Number(config.insistencias ?? DEFAULTS.insistencias),
    limiteRetentativas: Number(config.limiteRetentativas ?? DEFAULTS.limiteRetentativas),
  };

  if (!cfg.platformUrl) {
    throw new Error('URL da plataforma é obrigatória.');
  }
  if (!cfg.csvPath || !fs.existsSync(cfg.csvPath)) {
    throw new Error('Arquivo CSV de eleitores não encontrado.');
  }
  if (!cfg.adminUser || !cfg.adminPassword) {
    throw new Error('Credenciais de administrador são obrigatórias para descobrir eleições abertas.');
  }

  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const resultsDir = cfg.resultsDir || path.join(path.resolve('results'), stamp);
  const logger = createRunLogger(resultsDir);
  if (hooks.onEvent) {
    logger.on(hooks.onEvent);
  }

  const { electors, headers, source } = loadElectorsFromCsv(cfg.csvPath);
  const loginUrl = resolveLoginUrl(cfg);
  const passwordChangePoc = Boolean(cfg.passwordChangePoc);
  const visualHighlight = Boolean(cfg.visualHighlight);
  const mailUrl = String(cfg.mailUrl || DEFAULTS.mailUrl).trim() || DEFAULTS.mailUrl;
  const passwordStore = createPasswordStore();
  const ramp = resolveRampUpConfig(cfg.rampUpSpeed, {
    scaleUpEveryMs: cfg.scaleUpEveryMs,
    scaleDownEveryMs: cfg.scaleDownEveryMs,
    healthySuccessesNeeded: cfg.healthySuccessesNeeded,
  });
  const visual = createVisualDirector({
    enabled: visualHighlight,
    focusEveryMs: 1500,
  });

  const caffeinate = startDisplayCaffeinate(logger);
  const launchOpts = buildLaunchOptions(cfg);
  const failureTracker = createFailureTracker({ minRepeats: 2 });

  const pool = createAdaptivePool({
    chromium,
    launchOpts,
    ignoreHTTPSErrors: Boolean(cfg.ignoreHTTPSErrors),
    windowsInitial: cfg.windowsInitial,
    windowsMax: cfg.windowsMax,
    tabsInitial: cfg.tabsInitial,
    tabsMax: cfg.tabsMax,
    adaptive: !cfg.fixed,
    scaleUpEveryMs: ramp.scaleUpEveryMs,
    scaleDownEveryMs: ramp.scaleDownEveryMs,
    healthySuccessesNeeded: ramp.healthySuccessesNeeded,
    // Any successful elector counts toward ramp-up (reset+vote routinely > 45s).
    healthyDurationMs: 0,
    slowDurationMs: ramp.slowDurationMs || 360000,
    logger,
  });

  logger.info(`Iniciando Votador PoC [${VOTADOR_BUILD}]`, {
    build: VOTADOR_BUILD,
    caffeinate: caffeinate.active,
    electors: electors.length,
    csv: source,
    csv_headers: headers,
    first_login: electors[0]?.user_login,
    first_email: electors[0]?.user_email || null,
    first_password_len: electors[0]?.password_len,
    adaptive: !cfg.fixed,
    windows_initial: cfg.windowsInitial,
    windows_max: cfg.windowsMax,
    tabs_initial: cfg.tabsInitial,
    tabs_max: cfg.tabsMax,
    concurrency_initial: cfg.windowsInitial * cfg.tabsInitial,
    concurrency_max: cfg.windowsMax * cfg.tabsMax,
    ramp_up_speed: ramp.rampUpSpeed,
    scale_up_every_ms: ramp.scaleUpEveryMs,
    scale_down_every_ms: ramp.scaleDownEveryMs,
    healthy_successes_needed: ramp.healthySuccessesNeeded,
    tentativas: cfg.tentativas,
    insistencias: cfg.insistencias,
    limiteRetentativas: cfg.limiteRetentativas,
    passwordChangePoc,
    visualHighlight,
    mailUrl: passwordChangePoc ? mailUrl : null,
    storedPasswords: passwordStore.size(),
    loginUrl,
    resultsDir,
  });

  try {
    await pool.start();
    const primaryBrowser = pool.getPrimaryBrowser();
    if (!primaryBrowser) {
      throw new Error('Falha ao abrir a primeira janela Chrome.');
    }

    const adminContext = await primaryBrowser.newContext({
      ignoreHTTPSErrors: Boolean(cfg.ignoreHTTPSErrors),
    });
    let snapshot;
    try {
      snapshot = await scrapeOpenElections(adminContext, {
        platformUrl: cfg.platformUrl.replace(/\/+$/, ''),
        adminUser: cfg.adminUser,
        adminPassword: cfg.adminPassword,
        loginUrl,
        log: (e) => logger.info(e.message || 'admin', e),
      });
    } finally {
      await adminContext.close().catch(() => {});
    }

    if (!snapshot.rounds.length) {
      logger.warn('Nenhuma rodada aberta encontrada no admin.');
    } else {
      logger.info(`Rodadas abertas: ${snapshot.rounds.length}`, {
        rounds: snapshot.rounds.map((r) => ({
          election_id: r.election_id,
          round_id: r.round_id,
          title: r.election_title,
        })),
      });
    }

    const journeyCache = {
      current: {
        welcome: snapshot.journey.welcome || '',
        booth: snapshot.journey.booth || '',
        thank_you: snapshot.journey.thank_you || '',
        boothCells: [],
      },
    };

    let batchLocale = 'pt_BR';
    if (passwordChangePoc) {
      if (!electors[0]?.user_email) {
        throw new Error('PoC com troca de senha exige user_email no CSV de cada eleitor.');
      }
      // Reset is mandatory for every elector — do not burn time trying WP logins
      // just to discover locale. Mail matching already tries several subjects.
      batchLocale = String(cfg.batchLocale || 'pt_BR').replace('-', '_');
      logger.info('PoC com troca de senha ativo (reset obrigatório por eleitor)', {
        batchLocale,
        mailUrl,
      });
    }

    const state = {
      failureEvents: 0,
      retryAttempts: 0,
      successElectors: 0,
      failedElectors: 0,
      skippedElectors: 0,
      stopped: false,
      stopReason: '',
    };

    const queue = electors.map((e, index) => ({ elector: e, index }));
    let cursor = 0;

    async function nextJob() {
      if (state.stopped) {
        return null;
      }
      if (hooks.signal?.aborted) {
        state.stopped = true;
        state.stopReason = 'abortado';
        return null;
      }
      if (cursor >= queue.length) {
        return null;
      }
      const job = queue[cursor];
      cursor += 1;
      return job;
    }

    /**
     * @returns {Promise<{ ok: boolean, stalled: boolean, error?: Error }>}
     */
    async function processWithRetries(context, job, slot) {
      const { elector } = job;
      let lastError = null;
      let failedAttempts = 0;
      const maxAttempts = Math.max(1, cfg.insistencias);
      let stalled = false;
      const principalId = pool.getPrincipalId?.() ?? slot.id;
      if (visual.enabled) {
        visual.setPrincipal(principalId);
      }
      const isPrincipal = slot.id === principalId;

      for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
        if (state.stopped) {
          return { ok: false, stalled: true, error: lastError || new Error(state.stopReason) };
        }
        try {
          await voteElector(context, {
            elector,
            loginUrl,
            openRounds: snapshot.rounds,
            journeyCache,
            logger,
            passwordChangePoc,
            mailUrl,
            batchLocale,
            passwordStore,
            visual,
            workerId: slot.id,
            isPrincipal,
            onElectorAuthenticated: (userLogin) => {
              pool.armRampUp?.(`login eleitor ${userLogin || elector.user_login}`);
            },
          });
          state.successElectors += 1;
          logger.info('Eleitor concluído', {
            user_login: elector.user_login,
            attempt,
            ...pool.snapshot(),
          });
          return { ok: true, stalled: false };
        } catch (err) {
          lastError = err;
          failedAttempts += 1;
          state.retryAttempts += 1;
          const msg = String(err.message || err);
          if (/timeout|Timeout|ETIMEDOUT|Target closed|crashed|net::ERR/i.test(msg)) {
            stalled = true;
          }
          const failureKind = categorizeFailure(err);
          const tracked = failureTracker.record({
            kind: failureKind,
            error: err,
            user_login: elector.user_login,
            user_email: elector.user_email,
            attempt: failedAttempts,
          });

          // Shed load on every insistência — do not wait until y=3 stops the run.
          const pressure = await pool.reportAttemptFailure?.(err, {
            attempt: failedAttempts,
          });
          logger.warn(
            `Falha (insistência ${failedAttempts}/${cfg.insistencias}; limite y=${cfg.limiteRetentativas})`,
            {
              user_login: elector.user_login,
              user_email: elector.user_email || undefined,
              error: msg,
              failedAttempts,
              failure_kind: failureKind,
              failure_count: tracked?.count,
              shed_dropped: pressure?.dropped,
              shed_remaining: pressure?.remaining,
              backoff_ms: pressure?.backoffMs,
              ...pool.snapshot(),
            }
          );

          if (failedAttempts >= cfg.limiteRetentativas) {
            state.stopped = true;
            state.stopReason =
              `Limite máximo de retentativas (y=${cfg.limiteRetentativas}) atingido ` +
              `para ${elector.user_login}`;
            logger.error(state.stopReason, {
              user_login: elector.user_login,
              user_email: elector.user_email || undefined,
              failedAttempts,
              failure_kind: failureKind,
            });
            return { ok: false, stalled: true, error: lastError };
          }

          const backoffMs = Math.max(0, Number(pressure?.backoffMs) || 0);
          if (backoffMs > 0 && !state.stopped) {
            logger.warn(
              `Backoff ${Math.round(backoffMs / 1000)}s antes da insistência ${failedAttempts + 1} (alívio de carga)`,
              {
                user_login: elector.user_login,
                backoff_ms: backoffMs,
                ...pool.snapshot(),
              }
            );
            await new Promise((r) => setTimeout(r, backoffMs));
          }
        }
      }

      state.failureEvents += 1;
      state.failedElectors += 1;
      logger.error('Eleitor pulado após insistências (registrado)', {
        user_login: elector.user_login,
        user_email: elector.user_email || undefined,
        error: String(lastError?.message || lastError || 'erro desconhecido'),
        failure_kind: categorizeFailure(lastError),
        failureEvents: state.failureEvents,
        tentativas: cfg.tentativas,
      });

      if (state.failureEvents >= cfg.tentativas) {
        state.stopped = true;
        state.stopReason = `Tentativas (x=${cfg.tentativas}) esgotadas`;
      }

      return { ok: false, stalled, error: lastError || undefined };
    }

    await pool.run(async (slot) => {
      if (state.stopped || hooks.signal?.aborted) {
        return 'abort';
      }
      if (slot.stop) {
        return 'idle';
      }
      const job = await nextJob();
      if (!job) {
        return 'idle';
      }
      logger.info(`Worker ${slot.id} → ${job.elector.user_login}`, {
        remaining: queue.length - cursor,
        ...pool.snapshot(),
      });
      const t0 = Date.now();
      const outcome = await processWithRetries(slot.context, job, slot);
      const elapsed = Date.now() - t0;
      // Attempt-level shedding already ran; only nudge if still heavy after skip.
      if (outcome.ok) {
        await pool.reportSuccess(elapsed);
      } else if ((outcome.error || outcome.stalled) && (pool.snapshot()?.workers || 0) > 2) {
        await pool.shedLoad?.('eleitor falhou após insistências', {
          drops: 1,
          freezeMs: 60000,
          pressureMs: 12000,
        });
      }
      return state.stopped ? 'abort' : 'ok';
    });

    if (state.stopped && cursor < queue.length) {
      state.skippedElectors = queue.length - cursor;
      logger.warn(`Execução interrompida: ${state.stopReason}`, {
        skipped: state.skippedElectors,
      });
    }

    let passwordsExport = null;
    if (passwordChangePoc) {
      passwordsExport = passwordStore.exportTo(resultsDir);
      logger.info('Senhas geradas exportadas para o resultado da corrida', {
        path: passwordsExport,
        count: passwordStore.size(),
      });
    }

    const failureReport = failureTracker.exportTo(resultsDir);
    logger.info('Relatórios de falhas repetidas exportados', {
      exportedRows: failureReport.exportedRows,
      minRepeats: failureReport.minRepeats,
      byKind: {
        password_reset: failureReport.files.password_reset?.count,
        email_login: failureReport.files.email_login?.count,
        vote_login: failureReport.files.vote_login?.count,
      },
      files: Object.fromEntries(
        Object.entries(failureReport.files).map(([k, v]) => [k, v.fileName])
      ),
    });

    const summary = {
      resultsDir,
      electorsTotal: electors.length,
      successElectors: state.successElectors,
      failedElectors: state.failedElectors,
      skippedElectors: state.skippedElectors,
      failureEvents: state.failureEvents,
      retryAttempts: state.retryAttempts,
      openRounds: snapshot.rounds.length,
      journey: journeyCache.current,
      passwordChangePoc,
      batchLocale: passwordChangePoc ? batchLocale : null,
      passwordsExport,
      failureReport: {
        minRepeats: failureReport.minRepeats,
        exportedRows: failureReport.exportedRows,
        files: Object.fromEntries(
          Object.entries(failureReport.files).map(([k, v]) => [
            k,
            { fileName: v.fileName, label: v.label, count: v.count },
          ])
        ),
        rows: failureReport.rows,
      },
      concurrency: pool.snapshot(),
      stopReason: state.stopReason || null,
      finishedAt: new Date().toISOString(),
    };
    logger.writeSummary(summary);
    return summary;
  } finally {
    await pool.close();
    caffeinate.stop();
  }
}

function buildLaunchOptions(cfg) {
  const opts = {
    headless: false,
    channel: cfg.channel || 'chrome',
    args: ['--disable-dev-shm-usage'],
  };
  if (cfg.chromePath) {
    opts.executablePath = cfg.chromePath;
    delete opts.channel;
  }
  return opts;
}

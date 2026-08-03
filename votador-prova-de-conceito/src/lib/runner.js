import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { loadElectorsFromCsv } from './csv.js';
import { createRunLogger } from './logger.js';
import { scrapeOpenElections } from './scrapeAdmin.js';
import { resolveLoginUrl } from './urls.js';
import { voteElector } from './voteSession.js';
import { createPasswordStore } from './passwordStore.js';
import { discoverBatchLocale } from './discoverLocale.js';

/** Bumped when PoC runtime behaviour changes — look for this in startup logs. */
export const VOTADOR_BUILD = 'booth-cells-1';

export const DEFAULTS = {
  windows: 5,
  tabsPerWindow: 5,
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
  mailUrl: 'https://relatasoft.com.br/mail/',
};

/**
 * @param {object} config
 * @param {{ onEvent?: Function, signal?: AbortSignal }} [hooks]
 */
export async function runVotador(config, hooks = {}) {
  const cfg = {
    ...DEFAULTS,
    ...config,
    windows: Number(config.windows ?? DEFAULTS.windows),
    tabsPerWindow: Number(config.tabsPerWindow ?? DEFAULTS.tabsPerWindow),
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
  const resultsDir =
    cfg.resultsDir ||
    path.join(path.resolve('results'), stamp);
  const logger = createRunLogger(resultsDir);
  if (hooks.onEvent) {
    logger.on(hooks.onEvent);
  }

  const { electors } = loadElectorsFromCsv(cfg.csvPath);
  const loginUrl = resolveLoginUrl(cfg);
  const concurrency = cfg.windows * cfg.tabsPerWindow;
  const passwordChangePoc = Boolean(cfg.passwordChangePoc);
  const mailUrl = String(cfg.mailUrl || DEFAULTS.mailUrl).trim() || DEFAULTS.mailUrl;
  const passwordStore = createPasswordStore();

  logger.info(`Iniciando Votador PoC [${VOTADOR_BUILD}]`, {
    build: VOTADOR_BUILD,
    electors: electors.length,
    concurrency,
    windows: cfg.windows,
    tabsPerWindow: cfg.tabsPerWindow,
    tentativas: cfg.tentativas,
    insistencias: cfg.insistencias,
    limiteRetentativas: cfg.limiteRetentativas,
    passwordChangePoc,
    mailUrl: passwordChangePoc ? mailUrl : null,
    storedPasswords: passwordStore.size(),
    loginUrl,
    resultsDir,
  });

  const launchOpts = buildLaunchOptions(cfg);
  const browsers = [];
  const workerContexts = [];

  try {
    for (let w = 0; w < cfg.windows; w += 1) {
      const browser = await chromium.launch(launchOpts);
      browsers.push(browser);
      for (let t = 0; t < cfg.tabsPerWindow; t += 1) {
        const context = await browser.newContext({
          ignoreHTTPSErrors: Boolean(cfg.ignoreHTTPSErrors),
          viewport: { width: 1180, height: 820 },
        });
        workerContexts.push(context);
      }
    }

    // Admin scrape in a dedicated context (not counted as a voter slot).
    const adminBrowser = browsers[0];
    const adminContext = await adminBrowser.newContext({
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

    let batchLocale = 'en_US';
    if (passwordChangePoc) {
      if (!electors[0]?.user_email) {
        throw new Error('PoC com troca de senha exige user_email no CSV de cada eleitor.');
      }
      const localeContext = await browsers[0].newContext({
        ignoreHTTPSErrors: Boolean(cfg.ignoreHTTPSErrors),
      });
      try {
        const first = { ...electors[0] };
        const stored = passwordStore.get(first.user_login);
        if (stored?.password) {
          first.password = stored.password;
        }
        batchLocale = await discoverBatchLocale(localeContext, {
          loginUrl,
          elector: first,
          platformUrl: cfg.platformUrl.replace(/\/+$/, ''),
          logger,
        });
      } finally {
        await localeContext.close().catch(() => {});
      }
      logger.info('PoC com troca de senha ativo', { batchLocale, mailUrl });
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
     * Retry semantics (option 2):
     * - Tentativas (x): max electors skipped/logged after exhausting insistências
     * - Insistências (n): attempts per failed elector
     * - Limite máximo de retentativas (y): per-failure ceiling — when this
     *   elector's failed attempts reach y, stop the entire run
     */
    async function processWithRetries(context, job) {
      const { elector } = job;
      let lastError = null;
      let failedAttempts = 0;
      const maxAttempts = Math.max(1, cfg.insistencias);

      for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
        if (state.stopped) {
          return;
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
          });
          state.successElectors += 1;
          logger.info('Eleitor concluído', {
            user_login: elector.user_login,
            attempt,
          });
          return;
        } catch (err) {
          lastError = err;
          failedAttempts += 1;
          state.retryAttempts += 1;
          logger.warn(
            `Falha (insistência ${failedAttempts}/${cfg.insistencias}; limite y=${cfg.limiteRetentativas})`,
            {
              user_login: elector.user_login,
              error: String(err.message || err),
              failedAttempts,
            }
          );

          // y = per-failure ceiling: n == y on this failure → stop the test
          if (failedAttempts >= cfg.limiteRetentativas) {
            state.stopped = true;
            state.stopReason =
              `Limite máximo de retentativas (y=${cfg.limiteRetentativas}) atingido ` +
              `para ${elector.user_login}`;
            logger.error(state.stopReason, {
              user_login: elector.user_login,
              failedAttempts,
            });
            return;
          }
        }
      }

      // Exhausted n without hitting y (only possible when n < y): skip & register
      state.failureEvents += 1;
      state.failedElectors += 1;
      logger.error('Eleitor pulado após insistências (registrado)', {
        user_login: elector.user_login,
        error: String(lastError?.message || lastError || 'erro desconhecido'),
        failureEvents: state.failureEvents,
        tentativas: cfg.tentativas,
      });

      if (state.failureEvents >= cfg.tentativas) {
        state.stopped = true;
        state.stopReason = `Tentativas (x=${cfg.tentativas}) esgotadas`;
      }
    }

    async function workerLoop(context, workerId) {
      while (!state.stopped) {
        const job = await nextJob();
        if (!job) {
          break;
        }
        logger.info(`Worker ${workerId} → ${job.elector.user_login}`, {
          remaining: queue.length - cursor,
        });
        await processWithRetries(context, job);
      }
    }

    await Promise.all(
      workerContexts.map((ctx, i) => workerLoop(ctx, i + 1))
    );

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
      stopReason: state.stopReason || null,
      finishedAt: new Date().toISOString(),
    };
    logger.writeSummary(summary);
    return summary;
  } finally {
    for (const ctx of workerContexts) {
      await ctx.close().catch(() => {});
    }
    for (const browser of browsers) {
      await browser.close().catch(() => {});
    }
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

/**
 * Adaptive Chrome concurrency pool.
 *
 * Starts at windowsInitial × tabsInitial, ramps toward windowsMax × tabsMax
 * while work is healthy, and eases back when stalls/timeouts appear.
 */

function clamp(n, min, max) {
  return Math.max(min, Math.min(max, n));
}

function nowMs() {
  return Date.now();
}

/**
 * @param {object} opts
 * @param {import('playwright').Chromium} opts.chromium
 * @param {object} opts.launchOpts
 * @param {boolean} [opts.ignoreHTTPSErrors]
 * @param {number} opts.windowsInitial
 * @param {number} opts.windowsMax
 * @param {number} opts.tabsInitial
 * @param {number} opts.tabsMax
 * @param {{ info?: Function, warn?: Function }} [opts.logger]
 * @param {number} [opts.healthyDurationMs]
 * @param {number} [opts.slowDurationMs]
 * @param {number} [opts.scaleUpEveryMs]
 * @param {number} [opts.scaleDownEveryMs]
 * @param {number} [opts.healthySuccessesNeeded]
 */
export function createAdaptivePool({
  chromium,
  launchOpts,
  ignoreHTTPSErrors = false,
  windowsInitial,
  windowsMax,
  tabsInitial,
  tabsMax,
  adaptive = true,
  logger,
  healthyDurationMs = 45000,
  slowDurationMs = 90000,
  scaleUpEveryMs = 10000,
  scaleDownEveryMs = 6000,
  healthySuccessesNeeded = 2,
}) {
  const winInit = clamp(Number(windowsInitial) || 1, 1, 20);
  const winMax = clamp(Number(windowsMax) || winInit, winInit, 20);
  const tabInit = clamp(Number(tabsInitial) || 1, 1, 20);
  const tabMax = clamp(Number(tabsMax) || tabInit, tabInit, 20);

  /** @type {{ browser: any, closing: boolean }[]} */
  const windows = [];
  /** @type {{ id: number, windowIndex: number, context: any, stop: boolean, closed: boolean }[]} */
  const slots = [];
  let nextSlotId = 1;
  let started = false;
  let closed = false;
  let consecutiveHealthy = 0;
  let consecutiveFailures = 0;
  let lastScaleUpAt = 0;
  let lastScaleDownAt = 0;
  let lastProgressAt = nowMs();
  let scaleChain = Promise.resolve();

  function logInfo(message, extra = {}) {
    logger?.info?.(message, { ...snapshot(), ...extra });
  }

  function logWarn(message, extra = {}) {
    logger?.warn?.(message, { ...snapshot(), ...extra });
  }

  function livingSlots() {
    return slots.filter((s) => !s.stop && !s.closed && s.context);
  }

  function openWindows() {
    return windows.filter((w) => !w.closing && w.browser);
  }

  function tabsPerOpenWindow() {
    const open = openWindows();
    if (!open.length) return tabInit;
    const counts = open.map((w) => {
      const wi = windows.indexOf(w);
      return slots.filter((s) => s.windowIndex === wi && !s.closed && s.context).length;
    });
    return Math.max(1, ...counts);
  }

  function snapshot() {
    const open = openWindows();
    const living = livingSlots();
    return {
      windows: open.length,
      tabsPerWindow: tabsPerOpenWindow(),
      workers: living.length,
      windowsInitial: winInit,
      windowsMax: winMax,
      tabsInitial: tabInit,
      tabsMax: tabMax,
      consecutiveHealthy,
      consecutiveFailures,
    };
  }

  async function launchBrowser() {
    return chromium.launch({ ...launchOpts });
  }

  async function createContext(browser) {
    return browser.newContext({
      ignoreHTTPSErrors: Boolean(ignoreHTTPSErrors),
      viewport: { width: 1180, height: 820 },
    });
  }

  async function addWindow() {
    const browser = await launchBrowser();
    const entry = { browser, closing: false };
    windows.push(entry);
    return windows.length - 1;
  }

  async function addSlot(windowIndex) {
    const win = windows[windowIndex];
    if (!win || win.closing || !win.browser) return null;
    const context = await createContext(win.browser);
    const slot = {
      id: nextSlotId++,
      windowIndex,
      context,
      stop: false,
      closed: false,
    };
    slots.push(slot);
    return slot;
  }

  async function start() {
    if (started) return;
    started = true;
    const first = await addWindow();
    const tabs = Math.min(tabInit, tabMax);
    for (let t = 0; t < tabs; t += 1) {
      await addSlot(first);
    }
    for (let w = 1; w < winInit; w += 1) {
      const wi = await addWindow();
      for (let t = 0; t < tabs; t += 1) {
        await addSlot(wi);
      }
    }
    lastProgressAt = nowMs();
    logInfo(
      `Início adaptativo: ${openWindows().length} janela(s), ${livingSlots().length} contexto(s)` +
        ` (máx ${winMax}×${tabMax})`
    );
  }

  function getPrimaryBrowser() {
    return openWindows()[0]?.browser || null;
  }

  function queueScale(fn) {
    scaleChain = scaleChain.then(fn).catch((err) => {
      logWarn('Ajuste de concorrência falhou', { error: String(err?.message || err) });
    });
    return scaleChain;
  }

  async function scaleUp(reason) {
    if (!adaptive || closed) return false;
    if (nowMs() - lastScaleUpAt < scaleUpEveryMs) return false;

    const open = openWindows();
    if (!open.length) return false;

    const counts = open.map((w, i) => {
      const realIndex = windows.indexOf(w);
      return {
        windowIndex: realIndex,
        count: slots.filter((s) => s.windowIndex === realIndex && !s.closed && s.context).length,
      };
    });
    const minTabs = Math.min(...counts.map((c) => c.count));
    const maxTabs = Math.max(...counts.map((c) => c.count));

    if (minTabs < tabMax) {
      const target = counts.find((c) => c.count === minTabs);
      const slot = await addSlot(target.windowIndex);
      if (slot) {
        lastScaleUpAt = nowMs();
        consecutiveHealthy = 0;
        logInfo(`Acelerando: +1 contexto (janela ${target.windowIndex + 1}) — ${reason}`);
        return slot;
      }
    }

    if (open.length < winMax) {
      const wi = await addWindow();
      const tabsForNew = clamp(maxTabs || tabInit, 1, tabMax);
      let last = null;
      for (let t = 0; t < tabsForNew; t += 1) {
        last = await addSlot(wi);
      }
      lastScaleUpAt = nowMs();
      consecutiveHealthy = 0;
      logInfo(`Acelerando: +1 janela Chrome (${tabsForNew} contexto(s)) — ${reason}`);
      return last;
    }

    return false;
  }

  async function scaleDown(reason) {
    if (!adaptive || closed) return false;
    if (nowMs() - lastScaleDownAt < scaleDownEveryMs) return false;

    const living = livingSlots();
    if (living.length <= 1) return false;

    const byWindow = new Map();
    for (const s of living) {
      const list = byWindow.get(s.windowIndex) || [];
      list.push(s);
      byWindow.set(s.windowIndex, list);
    }

    let victim = null;
    let richest = -1;
    let richestWin = -1;
    for (const [wi, list] of byWindow.entries()) {
      if (list.length > richest) {
        richest = list.length;
        richestWin = wi;
        victim = list[list.length - 1];
      }
    }
    if (!victim) return false;

    victim.stop = true;
    lastScaleDownAt = nowMs();
    consecutiveFailures = 0;

    if (richest <= 1 && byWindow.size > 1) {
      logWarn(`Desacelerando: removendo janela ${richestWin + 1} após job atual — ${reason}`);
    } else {
      logWarn(`Desacelerando: -1 contexto (janela ${richestWin + 1}) após job atual — ${reason}`);
    }
    return true;
  }

  async function retireSlot(slot) {
    if (slot.closed) return;
    slot.closed = true;
    slot.stop = true;
    try {
      await slot.context?.close?.();
    } catch {
      // ignore
    }
    slot.context = null;

    const win = windows[slot.windowIndex];
    if (!win || win.closing) return;
    const still = slots.some(
      (s) => s.windowIndex === slot.windowIndex && !s.closed && s.context
    );
    if (!still) {
      win.closing = true;
      try {
        await win.browser?.close?.();
      } catch {
        // ignore
      }
      win.browser = null;
    }
  }

  async function reportSuccess(elapsedMs = 0) {
    lastProgressAt = nowMs();
    consecutiveFailures = 0;
    if (elapsedMs > 0 && elapsedMs <= healthyDurationMs) {
      consecutiveHealthy += 1;
    } else if (elapsedMs >= slowDurationMs) {
      consecutiveHealthy = 0;
      consecutiveFailures += 1;
      await queueScale(() => scaleDown('job lento'));
      return;
    } else {
      consecutiveHealthy = Math.max(0, consecutiveHealthy - 1);
    }

    if (consecutiveHealthy >= healthySuccessesNeeded) {
      await queueScale(() => scaleUp('sucessos saudáveis'));
    }
  }

  async function reportFailure(error, elapsedMs = 0) {
    consecutiveHealthy = 0;
    consecutiveFailures += 1;
    const msg = String(error?.message || error || '');
    const stalled = /timeout|Timeout|ETIMEDOUT|Target closed|crashed|net::ERR|stall/i.test(msg);
    if (stalled) consecutiveFailures += 1;
    lastProgressAt = nowMs();

    const reason = stalled
      ? 'timeout/travamento'
      : elapsedMs >= slowDurationMs
        ? 'falha lenta'
        : 'falha';
    await queueScale(() => scaleDown(reason));
  }

  /**
   * @param {(slot: { id: number, context: any, stop: boolean, windowIndex: number }) => Promise<'ok'|'idle'|'abort'>} workOnce
   */
  async function run(workOnce) {
    if (!started) await start();

    /** @type {Map<number, Promise<void>>} */
    const running = new Map();
    let abortAll = false;

    async function runSlot(slot) {
      try {
        while (!closed && !abortAll && !slot.stop && slot.context) {
          let result = 'idle';
          try {
            result = await workOnce(slot);
          } catch (error) {
            await reportFailure(error);
            result = 'ok';
          }
          if (result === 'abort') {
            abortAll = true;
            break;
          }
          if (result === 'idle' || slot.stop) {
            break;
          }
        }
      } finally {
        await retireSlot(slot);
        running.delete(slot.id);
      }
    }

    function ensureWorkers() {
      for (const slot of livingSlots()) {
        if (!running.has(slot.id) && !abortAll && !closed) {
          running.set(slot.id, runSlot(slot));
        }
      }
    }

    ensureWorkers();

    while (!closed && !abortAll) {
      ensureWorkers();

      if (running.size === 0) {
        // Scale-up may have added slots; otherwise we're done.
        if (livingSlots().length === 0) break;
        ensureWorkers();
        if (running.size === 0) break;
      }

      if (adaptive && nowMs() - lastProgressAt > slowDurationMs && livingSlots().length > 1) {
        await queueScale(() => scaleDown('watchdog sem progresso'));
        lastProgressAt = nowMs();
      }

      await Promise.race([
        ...running.values(),
        new Promise((r) => setTimeout(r, 500)),
      ]);
    }

    // Mark remaining slots to stop and wait
    for (const slot of livingSlots()) {
      slot.stop = true;
    }
    await Promise.allSettled([...running.values()]);
    await scaleChain;
  }

  async function close() {
    closed = true;
    for (const slot of slots) {
      slot.stop = true;
      if (!slot.closed) {
        try {
          await slot.context?.close?.();
        } catch {
          // ignore
        }
        slot.context = null;
        slot.closed = true;
      }
    }
    for (const win of windows) {
      if (!win.closing) {
        win.closing = true;
        try {
          await win.browser?.close?.();
        } catch {
          // ignore
        }
        win.browser = null;
      }
    }
  }

  return {
    start,
    getPrimaryBrowser,
    run,
    reportSuccess,
    reportFailure,
    snapshot,
    close,
    /** Lowest living slot id — stable "principal" worker for visual focus. */
    getPrincipalId() {
      const living = livingSlots();
      if (!living.length) return null;
      return Math.min(...living.map((s) => s.id));
    },
  };
}

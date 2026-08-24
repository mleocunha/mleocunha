/**
 * Visual highlight for headed PoC runs.
 *
 * - Overlay banner on every worker page (all tabs)
 * - Stronger style + page.bringToFront() only for the principal worker
 * - No OS-level window activation (macOS focus stays with the user)
 */

/**
 * @param {{ enabled?: boolean, focusEveryMs?: number }} [opts]
 */
export function createVisualDirector(opts = {}) {
  const enabled = Boolean(opts.enabled);
  const focusEveryMs = Math.max(500, Number(opts.focusEveryMs) || 1500);

  if (!enabled) {
    return {
      enabled: false,
      setPrincipal() {},
      bindContext() {
        return () => {};
      },
      async mark() {},
      async focus() {},
    };
  }

  let principalId = 1;
  let lastFocusAt = 0;
  /** @type {WeakMap<import('playwright').Page, { workerId: number, label: string, userLogin?: string }>} */
  const pageMeta = new WeakMap();

  function setPrincipal(workerId) {
    const id = Number(workerId) || 1;
    if (id === principalId) return;
    principalId = id;
  }

  /**
   * @param {import('playwright').Page} page
   * @param {{ workerId: number, label?: string, userLogin?: string, step?: string }} meta
   */
  async function paint(page, meta) {
    if (!page || page.isClosed?.()) return;
    const payload = {
      workerId: meta.workerId,
      label: meta.label || `Worker ${meta.workerId}`,
      userLogin: meta.userLogin || '',
      step: meta.step || '',
      principal: meta.workerId === principalId,
    };
    try {
      await page.evaluate((data) => {
        const ID = 'votador-visual-highlight';
        let el = document.getElementById(ID);
        if (!el) {
          el = document.createElement('div');
          el.id = ID;
          el.setAttribute('data-votador-visual', '1');
          document.documentElement.appendChild(el);
        }
        el.style.cssText = [
          'position:fixed',
          'top:0',
          'left:0',
          'right:0',
          'z-index:2147483646',
          'pointer-events:none',
          'font:600 13px/1.35 "IBM Plex Sans",system-ui,sans-serif',
          'padding:8px 12px',
          'letter-spacing:0.01em',
          data.principal
            ? 'background:rgba(11,110,79,0.92);color:#fff;box-shadow:0 2px 10px rgba(0,0,0,0.25);border-bottom:3px solid #f4d35e'
            : 'background:rgba(20,33,43,0.72);color:#e8eef2;border-bottom:2px solid rgba(255,255,255,0.25)',
        ].join(';');
        const bits = [
          data.principal ? '★ PRINCIPAL' : 'worker',
          data.label,
          data.userLogin ? `· ${data.userLogin}` : '',
          data.step ? `· ${data.step}` : '',
        ].filter(Boolean);
        el.textContent = bits.join(' ');
        document.documentElement.style.setProperty(
          'outline',
          data.principal ? '3px solid #0b6e4f' : '2px solid rgba(20,33,43,0.35)'
        );
        document.documentElement.style.setProperty('outline-offset', '-3px');
      }, payload);
    } catch {
      // Cross-origin / closed page — ignore
    }
  }

  /**
   * Track all pages in a context (including SnappyMail tabs opened later).
   * @param {import('playwright').BrowserContext} context
   * @param {{ workerId: number, label?: string, userLogin?: string }} meta
   */
  function bindContext(context, meta) {
    const base = {
      workerId: meta.workerId,
      label: meta.label || `Worker ${meta.workerId}`,
      userLogin: meta.userLogin || '',
      step: '',
    };

    const onPage = (page) => {
      pageMeta.set(page, { ...base });
      const refresh = () => {
        const m = pageMeta.get(page) || base;
        paint(page, m);
      };
      page.on('framenavigated', refresh);
      page.on('load', refresh);
      // Paint only — do not auto bringToFront. SnappyMail tabs must not cover
      // the voting site while lostpassword is being shown to the examiner.
      refresh();
    };

    context.on('page', onPage);
    for (const page of context.pages()) {
      onPage(page);
    }

    return () => {
      try {
        context.off('page', onPage);
      } catch {
        // ignore
      }
    };
  }

  /**
   * Update banner text and optionally focus principal tab.
   * @param {import('playwright').Page|null|undefined} page
   * @param {{ step?: string, userLogin?: string }} [extra]
   */
  async function mark(page, extra = {}) {
    if (!page || page.isClosed?.()) return;
    const prev = pageMeta.get(page) || { workerId: principalId, label: 'Worker', userLogin: '' };
    const next = {
      ...prev,
      step: extra.step != null ? extra.step : prev.step,
      userLogin: extra.userLogin != null ? extra.userLogin : prev.userLogin,
    };
    pageMeta.set(page, next);
    await paint(page, next);
    if (next.workerId === principalId) {
      await focus(page);
    }
  }

  /**
   * bringToFront for principal only, throttled (unless force).
   * @param {import('playwright').Page|null|undefined} page
   * @param {{ force?: boolean }} [opts]
   */
  async function focus(page, opts = {}) {
    if (!page || page.isClosed?.()) return;
    const meta = pageMeta.get(page);
    if (meta && meta.workerId !== principalId) return;
    const force = Boolean(opts.force);
    const now = Date.now();
    if (!force && now - lastFocusAt < focusEveryMs) return;
    lastFocusAt = now;
    try {
      await page.bringToFront();
    } catch {
      // ignore
    }
  }

  return {
    enabled: true,
    setPrincipal,
    bindContext,
    mark,
    focus,
    getPrincipalId: () => principalId,
  };
}

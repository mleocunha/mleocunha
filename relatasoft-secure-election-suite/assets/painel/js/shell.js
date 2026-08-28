(function () {
  function buildSidebar(cfg) {
    if (document.getElementById('ve-painel-sidebar')) return;

    const aside = document.createElement('aside');
    aside.id = 've-painel-sidebar';
    aside.className = 've-painel-sidebar';
    aside.setAttribute('aria-label', cfg.panelName || 'Painel');

    const brand = document.createElement('div');
    brand.className = 've-painel-brand';
    const markUrl = cfg.markUrl || '';
    brand.innerHTML =
      (markUrl
        ? '<img class="ve-painel-brand-mark" src="" alt="" width="44" height="44" decoding="async"/>'
        : '<div class="ve-painel-brand-mark" aria-hidden="true"></div>') +
      '<div class="ve-painel-brand-text">' +
      '<div class="ve-painel-brand-product"></div>' +
      '</div>';
    if (markUrl) {
      const img = brand.querySelector('img.ve-painel-brand-mark');
      img.src = markUrl;
      img.alt = 'RelataSoft';
    }
    brand.querySelector('.ve-painel-brand-product').textContent =
      cfg.productName || 'Voto Eletrônico by RelataSoft';
    aside.appendChild(brand);

    const nav = document.createElement('nav');
    nav.className = 've-painel-nav';
    nav.setAttribute('aria-label', 'Navegação do painel');
    const page = new URLSearchParams(window.location.search).get('page') || '';
    (cfg.nav || []).forEach(function (item) {
      const a = document.createElement('a');
      a.href = item.url;
      a.textContent = item.title;
      if (item.parentId && item.parentId !== 'home') a.classList.add('is-child');
      if (item.slug && item.slug === page) a.classList.add('is-active');
      nav.appendChild(a);
    });
    // Mark parent when a child page is active.
    const activeChild = (cfg.nav || []).find(function (item) {
      return item.slug === page && item.parentId && item.parentId !== 'home';
    });
    if (activeChild) {
      Array.prototype.forEach.call(nav.querySelectorAll('a'), function (a, idx) {
        const item = (cfg.nav || [])[idx];
        if (item && item.id === activeChild.parentId) a.classList.add('is-active-parent');
      });
    }
    aside.appendChild(nav);
    document.body.appendChild(aside);
  }

  function buildTopbar(cfg) {
    const wpbody = document.getElementById('wpbody-content');
    if (!wpbody || document.getElementById('ve-painel-topbar')) return;
    const bar = document.createElement('div');
    bar.id = 've-painel-topbar';
    bar.className = 've-painel-topbar';
    bar.innerHTML =
      '<div class="ve-painel-topbar-title"></div>' +
      '<div class="ve-painel-topbar-meta"><span class="ve-painel-persona"></span></div>';
    bar.querySelector('.ve-painel-topbar-title').textContent =
      cfg.panelName || 'Painel de Controle Eleitoral';
    bar.querySelector('.ve-painel-persona').textContent = cfg.personaLabel || '';
    wpbody.insertBefore(bar, wpbody.firstChild);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('ve-painel-active')) return;
    const cfg = window.vePainel || {};
    buildSidebar(cfg);
    buildTopbar(cfg);
  });
})();

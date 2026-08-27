(function () {
  function buildSidebar(cfg) {
    if (document.getElementById('ve-painel-sidebar')) return;

    const aside = document.createElement('aside');
    aside.id = 've-painel-sidebar';
    aside.className = 've-painel-sidebar';
    aside.setAttribute('aria-label', cfg.panelName || 'Painel');

    const brand = document.createElement('div');
    brand.className = 've-painel-brand';
    brand.innerHTML =
      '<div class="ve-painel-brand-mark" aria-hidden="true"></div>' +
      '<div class="ve-painel-brand-text">' +
      '<div class="ve-painel-brand-product"></div>' +
      '</div>';
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

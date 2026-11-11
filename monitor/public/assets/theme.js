'use strict';
(() => {
  const root = document.documentElement;
  let selected = null;
  try {
    const saved = localStorage.getItem('listenlab-theme');
    if (saved === 'dark' || saved === 'light') selected = saved;
  } catch { /* Theme switching also works when browser storage is unavailable. */ }

  function apply(theme) {
    root.dataset.theme = theme;
    const button = document.getElementById('theme-toggle');
    if (!button) return;
    const dark = theme === 'dark';
    const label = dark ? 'Tema claro' : 'Tema escuro';
    button.setAttribute('aria-pressed', String(dark));
    button.setAttribute('aria-label', 'Alternar tema');
    button.title = label;
    document.getElementById('theme-icon').textContent = dark ? '☀' : '☾';
    document.getElementById('theme-label').textContent = label;
  }

  apply(selected ?? 'dark');
  document.addEventListener('DOMContentLoaded', () => {
    apply(root.dataset.theme);
    document.getElementById('theme-toggle')?.addEventListener('click', () => {
      selected = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('listenlab-theme', selected); } catch { /* Keep the choice for this page. */ }
      apply(selected);
    });
  });
  window.addEventListener('storage', event => {
    if (event.key !== 'listenlab-theme' && event.key !== null) return;
    selected = event.newValue === 'dark' || event.newValue === 'light' ? event.newValue : null;
    apply(selected ?? 'dark');
  });
})();

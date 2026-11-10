'use strict';
(() => {
  const root = document.documentElement;
  const preference = window.matchMedia('(prefers-color-scheme: dark)');
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
    const label = dark ? 'Modo claro' : 'Modo escuro';
    button.setAttribute('aria-pressed', String(dark));
    button.setAttribute('aria-label', 'Modo escuro');
    button.title = label;
    document.getElementById('theme-icon').textContent = dark ? '☀' : '☾';
    document.getElementById('theme-label').textContent = label;
  }

  apply(selected ?? (preference.matches ? 'dark' : 'light'));
  document.addEventListener('DOMContentLoaded', () => {
    apply(root.dataset.theme);
    document.getElementById('theme-toggle')?.addEventListener('click', () => {
      selected = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('listenlab-theme', selected); } catch { /* Keep the choice for this page. */ }
      apply(selected);
    });
  });
  preference.addEventListener('change', event => {
    if (selected === null) apply(event.matches ? 'dark' : 'light');
  });
  window.addEventListener('storage', event => {
    if (event.key !== 'listenlab-theme' && event.key !== null) return;
    selected = event.newValue === 'dark' || event.newValue === 'light' ? event.newValue : null;
    apply(selected ?? (preference.matches ? 'dark' : 'light'));
  });
})();

'use strict';
(() => {
  const search = document.getElementById('test-search');
  const type = document.getElementById('test-type');
  const container = document.getElementById('test-groups');
  const groups = [...container.querySelectorAll('.test-group')];
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
  const entries = groups.map(group => ({
    group,
    context: normalize(group.querySelector('summary').textContent + ' ' + group.querySelector('.test-source').textContent),
    tests: [...group.querySelectorAll('.test-case')].map(row => ({row, text: normalize(row.textContent)})),
  }));
  function filter() {
    const query = normalize(search.value.trim());
    let count = 0;
    for (const {group, context, tests} of entries) {
      const matchesType = type.value === 'all' || type.value === group.dataset.type;
      let visible = 0;
      for (const {row, text} of tests) {
        row.hidden = !matchesType || !(context + ' ' + text).includes(query);
        if (!row.hidden) visible += Number(row.dataset.cases);
      }
      group.hidden = visible === 0;
      const total = tests.reduce((sum, {row}) => sum + Number(row.dataset.cases), 0);
      group.querySelector('.test-group-count').textContent = visible === total ? `${total} cenários` : `${visible} de ${total} cenários`;
      if (query && visible) group.open = true;
      count += visible;
    }
    document.getElementById('test-results').textContent = `${count} de ${container.dataset.total} cenários`;
    document.getElementById('test-empty').hidden = count !== 0;
  }
  document.getElementById('test-controls').hidden = false;
  search.addEventListener('input', filter);
  type.addEventListener('change', filter);
  for (const button of document.querySelectorAll('[data-copy]')) {
    button.hidden = false;
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copiado';
        setTimeout(() => { button.textContent = 'Copiar comando'; }, 2000);
      } catch { button.textContent = 'Selecione o comando acima'; }
    });
  }
  filter();
})();

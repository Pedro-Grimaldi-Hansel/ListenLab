'use strict';

const $ = id => document.getElementById(id);
const number = value => new Intl.NumberFormat('pt-BR').format(value);
const reproductions = value => `${number(value)} ${value === 1 ? 'reprodução' : 'reproduções'}`;
const percent = value => `${new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 1}).format(value)}%`;
const text = (id, value) => { $(id).textContent = value; };
const node = (tag, cls = '', value = '') => Object.assign(document.createElement(tag), {className: cls, textContent: value});
const weekdayNames = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
let timezone = 'America/Sao_Paulo';
let busy = false;
let hasData = false;

function date(value) {
  return new Intl.DateTimeFormat('pt-BR', {timeZone: timezone, dateStyle: 'short', timeStyle: 'short'}).format(new Date(value));
}
function calendar(value) { return value.split('-').reverse().join('/'); }
function name(value, fallback) { return typeof value === 'string' && value.trim() ? value : fallback; }

function renderRankings(data) {
  for (const type of ['tracks', 'artists', 'albums']) {
    const list = $(`ranking-${type}`);
    list.replaceChildren();
    const items = data.rankings[type];
    text(`${type}-count`, `${number(items.length)} de ${number(data.summary[type])} ${type === 'tracks' ? 'faixas' : type === 'artists' ? 'artistas' : 'álbuns'}`);
    if (!items.length) list.append(node('li', 'ranking-empty', 'Nenhuma reprodução identificada neste período.'));
    for (const item of items) {
      const row = node('li', 'ranking-row');
      const identity = node('div', 'ranking-identity');
      const title = node('strong', '', name(item.name, type === 'tracks' ? 'Faixa sem nome' : type === 'artists' ? 'Artista sem nome' : 'Álbum sem nome'));
      title.title = `${title.textContent} · catálogo #${item.id}`;
      identity.append(title);
      if (type === 'tracks') {
        identity.append(node('span', '', `${name(item.artists, 'Artista não informado')} · ${name(item.album, 'Álbum não informado')}`));
      }
      const count = node('strong', 'ranking-value', number(item.plays));
      count.setAttribute('aria-label', reproductions(item.plays));
      count.title = `${reproductions(item.plays)} · ${percent(item.share_pct)} do total do período`;
      const share = node('div', 'ranking-share');
      const progress = node('progress', 'ranking-progress');
      progress.max = 100; progress.value = item.share_pct;
      progress.setAttribute('aria-hidden', 'true');
      share.append(node('span', '', percent(item.share_pct)), progress);
      row.append(node('span', 'ranking-position', String(item.rank).padStart(2, '0')), identity, count, share);
      list.append(row);
    }
  }
  const missingAlbum = data.summary.plays_without_album;
  text('albums-note', missingAlbum ? `${reproductions(missingAlbum)} sem álbum ${missingAlbum === 1 ? 'fica' : 'ficam'} fora deste ranking.` : 'Reproduções agrupadas por identidade do álbum.');
  const missingArtist = data.summary.plays_without_artist;
  text('catalog-note', `Empates: nome e ID do catálogo.${missingArtist ? ` ${reproductions(missingArtist)} sem artista ${missingArtist === 1 ? 'fica' : 'ficam'} fora do ranking de artistas.` : ''}`);
}

function renderColumns(id, items, label, description) {
  const list = $(id); list.replaceChildren();
  if (!items.length) { list.append(node('li', 'chart-empty', 'Ainda não há eventos para montar a série.')); return; }
  list.style.gridTemplateColumns = `repeat(${items.length}, minmax(${id === 'daily-chart' ? '36px' : '0'}, 1fr))`;
  const max = Math.max(1, ...items.map(item => item.plays));
  for (const item of items) {
    const row = node('li', 'chart-column');
    const accessible = description(item);
    row.setAttribute('aria-label', accessible); row.title = accessible;
    const track = node('div', 'column-track');
    const fill = node('div', 'column-fill');
    fill.style.height = `${item.plays / max * 100}%`;
    track.append(fill);
    const value = node('span', 'column-value', number(item.plays));
    const caption = node('span', 'column-label', label(item));
    for (const child of [value, track, caption]) child.setAttribute('aria-hidden', 'true');
    row.append(value, track, caption); list.append(row);
  }
}

function renderDistributions(data) {
  renderColumns('hour-chart', data.distribution.hours, item => item.bucket % 6 === 0 ? `${String(item.bucket).padStart(2, '0')}h` : '', item => `${item.bucket}h: ${reproductions(item.plays)}, ${percent(item.share_pct)} do período`);
  const values = $('hour-values'); values.replaceChildren();
  for (const item of data.distribution.hours) {
    const row = node('tr');
    row.append(node('td', '', `${String(item.bucket).padStart(2, '0')}h`), node('td', 'numeric', number(item.plays)), node('td', 'numeric', percent(item.share_pct)));
    values.append(row);
  }
  const days = $('weekday-chart'); days.replaceChildren();
  const max = Math.max(1, ...data.distribution.weekdays.map(item => item.plays));
  for (const item of data.distribution.weekdays) {
    const row = node('li', 'weekday-row');
    const meter = node('progress'); meter.max = max; meter.value = item.plays; meter.setAttribute('aria-hidden', 'true');
    const count = node('strong', '', number(item.plays));
    const share = node('span', 'weekday-share', percent(item.share_pct));
    row.append(node('span', '', weekdayNames[item.bucket - 1]), meter, count, share);
    days.append(row);
  }
  const daily = data.distribution.daily;
  renderColumns('daily-chart', daily.items, item => calendar(item.date).slice(0, 5), item => `${calendar(item.date)}: ${reproductions(item.plays)}`);
  text('daily-range', daily.from ? `${calendar(daily.from)} a ${calendar(daily.to)}${daily.truncated ? ' · últimos 90 dias do recorte' : ''}` : 'A série começa com o primeiro evento registrado.');
  text('daily-note', `${daily.truncated ? 'Gráfico limitado aos últimos 90 dias. Rankings e demais distribuições usam o período completo. ' : ''}Dias sem eventos aparecem com zero.`);
}

function render(data) {
  timezone = data.timezone;
  for (const metric of ['plays', 'tracks', 'artists', 'albums']) text(`stat-${metric}`, number(data.summary[metric]));
  const labels = {all: 'Todo o histórico coletado', '7d': 'Últimos 7 dias', '30d': 'Últimos 30 dias', '90d': 'Últimos 90 dias', custom: 'Período personalizado'};
  const bounds = data.period.from ? ` · ${calendar(data.period.from)} a ${calendar(data.period.to)}` : '';
  text('selection-label', `${labels[data.period.name]}${bounds}`);
  text('selection-range', data.summary.first_play_at ? `Registros de ${date(data.summary.first_play_at)} a ${date(data.summary.last_play_at)}.` : 'Nenhuma reprodução neste período.');
  text('dataset-range', data.dataset.first_play_at ? `Base completa: ${number(data.dataset.plays)} eventos, de ${date(data.dataset.first_play_at)} a ${date(data.dataset.last_play_at)}.` : 'O banco ainda não possui reproduções registradas.');
  text('distribution-timezone', `Fuso: ${timezone}. O dia atual pode estar incompleto.`);
  text('ranking-size', `Top ${data.period.limit}`);
  renderRankings(data); renderDistributions(data);
  const updated = new Intl.DateTimeFormat('pt-BR', {timeZone: timezone, hour: '2-digit', minute: '2-digit'}).format(new Date(data.server_time));
  text('analytics-updated', `Atualizado às ${updated} · ${timezone === 'America/Sao_Paulo' ? 'Brasília' : timezone}`);
}

function toggleDates() {
  const custom = $('period').value === 'custom';
  $('custom-dates').hidden = !custom;
  $('apply-filters').hidden = !custom;
  for (const id of ['date-from', 'date-to']) { $(id).required = custom; $(id).disabled = !custom; }
}

async function refresh(event) {
  event?.preventDefault();
  if (busy) return;
  const form = $('analytics-filters');
  if (!form.reportValidity()) return;
  const query = new URLSearchParams(new FormData(form));
  busy = true;
  for (const control of form.elements) control.disabled = true;
  $('refresh-button').disabled = true;
  $('analytics-content').setAttribute('aria-busy', 'true');
  text('analytics-updated', 'Consultando o banco…');
  try {
    const response = await fetch(`/api/analytics?${query}`, {cache: 'no-store', signal: AbortSignal.timeout(15000)});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Não foi possível consultar as análises.');
    render(data); hasData = true; $('analytics-error').hidden = true;
  } catch (error) {
    $('analytics-error').hidden = false;
    text('analytics-error', error.name === 'TimeoutError' ? 'A consulta demorou mais que o esperado. Tente atualizar novamente.' : error.message);
    text('analytics-updated', hasData ? 'Falha na atualização · exibindo o último recorte carregado.' : 'Sem dados carregados · tente atualizar.');
    if (!hasData) {
      text('selection-label', 'Consulta indisponível');
      text('selection-range', 'As análises serão exibidas quando a conexão for restabelecida.');
      text('dataset-range', 'O alcance do histórico ainda não pôde ser consultado.');
      for (const type of ['tracks', 'artists', 'albums']) $(`ranking-${type}`).replaceChildren(node('li', 'ranking-empty', 'Aguardando conexão com o banco.'));
      text('daily-range', 'Aguardando conexão com o banco.');
    }
  } finally {
    busy = false;
    for (const control of form.elements) control.disabled = false;
    $('refresh-button').disabled = false;
    $('analytics-content').setAttribute('aria-busy', 'false');
    toggleDates();
  }
}

function selectRanking(tab) {
  for (const button of document.querySelectorAll('.ranking-tabs [role="tab"]')) {
    const selected = button === tab;
    button.setAttribute('aria-selected', String(selected));
    button.tabIndex = selected ? 0 : -1;
    $(button.getAttribute('aria-controls')).hidden = !selected;
  }
}
const rankingTabs = [...document.querySelectorAll('.ranking-tabs [role="tab"]')];
for (const tab of rankingTabs) {
  tab.addEventListener('click', () => selectRanking(tab));
  tab.addEventListener('keydown', event => {
    const index = rankingTabs.indexOf(tab);
    const next = event.key === 'ArrowRight' ? (index + 1) % rankingTabs.length
      : event.key === 'ArrowLeft' ? (index + rankingTabs.length - 1) % rankingTabs.length
      : event.key === 'Home' ? 0 : event.key === 'End' ? rankingTabs.length - 1 : null;
    if (next === null) return;
    event.preventDefault();
    selectRanking(rankingTabs[next]);
    rankingTabs[next].focus();
  });
}
$('period').addEventListener('change', () => {
  toggleDates();
  if ($('period').value !== 'custom') refresh();
});
$('ranking-limit').addEventListener('change', () => { if ($('analytics-filters').checkValidity()) refresh(); });
$('analytics-filters').addEventListener('submit', refresh);
toggleDates();
refresh();

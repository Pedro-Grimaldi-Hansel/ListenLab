'use strict';

const $ = id => document.getElementById(id);
const number = value => new Intl.NumberFormat('pt-BR').format(value);
const reproductions = value => `${number(value)} ${value === 1 ? 'reprodução' : 'reproduções'}`;
const percent = value => `${new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 1}).format(value)}%`;
const decimal = value => new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 1}).format(value);
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
function dateOnly(value) { return new Intl.DateTimeFormat('pt-BR', {timeZone: timezone, dateStyle: 'short'}).format(new Date(value)); }

function comparisonNote(comparison) {
  if (!comparison.available) {
    return {
      all_history: 'Selecione um período de datas para comparar com o anterior.',
      insufficient_history: 'Sem base comparável: o histórico registrado ainda não alcança o início do período anterior.',
      future_dates: 'Comparação indisponível para períodos com datas futuras.',
      outside_range: 'O período anterior fica fora do intervalo de datas suportado.'
    }[comparison.status] || 'Sem base comparável.';
  }
  return `Comparação com ${calendar(comparison.from)} a ${calendar(comparison.to)}${comparison.partial ? `, até ${new Intl.DateTimeFormat('pt-BR', {timeZone: timezone, timeStyle: 'short'}).format(new Date(comparison.until_at))} no último dia (mesmo trecho transcorrido)` : ' (mesma quantidade de dias)'}. As contagens refletem os eventos registrados e podem ter lacunas.`;
}

function renderMetrics(data) {
  const summary = data.summary;
  const comparison = data.comparison;
  for (const metric of ['plays', 'tracks', 'artists', 'albums']) text(`stat-${metric}`, number(summary[metric]));
  text('stat-average', summary.plays_per_active_day === null ? '—' : decimal(summary.plays_per_active_day));
  text('active-days', summary.active_days ? `${reproductions(summary.plays)} ÷ ${number(summary.active_days)} ${summary.active_days === 1 ? 'dia com registros' : 'dias com registros'}` : 'Nenhum dia com reproduções registradas neste período.');
  text('stat-concentration', summary.top_artists_share_pct === null ? '—' : percent(summary.top_artists_share_pct));
  text('concentration-title', summary.top_artists_count === 1 ? 'Seu artista mais ouvido' : summary.top_artists_count ? `Seus ${number(summary.top_artists_count)} artistas mais ouvidos` : 'Seus artistas mais ouvidos');
  text('concentration-detail', summary.plays ? `${number(summary.top_artists_plays)} de ${reproductions(summary.plays)}` : 'Nenhuma reprodução registrada neste período.');
  const concentrationMeter = $('concentration-meter');
  concentrationMeter.hidden = summary.top_artists_share_pct === null;
  concentrationMeter.value = summary.top_artists_share_pct ?? 0;
  concentrationMeter.setAttribute('aria-valuetext', summary.plays ? `${percent(summary.top_artists_share_pct)}: ${number(summary.top_artists_plays)} de ${reproductions(summary.plays)}` : 'Sem dados');
  concentrationMeter.title = 'Cada reprodução conta uma vez, mesmo quando tem vários desses artistas.';
  const note = comparisonNote(comparison);
  text('comparison-note', note);
  for (const metric of ['plays', 'tracks', 'artists', 'albums', 'plays_per_active_day', 'top_artists_share_pct']) {
    const element = $(`delta-${metric}`);
    element.className = 'metric-delta';
    element.title = note;
    element.hidden = !comparison.available;
    if (!comparison.available) { element.textContent = ''; continue; }
    const delta = comparison.deltas[metric];
    const points = metric === 'top_artists_share_pct';
    const change = points ? delta.change_pp : delta.change_pct;
    if (change === null) {
      element.textContent = delta.previous === 0 ? 'Zero no anterior' : 'Sem dados comparáveis';
      element.title = `${note} Não há percentual calculável para esta métrica.`;
      continue;
    }
    element.classList.add(change > 0 ? 'change-up' : change < 0 ? 'change-down' : 'change-same');
    element.textContent = `${change > 0 ? '▲' : change < 0 ? '▼' : '='} ${points ? `${decimal(Math.abs(change))} pontos percentuais` : percent(Math.abs(change))} vs. anterior`;
    const previous = delta.previous === null ? 'sem dados' : points ? percent(delta.previous) : decimal(delta.previous);
    element.title = `${note} Valor anterior: ${previous}.`;
  }
  const sentences = [];
  if (!summary.plays) sentences.push('Nenhuma reprodução registrada no período selecionado.');
  else {
    sentences.push(`${reproductions(summary.plays)} em ${number(summary.active_days)} ${summary.active_days === 1 ? 'dia ativo' : 'dias ativos'}, com média de ${decimal(summary.plays_per_active_day)} por dia ativo.`);
    if (comparison.available) {
      const change = comparison.deltas.plays.change_pct;
      if (change === null) sentences.push('O período anterior não teve reproduções registradas.');
      else sentences.push(change === 0 ? 'O total de reproduções se manteve em relação ao período anterior.' : `${percent(Math.abs(change))} ${change > 0 ? 'mais' : 'menos'} reproduções que no período anterior.`);
    }
    if (summary.top_artists_count) sentences.push(`${summary.top_artists_count === 1 ? 'O artista mais ouvido aparece' : `Os ${number(summary.top_artists_count)} artistas mais ouvidos aparecem`} em ${percent(summary.top_artists_share_pct)} das reproduções.`);
  }
  text('listening-summary', sentences.join(' '));
}

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
      const heading = node('div', 'ranking-title');
      heading.append(title);
      if (item.change_status !== 'unavailable') {
        const change = item.rank_change;
        const badge = node('span', `rank-change ${item.change_status === 'up' || item.change_status === 'new' ? 'change-up' : item.change_status === 'down' ? 'change-down' : 'change-same'}`,
          item.change_status === 'new' ? 'Novo' : change === 0 ? '=' : `${change > 0 ? '▲' : '▼'}${number(Math.abs(change))}`);
        badge.title = item.change_status === 'new' ? 'Sem reproduções no período anterior. Não significa primeira escuta.'
          : `Posição anterior: ${number(item.previous_rank)}. ${change === 0 ? 'Mesma posição.' : `${Math.abs(change)} ${Math.abs(change) === 1 ? 'posição' : 'posições'} ${change > 0 ? 'acima' : 'abaixo'}.`}`;
        badge.setAttribute('aria-label', badge.title);
        heading.append(badge);
      }
      identity.append(heading);
      if (type === 'tracks') {
        identity.append(node('span', '', `${name(item.artists, 'Artista não informado')} · ${name(item.album, 'Álbum não informado')}`));
      }
      const dates = node('div', 'ranking-dates');
      for (const [key, label] of [['first_play_at', '1ª'], ['last_play_at', 'Última']]) {
        const stamp = node('time', '', `${label}: ${dateOnly(item[key])}`);
        stamp.dateTime = item[key];
        stamp.title = `${label === '1ª' ? 'Primeira' : 'Última'} reprodução em todo o histórico registrado: ${date(item[key])}`;
        stamp.setAttribute('aria-label', stamp.title);
        dates.append(stamp);
      }
      identity.append(dates);
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
  const releases = $('release-chart'); releases.replaceChildren();
  const decades = data.distribution.release_decades;
  if (!decades.length) releases.append(node('li', 'chart-empty', 'Nenhuma reprodução para distribuir por década.'));
  for (const item of decades) {
    const row = node('li', 'release-row');
    const label = item.decade === null ? 'Sem data' : `${String(item.decade).padStart(4, '0')}–${String(item.decade + 9).padStart(4, '0')}`;
    row.title = `${label}: ${reproductions(item.plays)}, ${percent(item.share_pct)} do período`;
    row.setAttribute('aria-label', row.title);
    const meter = node('progress'); meter.max = 100; meter.value = item.share_pct; meter.setAttribute('aria-hidden', 'true');
    row.append(node('span', '', label), meter, node('strong', '', percent(item.share_pct)), node('span', 'release-count', number(item.plays)));
    releases.append(row);
  }
}

function render(data) {
  timezone = data.timezone;
  renderMetrics(data);
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
      text('listening-summary', 'O resumo estará disponível quando a conexão for restabelecida.');
      text('comparison-note', 'Aguardando conexão com o banco.');
      $('release-chart').replaceChildren(node('li', 'chart-empty', 'Aguardando conexão com o banco.'));
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

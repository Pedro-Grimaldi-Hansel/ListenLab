'use strict';
const $ = id => document.getElementById(id);
const state = {overview: null, receivedAt: 0, page: 1, pages: 1, status: 'all', timezone: 'America/Sao_Paulo', offline: false, busy: false, detailId: null};
const statusLabels = {completed: 'Concluída', partial: 'Com alerta', failed: 'Falhou', running: 'Em andamento'};
const statusClasses = {completed: 'success', partial: 'warning', failed: 'danger', running: 'running'};
const operationLabels = {sync: 'Coleta', probe: 'Investigação', replay: 'Reprocessamento', diagnostic: 'Diagnóstico'};
const number = value => new Intl.NumberFormat('pt-BR').format(value ?? 0);
const node = (tag, cls, text) => { const element = document.createElement(tag); if (cls) element.className = cls; if (text !== undefined) element.textContent = text; return element; };
const text = (id, value) => { $(id).textContent = value; };
const date = (value, options = {}) => value ? new Intl.DateTimeFormat('pt-BR', {timeZone: state.timezone, ...options}).format(new Date(value)) : '—';
const clock = value => date(value, {hour: '2-digit', minute: '2-digit', second: '2-digit'});
const shortDate = value => date(value, {day: '2-digit', month: 'short'}).replace('.', '');
const fullDate = value => date(value, {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'});
const bytes = value => value < 1024 ? `${value} B` : value < 1048576 ? `${(value / 1024).toLocaleString('pt-BR', {maximumFractionDigits: 1})} KB` : value < 1073741824 ? `${(value / 1048576).toLocaleString('pt-BR', {maximumFractionDigits: 1})} MB` : `${(value / 1073741824).toLocaleString('pt-BR', {maximumFractionDigits: 2})} GB`;
const duration = value => value === null ? 'Em curso' : value < 1 ? `${Math.round(value * 1000)} ms` : value < 60 ? `${value.toLocaleString('pt-BR', {maximumFractionDigits: 1})} s` : `${Math.floor(value / 60)} min ${Math.floor(value % 60)} s`;
const badge = status => node('span', `badge ${statusClasses[status] || 'neutral'}`, statusLabels[status] || status);
const now = () => state.overview ? new Date(state.overview.server_time).getTime() + (Date.now() - state.receivedAt) : Date.now();

async function request(url) {
  const response = await fetch(url, {cache: 'no-store', signal: AbortSignal.timeout(10000)});
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'Não foi possível atualizar o monitor.');
  return data;
}

function renderStatus() {
  if (!state.overview) return;
  const {scheduler, latest_sync: latest, preserved, today} = state.overview;
  if (state.overview.demo) document.querySelector('.page-header .eyebrow').textContent = 'PRÉVIA · DADOS SINTÉTICOS';
  try { new Intl.DateTimeFormat('pt-BR', {timeZone: state.overview.timezone}); state.timezone = state.overview.timezone; } catch { state.timezone = 'America/Sao_Paulo'; }
  text('interval-value', scheduler.interval_seconds % 60 === 0 ? `${scheduler.interval_seconds / 60} minutos` : `${scheduler.interval_seconds} segundos`);
  text('latest-time', latest ? date(latest.started_at, {hour: '2-digit', minute: '2-digit', second: '2-digit'}) : 'Sem coletas');
  $('latest-status').replaceWith(Object.assign(badge(latest?.status || 'Sem execução'), {id: 'latest-status'}));
  text('latest-date', latest ? `${shortDate(latest.started_at)} · #${latest.id}` : 'Execute sua primeira coleta');
  text('plays-total', number(preserved.plays));
  text('latest-new', latest ? `+${number(latest.plays_created)} novas` : '—');
  text('raw-total', number(preserved.raw_files));
  text('raw-size', `${bytes(preserved.raw_bytes)} em respostas catalogadas`);
  renderStorage(state.overview.storage);
  text('activity-total', `${number(today.total)} execuções`);
  text('activity-completed', number(today.completed));
  text('activity-partial', number(today.partial));
  text('activity-failed', number(today.failed));
  text('timezone-label', state.timezone === 'America/Sao_Paulo' ? 'Horário de Brasília' : state.timezone);
  text('updated-at', `Atualizado às ${clock(state.overview.server_time)} · tela a cada 5s`);
  tick();
}

function renderStorage(storage) {
  if (!storage) return;
  text('storage-local', bytes(storage.local_bytes));
  text('storage-unique', number(storage.unique_contents));
  text('storage-compression', `${number(storage.compressed_contents)} compactados · ${number(storage.archived_contents)} arquivados`);
  text('storage-archive', storage.measured_at ? bytes(storage.archive_bytes) : 'Não medido');
  text('storage-backup', storage.last_verified_backup_at ? fullDate(storage.last_verified_backup_at) : 'Ainda não verificado');
  const kept = storage.backup_keep === 0 ? 'todos os snapshots' : `até ${storage.backup_keep} snapshots completos`;
  text('storage-snapshots', `${number(storage.snapshot_count)} snapshots · mantém ${kept}`);
  text('storage-savings', `${bytes(storage.logical_bytes)} recebidos logicamente · ${bytes(storage.saved_local_bytes)} de economia na pasta RAW ativa. O arquivo e os backups são contabilizados separadamente.`);
  text('storage-policy', storage.maintenance_enabled ? `Manutenção diária: conteúdos com ${storage.archive_after_days} dias podem sair da pasta ativa após restauração verificada do backup. Os originais continuam recuperáveis no arquivo.` : 'Manutenção automática desativada; os comandos locais continuam disponíveis.');
  const status = storage.maintenance?.status;
  text('storage-status', status === 'running' ? 'Em manutenção' : status === 'failed' ? 'Requer atenção' : status === 'completed' ? 'Verificado' : 'Aguardando manutenção');
  $('storage-status').className = `badge ${status === 'failed' ? 'danger' : status === 'completed' ? 'success' : 'neutral'}`;
}

function tick() {
  if (!state.overview) return;
  const runtime = state.overview.scheduler;
  let status = runtime.status;
  const age = runtime.heartbeat_at ? Math.max(0, Math.floor((now() - Date.parse(runtime.heartbeat_at)) / 1000)) : null;
  if (age !== null && age > state.overview.heartbeat_stale_seconds && !['stopped','unknown'].includes(status)) status = 'stale';
  if (state.offline || Date.now() - state.receivedAt > 20000) status = 'disconnected';
  const variants = {
    waiting: ['Ativo', 'Coleta em funcionamento', 'O agendador está aguardando a próxima busca no Spotify.', 'healthy'],
    collecting: ['Coletando', 'Buscando novos eventos', 'As respostas estão sendo preservadas e processadas.', 'healthy'],
    stopped: ['Pausado', 'Coleta automática pausada', 'O agendador foi encerrado. Abra o guia para retomá-lo.', 'warning'],
    unknown: ['Sem sinal', 'Agendador não confirmado', 'Abra o guia para iniciar ou conferir o serviço de coleta.', 'warning'],
    stale: ['Sem sinal recente', 'Confira o agendador', 'O sinal está desatualizado. Verifique se o scheduler e o Docker estão em execução.', 'warning'],
    delayed: ['Atrasado', 'Coleta fora do horário previsto', 'O agendador responde, mas o horário da próxima busca já passou. Consulte as execuções.', 'warning'],
    disconnected: ['Sem conexão', 'Atualização indisponível', 'Os dados exibidos são os últimos recebidos. Aguardando conexão.', 'danger'],
  };
  const [label, title, description, cls] = variants[status] || variants.unknown;
  text('collector-badge', label); $('collector-badge').className = `status-pill ${cls}`;
  text('collector-title', title); text('collector-description', description);
  text('heartbeat-value', age === null ? 'Sinal ainda não recebido' : status === 'stopped' ? 'Encerramento confirmado' : `Último sinal há ${age}s`);
  const showNext = ['waiting','delayed'].includes(status) && runtime.next_run_at;
  const remaining = showNext ? Math.max(0, Math.ceil((Date.parse(runtime.next_run_at) - now()) / 1000)) : null;
  text('countdown-value', status === 'collecting' ? 'Agora' : remaining !== null ? `${String(Math.floor(remaining / 60)).padStart(2,'0')}:${String(remaining % 60).padStart(2,'0')}` : '—:—');
  text('countdown-label', status === 'collecting' ? 'BUSCA EM ANDAMENTO' : 'PRÓXIMA COLETA');
  text('next-run-value', showNext ? `Prevista para ${clock(runtime.next_run_at)}` : status === 'collecting' ? `Iniciada às ${clock(runtime.cycle_started_at)}` : 'Sem próxima execução confirmada');
  $('countdown-progress').style.width = remaining !== null ? `${Math.max(0, Math.min(100, (1 - remaining / runtime.interval_seconds) * 100))}%` : status === 'collecting' ? '100%' : '0%';
}

function renderBatches(data) {
  state.page = data.page; state.pages = data.pages;
  text('history-count', number(data.total));
  const body = $('history-body'); body.replaceChildren();
  if (data.items.length === 0) {
    const row = node('tr'); const cell = node('td', 'empty-state', state.status === 'all' ? 'Seu histórico de execuções começa aqui. Assim que a primeira coleta acontecer, o resultado aparecerá nesta lista.' : 'Nenhuma execução encontrada com este status.'); cell.colSpan = 8; row.append(cell); body.append(row);
  }
  for (const batch of data.items) {
    const row = node('tr');
    const id = node('td'); id.append(node('span', 'batch-number', `#${String(batch.id).padStart(4,'0')}`), node('span', 'batch-type', operationLabels[batch.operation] || batch.operation));
    const started = node('td'); started.append(node('span','row-time',clock(batch.started_at)),node('span','row-date',shortDate(batch.started_at)));
    const details = node('td'); const button = node('button','detail-button','↗'); button.setAttribute('aria-label', `Ver detalhes da execução ${batch.id}`); button.addEventListener('click', () => openDetails(batch.id)); details.append(button);
    const statusCell = node('td'); statusCell.append(badge(batch.status));
    row.append(id, started, node('td','', duration(batch.duration_seconds)), node('td','numeric',number(batch.received)), node('td',`numeric ${batch.plays_created > 0 ? 'number-positive' : 'number-muted'}`, batch.plays_created > 0 ? `+${number(batch.plays_created)}` : '0'), node('td','numeric number-muted',number(batch.existing)), statusCell, details);
    body.append(row);
  }
  text('pagination-summary', data.total ? `Exibindo ${(data.page - 1) * 15 + 1}–${Math.min(data.page * 15, data.total)} de ${number(data.total)} execuções` : state.status === 'all' ? 'Nenhuma execução registrada' : 'Nenhuma execução neste filtro');
  text('page-label', `${data.page} / ${data.pages}`);
  $('previous-page').disabled = data.page <= 1; $('next-page').disabled = data.page >= data.pages;
}

async function refresh() {
  if (state.busy) return;
  state.busy = true; $('refresh-button').disabled = true;
  $('status-filter').disabled = true; $('previous-page').disabled = true; $('next-page').disabled = true;
  try {
    const [overview, batches] = await Promise.all([request('/api/status'), request(`/api/batches?page=${state.page}&status=${encodeURIComponent(state.status)}`)]);
    state.overview = overview; state.receivedAt = Date.now(); state.offline = false;
    $('error-banner').hidden = true; $('live-label').className = 'live-label online'; $('live-label').replaceChildren(node('span','small-dot'), document.createTextNode('Ao vivo'));
    text('database-status','PostgreSQL conectado'); $('database-dot').style.background = 'var(--spotify-green)';
    renderStatus(); renderBatches(batches);
  } catch (error) {
    state.offline = true; $('error-banner').hidden = false; text('error-banner', error.name === 'TimeoutError' ? 'A conexão demorou mais que o esperado. Tentando novamente em instantes.' : error.message);
    $('live-label').className = 'live-label offline'; $('live-label').replaceChildren(node('span','small-dot'), document.createTextNode('Sem atualização'));
    text('database-status','Conexão não confirmada'); $('database-dot').style.background = 'var(--red)';
    if (state.overview) tick(); else {
      text('collector-badge','MONITOR SEM CONEXÃO'); text('collector-title','Aguardando conexão com o banco.'); text('collector-description','Confira o aviso acima. A tela tentará atualizar novamente em instantes.');
      const row = node('tr'); const cell = node('td','empty-state','O histórico será exibido quando a conexão for restabelecida.'); cell.colSpan=8; row.append(cell); $('history-body').replaceChildren(row);
    }
  } finally {
    state.busy = false; $('refresh-button').disabled = false; $('status-filter').disabled = false;
    $('previous-page').disabled = state.page <= 1; $('next-page').disabled = state.page >= state.pages;
  }
}

async function openDetails(id) {
  state.detailId = id; text('detail-title', `Execução #${String(id).padStart(4,'0')}`);
  $('detail-content').replaceChildren(node('p','detail-text','Carregando detalhes da execução…')); $('detail-dialog').showModal();
  try {
    const data = await request(`/api/batches/${id}`);
    if (state.detailId !== id) return;
    const content = $('detail-content'); content.replaceChildren();
    const meta = node('div','detail-meta'); meta.append(badge(data.status),node('span','',`${operationLabels[data.operation] || data.operation} · ${fullDate(data.started_at)} · ${duration(data.duration_seconds)}`)); content.append(meta);
    const stats = node('div','detail-stats');
    for (const [label, value] of [['Recebidos',data.received],['Novas reproduções',data.plays_created],['Já existentes',data.existing],['Com falha',data.failed]]) {
      const card = node('div','detail-stat'); card.append(node('span','',label),node('strong','',number(value))); stats.append(card);
    }
    content.append(stats);
    const reasons = {empty_page:'A API retornou uma página vazia.', next_exhausted:'A API não forneceu outra página.', page_limit:'O orçamento de páginas foi atingido.', no_progress:'A API repetiu uma página já recebida.', repeated_cursor:'A paginação repetiu um cursor.', non_decreasing_cursor:'O cursor de investigação não retrocedeu.', no_before_cursor:'A resposta não forneceu um cursor anterior.', replayed_artifact:'Arquivo original reprocessado.', error:'A execução terminou com falha.', collecting:'Coleta em andamento.'};
    content.append(node('p','detail-text',`${data.pages === null ? 'Páginas ainda não informadas' : `${data.pages} página(s)`} · ${reasons[data.stop_reason] || 'Consulte os registros desta execução.'}`));
    if (data.oldest && data.newest) content.append(node('p','detail-text',`Período dos eventos: ${fullDate(data.oldest)} até ${fullDate(data.newest)}.`));
    content.append(node('h3','detail-section-title',`Respostas RAW · ${data.artifacts.length} arquivo(s)`));
    content.append(node('p','detail-text','Os hashes descrevem os bytes originais. O caminho pode conter uma referência ao conteúdo compartilhado. Use raw:verify para conferir a integridade ou raw:read ID para ler o corpo original, inclusive arquivado.'));
    if (!data.artifacts.length) content.append(node('p','detail-text','Nenhuma resposta RAW registrada nesta execução. A falha pode ter ocorrido antes da chamada à API.'));
    for (const artifact of data.artifacts) {
      const card = node('div','artifact'); const heading = node('div','artifact-heading');
      const copy = node('button','copy-button','Copiar caminho'); copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(`storage/raw/${artifact.path}`); copy.textContent='Copiado'; } catch { copy.textContent='Selecione o caminho abaixo'; } });
      const location = artifact.storage_tier === 'archive' ? 'arquivo privado' : artifact.storage_tier === 'local' ? 'conteúdo compartilhado' : 'arquivo legado';
      heading.append(node('strong','',`RAW #${artifact.id} · HTTP ${artifact.http_status ?? '—'} · ${bytes(artifact.size_bytes)} originais · ${location}${artifact.encoding === 'gzip' ? ' · compactado' : ''}`),copy);
      card.append(heading,node('p','artifact-path',`storage/raw/${artifact.path}`),node('div','artifact-hash',`SHA-256 ${artifact.sha256}`)); content.append(card);
    }
    content.append(node('h3','detail-section-title','Problemas registrados'));
    if (!data.issues.length) content.append(node('p','detail-text','Nenhum problema registrado nesta execução.'));
    for (const issue of data.issues) { const card=node('div','issue'); card.append(node('strong','',issue.code),node('span','',issue.message)); content.append(card); }
  } catch (error) { if (state.detailId === id) $('detail-content').replaceChildren(node('p','detail-text',error.message)); }
}

$('refresh-button').addEventListener('click', refresh);
$('status-filter').addEventListener('change', event => { state.status=event.target.value; state.page=1; refresh(); });
$('previous-page').addEventListener('click', () => { state.page=Math.max(1,state.page-1); refresh(); });
$('next-page').addEventListener('click', () => { state.page=Math.min(state.pages,state.page+1); refresh(); });
$('help-button').addEventListener('click', () => $('help-dialog').showModal());
$('close-help').addEventListener('click', () => $('help-dialog').close());
$('close-detail').addEventListener('click', () => $('detail-dialog').close());
$('detail-dialog').addEventListener('close', () => { state.detailId=null; });
for (const dialog of [$('help-dialog'),$('detail-dialog')]) dialog.addEventListener('click', event => { if (event.target === dialog) { const rect=dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close(); } });
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
setInterval(() => { if (!document.hidden) refresh(); },5000);
setInterval(tick,1000);
refresh();

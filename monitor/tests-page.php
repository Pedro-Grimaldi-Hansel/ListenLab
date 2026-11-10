<?php
declare(strict_types=1);

$catalog = require __DIR__ . '/test-catalog.php';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$count = static fn (array $group): int => array_sum(array_map(static fn (array $test): int => $test[3] ?? 1, $group['tests']));
$total = array_sum(array_map($count, $catalog));
$unit = array_sum(array_map($count, array_filter($catalog, static fn (array $group): bool => $group['type'] === 'unit')));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#121212">
  <title>ListenLab · Testes explicados</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='12' fill='%231db954'/%3E%3Cpath d='M12 17v6m8-12v18m8-15v12' stroke='%23121212' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">
  <script src="/assets/theme.js"></script>
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/tests.js" defer></script>
</head>
<body data-page="tests">
  <aside class="sidebar">
    <a class="brand" href="/dashboard" aria-label="ListenLab, dashboard"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span>ListenLab<span class="brand-sub">SEU ARQUIVO MUSICAL</span></span></a>
    <div class="nav-label">SEU LISTENLAB</div>
    <nav aria-label="Menu principal">
      <a class="nav-item" href="/dashboard" aria-label="Dashboard" title="Dashboard"><span aria-hidden="true">◫</span> Dashboard</a>
      <a class="nav-item" href="/dashboard#history" aria-label="Histórico de execuções" title="Histórico de execuções"><span aria-hidden="true">↺</span> Histórico de execuções</a>
      <a class="nav-item active" href="/tests" aria-current="page" aria-label="Testes" title="Testes"><span aria-hidden="true">✓</span> Testes</a>
      <a class="nav-item" href="#run-tests" aria-label="Como executar os testes" title="Como executar os testes"><span aria-hidden="true">ⓘ</span> Como executar</a>
      <button class="nav-item" id="theme-toggle" type="button" aria-label="Modo escuro" aria-pressed="false" title="Modo escuro"><span aria-hidden="true" id="theme-icon">☾</span><span class="nav-text" id="theme-label">Modo escuro</span></button>
    </nav>
    <div class="sidebar-bottom"><div class="local-label"><span class="small-dot"></span> Feito para rodar localmente</div><p>Seu histórico, sob seu controle.</p><span class="version">LISTENLAB / MONITOR</span></div>
  </aside>
  <main>
    <nav class="screen-switcher" aria-label="Telas do ListenLab"><a href="/dashboard">Dashboard</a><a href="/tests" aria-current="page">Testes</a></nav>
    <header class="page-header"><div><div class="eyebrow">DOCUMENTAÇÃO DA SUÍTE</div><h1>Testes explicados.</h1><p>Consulte os cenários e veja como verificar cada parte do ListenLab.</p></div><a class="button button-light" href="/dashboard">Voltar ao dashboard <span aria-hidden="true">↗</span></a></header>
    <section class="tests-intro" aria-label="Como a suíte funciona">
      <div><span class="collector-label">DADOS FICTÍCIOS, VERIFICAÇÕES REAIS</span><h2>O que protege o seu histórico.</h2><p>Os testes simulam eventos, respostas e falhas para conferir se o ListenLab preserva a evidência e registra cada reprodução com segurança.</p></div>
      <div class="tests-safety"><strong>Seu histórico fica preservado.</strong><p>Os testes usam fixtures sintéticas, arquivos temporários e um banco separado terminado em <code>_test</code>. As chamadas Spotify são simuladas.</p></div>
    </section>
    <section class="test-suite-summary" aria-label="Composição da suíte">
      <div><strong id="test-total"><?= $total ?></strong><span>cenários em <?= count($catalog) ?> grupos</span></div>
      <div><strong><?= $unit ?></strong><span>unitários · regras isoladas</span></div>
      <div><strong><?= $total - $unit ?></strong><span>integração · banco, processos e HTTP</span></div>
    </section>
    <p class="tests-caption">Um teste pode ter várias entradas: cada variação conta como um cenário. Este catálogo explica a cobertura; o resultado atualizado aparece no terminal depois de executar a suíte.</p>
    <section class="tests-controls" id="test-controls" aria-label="Buscar e filtrar testes" hidden>
      <label class="test-search">Buscar um teste<input id="test-search" type="search" placeholder="Ex.: duplicar, tokens, RAW…" autocomplete="off" aria-controls="test-groups"></label>
      <label class="filter-label">Tipo de teste<select id="test-type"><option value="all">Todos os tipos</option><option value="unit">Unitários</option><option value="integration">Integração</option></select></label>
      <p id="test-results" role="status" aria-live="polite"><?= $total ?> cenários</p>
    </section>
    <div class="test-groups" id="test-groups" data-total="<?= $total ?>">
      <?php foreach ($catalog as $index => $group): ?>
      <details class="test-group" id="test-<?= $escape($group['id']) ?>" data-type="<?= $escape($group['type']) ?>">
        <summary><span class="test-group-number" aria-hidden="true"><?= sprintf('%02d', $index + 1) ?></span><div class="test-group-heading"><div class="test-group-title"><h2><?= $escape($group['title']) ?></h2><span class="badge neutral"><?= $group['type'] === 'unit' ? 'Unitário' : 'Integração' ?></span></div><p><?= $escape($group['description']) ?></p></div><span class="test-group-count"><?= $count($group) ?> cenários</span><span class="test-chevron" aria-hidden="true">⌄</span></summary>
        <div class="test-group-content"><p class="test-source">Arquivo: <code><?= $escape($group['file']) ?></code></p><ul class="test-case-list">
          <?php foreach ($group['tests'] as $test): ?>
          <li class="test-case" data-test-id="<?= $escape($test[0]) ?>" data-cases="<?= $test[3] ?? 1 ?>"><h3><?= $escape($test[1]) ?><?php if (($test[3] ?? 1) > 1): ?> <span class="count-label"><?= $test[3] ?> variações</span><?php endif; ?></h3><p><?= $escape($test[2]) ?></p></li>
          <?php endforeach; ?>
        </ul></div>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="empty-state test-empty" id="test-empty" hidden>Nenhum teste encontrado. Tente outro termo ou tipo.</p>
    <section class="test-run-card" id="run-tests" aria-labelledby="run-tests-title">
      <div class="eyebrow">DA EXPLICAÇÃO À VERIFICAÇÃO</div><h2 id="run-tests-title">Como executar os testes</h2><p>Com o Docker e o banco ligados, abra o terminal na pasta ListenLab. O banco <code>listenlab_test</code> já está preparado nesta instalação.</p>
      <?php foreach ([
          ['Suíte completa', 'Executa os testes unitários e de integração.', 'docker compose run --no-deps --rm -T app composer test:all'],
          ['Somente unitários', 'Confere as regras isoladas sem precisar conectar ao banco.', 'docker compose run --no-deps --rm -T app composer test'],
          ['Somente integração', 'Verifica o banco separado, o agendador e as rotas HTTP.', 'docker compose run --no-deps --rm -T app composer test:integration'],
      ] as [$title, $description, $command]): ?>
      <div class="test-command"><div><h3><?= $escape($title) ?></h3><p><?= $escape($description) ?></p></div><pre><code><?= $escape($command) ?></code></pre><button class="copy-button" type="button" data-copy="<?= $escape($command) ?>" hidden>Copiar comando</button></div>
      <?php endforeach; ?>
      <p class="tests-caption">Ao terminar, o PHPUnit informa os testes, as assertions (verificações feitas dentro deles) e eventuais falhas. Alguns testes possuem requisitos específicos, como <code>pcntl</code>, já incluído na imagem Docker.</p>
    </section>
    <footer class="page-footer"><span><span class="small-dot"></span> Catálogo da suíte local</span><span>Escolha um grupo para ler os cenários.</span><a href="/dashboard">Voltar ao dashboard ↗</a></footer>
  </main>
</body>
</html>

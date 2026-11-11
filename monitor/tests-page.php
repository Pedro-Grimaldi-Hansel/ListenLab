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
  <a class="skip-link" href="#main-content">Ir para o conteúdo</a>
  <aside class="sidebar">
    <a class="brand" href="/analytics" aria-label="ListenLab, análises"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>ListenLab<span class="brand-sub">Arquivo musical</span></span></a>
    <div class="nav-label">Biblioteca</div>
    <nav aria-label="Menu principal"><a class="nav-item" href="/analytics"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#analytics"></use></svg><span>Análises</span></a><a class="nav-item" href="/dashboard"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#dashboard"></use></svg><span>Dashboard</span></a><a class="nav-item active" href="/tests" aria-current="page"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#tests"></use></svg><span>Testes</span></a></nav>
    <div class="sidebar-bottom"><button class="theme-control" id="theme-toggle" type="button" aria-label="Alternar tema" aria-pressed="true"><span id="theme-icon" aria-hidden="true">☀</span><span id="theme-label">Tema claro</span></button><div class="local-label"><span class="small-dot"></span> Armazenado neste computador</div><span class="version">LISTENLAB / LOCAL</span></div>
  </aside>
  <main id="main-content" tabindex="-1">
    <header class="page-header"><div><div class="eyebrow">Documentação</div><h1>Testes</h1><p>Explore o que é verificado no ListenLab.</p></div><a class="button button-light" href="#run-tests">Como executar <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#arrow"></use></svg></a></header>
<section class="test-suite-summary" aria-label="Composição da suíte">
      <div><strong id="test-total"><?= $total ?></strong><span>cenários em <?= count($catalog) ?> grupos</span></div>
      <div><strong><?= $unit ?></strong><span>unitários · regras isoladas</span></div>
      <div><strong><?= $total - $unit ?></strong><span>integração · banco, processos e HTTP</span></div>
    </section>
    <p class="tests-caption">Este é o catálogo de cobertura. Os resultados de cada execução aparecem no terminal. Os testes usam dados fictícios e banco separado terminado em _test.</p>
    <section class="tests-controls" id="test-controls" aria-label="Buscar e filtrar testes" hidden>
      <label class="test-search">Buscar um teste<input id="test-search" type="search" placeholder="Ex.: duplicar, tokens, RAW…" autocomplete="off" aria-controls="test-groups"></label>
      <label class="filter-label">Tipo de teste<select id="test-type"><option value="all">Todos os tipos</option><option value="unit">Unitários</option><option value="integration">Integração</option></select></label>
      <p id="test-results" role="status" aria-live="polite"><?= $total ?> cenários</p>
    </section>
    <div class="test-groups" id="test-groups" data-total="<?= $total ?>">
      <?php foreach ($catalog as $index => $group): ?>
      <details class="test-group" id="test-<?= $escape($group['id']) ?>" data-type="<?= $escape($group['type']) ?>">
        <summary><span class="test-group-number" aria-hidden="true"><?= sprintf('%02d', $index + 1) ?></span><div class="test-group-heading"><div class="test-group-title"><h2><?= $escape($group['title']) ?></h2><span class="badge neutral"><?= $group['type'] === 'unit' ? 'Unitário' : 'Integração' ?></span></div><p><?= $escape($group['description']) ?></p></div><span class="test-group-count"><?= $count($group) ?> cenários</span><span class="test-chevron"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#chevron"></use></svg></span></summary>
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
      <div class="eyebrow">NA SUA MÁQUINA</div><h2 id="run-tests-title">Como executar os testes</h2><p>Com o Docker e o banco ligados, abra o terminal na pasta ListenLab. O banco <code>listenlab_test</code> já está preparado nesta instalação.</p>
      <?php foreach ([
          ['Suíte completa', 'Executa os testes unitários e de integração.', 'docker compose run --no-deps --rm -T app composer test:all'],
          ['Somente unitários', 'Confere as regras isoladas sem precisar conectar ao banco.', 'docker compose run --no-deps --rm -T app composer test'],
          ['Somente integração', 'Verifica o banco separado, o agendador e as rotas HTTP.', 'docker compose run --no-deps --rm -T app composer test:integration'],
      ] as [$title, $description, $command]): ?>
      <div class="test-command"><div><h3><?= $escape($title) ?></h3><p><?= $escape($description) ?></p></div><pre><code><?= $escape($command) ?></code></pre><button class="copy-button" type="button" data-copy="<?= $escape($command) ?>" hidden>Copiar comando</button></div>
      <?php endforeach; ?>
      <p class="tests-caption">Ao terminar, o PHPUnit informa os testes, as assertions (verificações feitas dentro deles) e eventuais falhas. Alguns testes possuem requisitos específicos, como <code>pcntl</code>, já incluído na imagem Docker.</p>
    </section>
    <footer class="page-footer"><span><span class="small-dot"></span> Catálogo da suíte local</span><span>Escolha um grupo para ler os cenários.</span><a href="/analytics">Ir para Análises →</a></footer>
  </main>
</body>
</html>

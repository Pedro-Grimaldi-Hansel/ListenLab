# Continuar o ListenLab no VS Code

Validação local: 10/11/2026. Esta pasta é a instalação existente do proprietário. O arquivo de continuidade não existia na pasta nem no ZIP recebido; este documento registra o estado observado após a integração.

## Contexto e preservação

Leia `AGENTS.md`, `README.md`, `docs/monitor.md` e as decisões em `docs/decisions/`. O `AGENTS.md` fornecido foi copiado para a raiz do projeto.

O pacote `ListenLab-monitor-local.zip` foi comparado com a pasta antes da integração. Os arquivos substituídos têm cópia temporária fora do repositório. As alterações locais anteriores foram mantidas. O repositório já tinha o README modificado e a maior parte do projeto ainda não rastreada pelo Git.

Na integração inicial, não houve edição de `.env`, tokens, RAW ou relatórios existentes. Os 67 arquivos privados presentes naquele início mantiveram seus hashes. Os hashes dos 50 registros anteriores de `plays` e dos 44 registros anteriores de `raw_artifacts` também permaneceram iguais. O volume PostgreSQL existente foi mantido. A atualização posterior de armazenamento, autorizada pelo proprietário, está descrita abaixo.

## Serviços locais

- Monitor: `http://127.0.0.1:8787`, publicado somente no loopback.
- PostgreSQL: saudável, publicado em `127.0.0.1:15432`, usando o volume existente.
- Scheduler: ativo, com intervalo efetivo de 900 segundos (15 minutos), confirmado pela API, e heartbeat de cinco segundos. A primeira coleta após recriação terminou com código zero.
- Banco de testes: `listenlab_test`; fixtures em schemas temporários, removidos ao final. Os testes não chamam o Spotify nem usam tokens reais.

Fechar o navegador não interrompe a coleta. Docker e o computador precisam continuar ligados.

## Verificações concluídas

- Imagem PHP 8.4.27 reconstruída com `pcntl`.
- Suíte completa com armazenamento e backup: **88 testes, 727 assertions**, sem falhas ou skips.
- `composer validate --strict` e `composer lint` aprovados.
- `raw:verify`: todos os 113 artefatos registrados na verificação final conferiram por SHA-256, incluindo os novos RAW do scheduler.
- HTTP local: página, assets, status, histórico, paginação, filtros, detalhes, respostas HEAD, erros de parâmetros, métodos de escrita recusados e caminhos privados bloqueados.
- Testes HTTP permanentes iniciam um servidor isolado com conexão PostgreSQL somente leitura no schema de teste.
- Scheduler sintético: heartbeat atualizado durante o processo filho, lock exclusivo e encerramento por SIGTERM sem próxima execução confirmada.

A conferência visual permanece pendente. A ferramenta de controle do Chrome encerrou a inspeção por não conseguir confirmar a URL atual do navegador; nenhum resultado visual foi considerado aprovado. Abra o monitor para revisar aparência, responsividade e interação dos diálogos.

## Tema e explicações dos testes

O menu do monitor oferece tema claro/escuro e a página `/tests`. O tema segue a preferência do sistema até uma escolha explícita, salva no navegador e compartilhada entre as duas telas. O catálogo atualizado explica 78 métodos em oito grupos, correspondentes a 88 cenários (39 unitários e 49 de integração), incluindo as variações de dados.

O dashboard principal tem rota própria `/dashboard`; a entrada `/` redireciona para ela. Dashboard e testes têm navegação explícita, conteúdo distinto e scripts separados. O catálogo usa uma apresentação de documentação, em vez dos cartões operacionais do dashboard. A paleta passou a usar verde `#1DB954`, preto e cinza, com destaques `#1ED760`, mantendo as escolhas de tema já salvas no navegador.

O catálogo é descritivo e não executa a suíte. A página funciona sem consultar o banco e oferece busca, filtro por tipo e comandos para copiar. `testCatalogueDescribesEveryTestAndCountsItsDataSets` confere que nenhum teste ou conjunto de dados ficou sem explicação. Atualize `monitor/test-catalog.php` quando adicionar ou remover testes.

As rotas e os assets novos foram validados por HTTP. A lógica JavaScript foi conferida em execução isolada: escolha inicial do tema, persistência, alternância, armazenamento bloqueado, sincronização entre abas, busca sem distinção de acentos, filtros e contagens. A sintaxe dos três arquivos JavaScript também passou. O serviço de acesso ao navegador continuou indisponível para revisão visual.

Na etapa de separação das telas, a validação confirmou o redirecionamento de GET/HEAD em `/`, as páginas `/dashboard` e `/tests`, seus conteúdos/scripts distintos, a paleta servida e a API operacional respondendo. Composer e lint PHP passaram. Nenhuma migration ou alteração de configuração privada foi necessária para aquela mudança visual.

## Armazenamento e coleta a cada 15 minutos

O proprietário autorizou deduplicação, compressão sem perdas e liberação das cópias ativas após backup restaurado e verificado. Escolheu uma pasta privada dentro do projeto: `storage/archive`. Leia [operação e restauração](raw-storage.md) e a [decisão 005](decisions/005-shared-raw-storage.md).

O `.env` foi editado apenas nas variáveis de intervalo e manutenção: `SYNC_INTERVAL_SECONDS=900`, arquivo privado, idade de 30 dias, manutenção ligada e três snapshots completos. A comparação com a cópia anterior confirmou as demais linhas privadas preservadas. Não copie `.env.example` sobre o arquivo existente.

As migrations aditivas 003 e 004 foram aplicadas. Os corpos RAW idênticos compartilham um objeto; gzip mantém seus bytes exatos, confirmados por SHA-256. Novas ocorrências guardam hash e posição no RAW em vez de copiar o JSONB, preservando os payloads anteriores. Caminhos e linhas imutáveis de `raw_artifacts` permanecem; arquivos antigos podem conter referências ao corpo compartilhado.

A manutenção inicial simulou o plano e depois criou um snapshot de todo o schema do banco e dos conteúdos RAW. O dump foi restaurado em banco temporário `_test` e comparado antes da conversão. Também foi exercitada a restauração de todas as referências em `storage/backups/restore-check-20261110`, sem sobrescrever a instalação ativa. Essa pasta restaurada depende dos objetos em `storage/archive`.

Durante essa manutenção, os hashes das 57 plays e das 111 linhas de `raw_artifacts` permaneceram iguais; foram restauradas 111 referências. Os 101 arquivos RAW anteriores à implementação continuaram recuperáveis com seus hashes originais. Os 50 relatórios anteriores e os arquivos de tokens mantiveram seus hashes. A CLI `raw:read` também devolveu os bytes originais exatos. A coleta retomada acrescentou novos dados normalmente.

Na compactação inicial, a pasta RAW ativa catalogada passou de 5.301.445 para 13.819 bytes; os 14 conteúdos únicos arquivados ocuparam 70.222 bytes comprimidos. O arquivo completo com o primeiro dump verificado mediu 808.465 bytes. Novas coletas podem aumentar esses valores; são medidas de conteúdo de arquivo, não de alocação no disco. A prova de restauração adicional foi preservada junto ao snapshot.

O painel ganhou indicadores de espaço ativo, conteúdos únicos, arquivo/backup, última restauração verificada e política de manutenção. O catálogo da tela Testes inclui os novos cenários. Tokens, relatórios anteriores e o volume PostgreSQL foram preservados. Os números do painel medem arquivos catalogados e não a alocação NTFS ou o disco virtual reservado pelo Docker.

O arquivo fica no mesmo disco: faça também uma cópia privada em outro meio. A rotina mantém todos os corpos únicos, arquiva os que têm pelo menos 30 dias e conserva três snapshots verificados do banco. A verificação pesada ocorre no máximo diariamente. Novos corpos são deduplicados e comprimidos no recebimento. A atualização da tela a cada cinco segundos continua sem consultar o Spotify.

Mesmo uma tentativa automática falha impede outra tentativa nas próximas 24 horas, evitando multiplicar dumps a cada coleta. Uma execução manual sem `--if-due` permite tentar novamente após corrigir o problema. A lógica JavaScript do novo painel foi verificada isoladamente para sucesso, falha, medição ausente e manutenção/rotação desativadas; a inspeção visual continua pendente.

## Repetir as verificações

Na raiz do projeto, com o Docker e o banco em execução:

```powershell
docker compose run --no-deps --rm -T app composer test:all
docker compose run --no-deps --rm -T app composer validate --strict
docker compose run --no-deps --rm -T app composer lint
docker compose run --no-deps --rm -T app php app raw:verify
```

Para iniciar somente o monitor:

```powershell
docker compose --profile monitor up -d --no-deps monitor
```

Se alterar o intervalo no `.env`, recrie somente o scheduler para carregar a configuração:

```powershell
docker compose --profile schedule up -d --no-deps --force-recreate scheduler
```

Não copie `.env.example` sobre o `.env` existente e não remova volumes para atualizar o monitor. A criação inicial de `listenlab_test` está descrita no README e não precisa ser repetida nesta instalação.

## Revisão e próximos passos

A revisão de privacidade do Git confirmou ausência de arquivos privados rastreados ou no staging, além de ausência das credenciais atuais e dos identificadores do catálogo nos 89 arquivos candidatos e 87 blobs alcançáveis examinados. Uma coincidência de palavra comum em uma explicação de teste de autenticação foi revisada manualmente e não representa dado de reprodução. A autoria Git e o remoto foram mantidos; a URL do remoto não contém credencial embutida. O `.gitignore` passou a proteger toda a pasta `storage`, variantes de `.env`, dumps/exports e arquivos compactados, mantendo as migrations SQL e `.env.example` publicáveis. O `.dockerignore` recebeu as proteções correspondentes. Consulte [Git e dados privados](git-privacy.md).

O novo [exemplo público de resposta](../examples/README.md) foi criado inteiramente com dados fictícios e validado pelo parser: três eventos, duas faixas e um contexto nulo, com datas/IDs sintéticos. Não usa nem anonimiza um RAW real. Nenhum arquivo foi colocado no staging para esta revisão.

Leia a [pesquisa de confiabilidade do histórico recente](spotify-history-reliability.md) antes de alterar frequência, cursores ou retenção. Ela distingue relatos históricos, documentação oficial e observações desta instalação. A frequência e o armazenamento foram atualizados posteriormente conforme descrito acima; a estratégia de cursores permanece igual.

Revise os arquivos do monitor, `src/Monitoring/`, `src/Scheduling/`, a migration 002, Compose, documentação e testes. Os novos testes desta continuidade estão em `tests/Integration/MonitorHttpTest.php` e no cenário de heartbeat/SIGTERM de `tests/Integration/MonitorTest.php`.

Nenhum commit, push ou PR foi criado. A revisão, os commits e o push ficam com o proprietário. `.env`, `tokens/`, `storage/raw/`, `storage/archive/`, `storage/backups/`, relatórios e dumps permanecem ignorados pelo Git.

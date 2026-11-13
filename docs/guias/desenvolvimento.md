# Desenvolvimento e verificações

[Índice](../README.md) · [Continuidade no VS Code](../continuar-no-vscode.md) · [Decisões](../decisions/README.md)

Leia [AGENTS.md](../../AGENTS.md), confira `git status --short` e preserve o trabalho local. Commits e pushes são responsabilidade do proprietário. O ambiente de desenvolvimento usa as mesmas imagens PHP/PostgreSQL, com banco e arquivos sintéticos separados nos testes.

## Mapa do código

| Caminho | Responsabilidade |
| --- | --- |
| [app](../../app), [Console.php](../../src/Console.php) | Entrada CLI, opções, dependências e códigos de saída |
| [bootstrap.php](../../bootstrap.php), [Config.php](../../src/Config.php), [Database.php](../../src/Database.php) | Ambiente administrativo, configuração e conexões |
| [Spotify/](../../src/Spotify) | OAuth, renovação, vínculo de conta e chamadas à API |
| [Ingestion/](../../src/Ingestion) | Paginação, captura RAW, parsing, identidade, catálogo, auditoria e relatórios |
| [Storage/](../../src/Storage) | Conteúdo compartilhado, integridade, manutenção, backup e recuperação |
| [Scheduling/](../../src/Scheduling), [bin/scheduler.php](../../bin/scheduler.php) | Ciclos, processos filhos, sinais e heartbeat |
| [Monitoring/](../../src/Monitoring), [monitor/](../../monitor) | Credencial web, consultas operacionais, rotas e interface |
| [Analytics/](../../src/Analytics) | Períodos locais, rankings, distribuições e comparações |
| [migrations/](../../migrations), [Migrator.php](../../src/Migrator.php) | Evolução incremental com lock, transação e checksum |
| [tests/](../../tests), [phpunit.xml](../../phpunit.xml) | Testes unitários e de integração |
| [compose.yaml](../../compose.yaml), [Dockerfile](../../Dockerfile) | Serviços, volumes e separação de imagens |

HTML, CSS, JavaScript e SVG são locais; não há etapa obrigatória de build de frontend. `src/` e a interface são montados da pasta do projeto. O agendador precisa ser reiniciado para carregar mudanças no processo PHP persistente; alterações de ambiente exigem recriar o container.

## Preparar o banco de testes

Use um banco separado com nome terminado em `_test`, diferente de `POSTGRES_DB`. O padrão é `listenlab_test`. Crie-o apenas uma vez, com o banco em execução:

```powershell
docker compose up -d db
docker compose exec db createdb -U listenlab listenlab_test
```

O exemplo usa `POSTGRES_USER=listenlab`; ajuste o usuário se a configuração local for diferente. Se o banco já existir, use-o sem recriar. Confira `TEST_DB_NAME=listenlab_test` no ambiente privado. Não renomeie nem use o banco principal para fazer a suíte passar.

Os testes de integração criam schemas temporários isolados, aplicam migrations e removem os schemas ao final. Os testes de armazenamento também restauram snapshots em bancos temporários `_test`. Transportes e tokens são simulados; a suíte não precisa chamar a API com a autorização pessoal.

## Executar as verificações

Na raiz, com as dependências instaladas e o banco disponível:

```powershell
docker compose run --no-deps --rm -T app composer test
docker compose run --no-deps --rm -T app composer test:integration
docker compose run --no-deps --rm -T app composer validate --strict
docker compose run --no-deps --rm -T app composer lint
```

`composer test:all` executa as duas suítes juntas e pode substituir os dois primeiros comandos. Escolha as verificações relevantes à mudança; para alteração de domínio, armazenamento, permissões ou fluxo HTTP, execute a suíte completa.

Para mudanças de JavaScript, com Node instalado no host:

```powershell
node --check monitor/public/assets/app.js
node --check monitor/public/assets/analytics.js
node --check monitor/public/assets/tests.js
node --check monitor/public/assets/theme.js
```

Sintaxe aprovada não substitui verificar o comportamento e a apresentação no navegador. Confira teclado, filtros, recorte vazio, tema e telas pequenas quando a interface mudar.

## Cobertura e catálogo da interface

A suíte cobre OAuth simulado, renovação, recusa de outra conta, paginação, quotas, timestamps, deduplicação, variantes, dados incompletos, replay, integridade e recuperação RAW, permissões web, scheduler e rotas HTTP. Os arquivos em `tests/Integration/` mostram os cenários que dependem do PostgreSQL.

O catálogo em [monitor/test-catalog.php](../../monitor/test-catalog.php) descreve métodos e conjuntos de dados. Atualize-o ao adicionar/remover cenários: um teste verifica sua correspondência com a suíte. Abrir `/tests` apenas exibe esse catálogo.

Os números presentes no histórico são resultados datados, sem garantir o estado do código local em revisão. Registre comando, data e resultado real ao fazer nova validação.

## Migrations e mudanças de regra

Adicione uma nova migration; não edite uma já aplicada. A conferência de SHA-256 recusará mudanças no histórico das migrations. Identidade de eventos, versão do normalizador, escopo de contas, retenção e correspondência entre fontes exigem revisar as decisões e os efeitos de replay antes de alterar o comportamento.

Mantenha exemplos sintéticos em [examples/](../../examples/README.md), testes em diretórios temporários e dados reais nas pastas privadas. Os guias de [schema](../referencia/schema.md), [auditoria](../referencia/auditoria-enxuta.md) e [RAW](../referencia/raw-storage.md) explicam as garantias que precisam ser preservadas.

## CI e documentação

O [workflow](../../.github/workflows/tests.yml) verifica Composer, dependências, sintaxe, testes com PostgreSQL separado e imagens. O relatório de imagens inclui avisos sem correção e bloqueia achados altos/críticos corrigíveis. A configuração do workflow não prova que houve uma execução no GitHub; consulte o resultado da execução correspondente.

Para alterações só de documentação, confira links locais, títulos/âncoras, blocos de código, comandos e nomes contra o código. Não é necessário coletar músicas, aplicar migrations ou executar manutenção para validar texto.

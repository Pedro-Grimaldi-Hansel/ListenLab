# Configuração e limites locais

[Índice](../README.md) · [Instalação](../guias/instalacao.md) · [Operação](../guias/operacao.md)

O modelo público é [.env.example](../../.env.example). Os valores abaixo são **padrões**, sem reproduzir a configuração privada. A implementação está em [Config.php](../../src/Config.php), [Console.php](../../src/Console.php), [Database.php](../../src/Database.php) e [compose.yaml](../../compose.yaml).

## De onde vêm as variáveis

A CLI carrega `.env` com dotenv imutável, preservando variáveis já fornecidas pelo ambiente. O Compose usa `.env` para substituições e fornece `env_file` ao ambiente administrativo (`app`, `scheduler` e `callback`). Algumas variáveis são fixadas explicitamente pelo Compose, como a conexão interna `db:5432`.

O monitor usa outro bootstrap: não carrega `.env` nem recebe todo o ambiente administrativo. O Compose fornece somente as opções declaradas para ele e monta o secret exclusivo de conexão. Valores exibidos no painel podem exigir recriar o monitor após mudar o arquivo local.

## Banco e execução

| Variável | Padrão | Uso |
| --- | --- | --- |
| `DB_HOST` | `127.0.0.1` no PHP fora do Docker | Compose fixa `db` nos serviços da aplicação |
| `DB_PORT` | `5432` | Porta publicada no host; dentro do Docker, conexão fixa em `5432` |
| `POSTGRES_DB` | `listenlab` | Banco principal |
| `POSTGRES_USER` | `listenlab` | Usuário administrativo usado por CLI/scheduler/callback |
| `POSTGRES_PASSWORD` | Definição privada obrigatória | Substituir o placeholder do modelo por uma senha própria |
| `TEST_DB_NAME` | `listenlab_test` | Banco de testes separado, terminado em `_test` |
| `LOCAL_UID`, `LOCAL_GID` | `1000` / `1000` | Identidade de execução dos containers PHP |

Os dois bootstraps e as conexões configuram UTC. `APP_TIMEZONE=UTC` aparece no modelo, mas o código atual não usa essa variável para mudar o fuso; a exibição/filtros dependem de `MONITOR_TIMEZONE`.

As portas são publicadas em `127.0.0.1`. Nesta instalação, `DB_PORT` foi configurado como `15432`; isso não muda `db:5432`. Alterar senha no `.env` não altera um volume PostgreSQL já inicializado.

## Spotify e coletas

| Variável | Padrão / faixa aceita | Uso |
| --- | --- | --- |
| `SPOTIFY_CLIENT_ID` | Vazio no modelo | Identificador do app cadastrado |
| `SPOTIFY_CLIENT_SECRET` | Vazio no modelo | Segredo privado do app |
| `SPOTIFY_REDIRECT_URI` | `http://127.0.0.1:8888/spotify/callback` | Retorno OAuth cadastrado |
| `SPOTIFY_PAGE_LIMIT` | `50`; 1–50 | Itens solicitados por página |
| `SYNC_MAX_PAGES` | `20`; 1–10000 | Orçamento de páginas do sync |
| `PROBE_MAX_PAGES` | `100`; 1–10000 | Orçamento de páginas do probe |
| `HTTP_MAX_RETRIES` | `2`; 0–5 | Repetições de 429 comum por chamada de página |
| `HTTP_MAX_RETRY_WAIT_SECONDS` | `30`; 0–60 | Maior espera individual de `Retry-After` aceita |
| `SYNC_INTERVAL_SECONDS` | `900`; 15–86400 | Intervalo do ciclo automático em segundos |

As opções CLI `--limit` e `--max-pages` substituem os padrões daquela execução. O orçamento de retries não inclui a única renovação por 401. Não há retry cego para `QUOTA_EXCEEDED`.

## Diretórios e manutenção

| Variável | Padrão | Uso |
| --- | --- | --- |
| `RAW_STORAGE_PATH` | `storage/raw` | Referências e conteúdo ativo |
| `TOKEN_STORAGE_PATH` | `tokens` | Tokens e estado OAuth |
| `REPORT_STORAGE_PATH` | `storage/reports` | Relatórios privados |
| `RAW_ARCHIVE_PATH` | `storage/archive` | Objetos arquivados, snapshots e manifestos |
| `RAW_ARCHIVE_AFTER_DAYS` | `30`; 0–36500 | Idade mínima para liberar objeto ativo após backup verificado |
| `RAW_MAINTENANCE_ENABLED` | `1` | Apenas o valor `1` habilita a chamada automática pelo scheduler |
| `RAW_BACKUP_KEEP` | `3` | Snapshots verificados mantidos; 0 desativa rotação, ou use 2–50 |

Caminhos relativos partem da raiz do projeto, `/app` no container. Em comandos Docker, use caminhos desse ambiente. Preservar diretório de referências e objetos do arquivo é necessário ao mudar armazenamento; não basta editar uma variável.

A manutenção automática usa `--if-due`: respeita 24 horas após uma tentativa registrada, inclusive falha. A idade de arquivamento considera o primeiro recebimento do conteúdo, não a data de reprodução. A retenção de snapshots não expira os corpos RAW únicos. [Armazenamento](raw-storage.md).

## Monitor

| Variável | Padrão | Uso |
| --- | --- | --- |
| `MONITOR_PORT` | `8787` | Porta local publicada; o servidor interno continua em `8787` |
| `MONITOR_TIMEZONE` | `America/Sao_Paulo` | Exibição e períodos de calendário nas análises |
| `MONITOR_DB_USER` | `listenlab_monitor` | Role gerenciada de leitura |
| `MONITOR_DB_PASSWORD_FILE` | `storage/secrets/monitor-db-password` | Secret criado pela CLI; montado em `/run/secrets/monitor_db_password` |

O secret não vai para o Git ou imagem e não usa a senha administrativa. O monitor também recebe banco/host/porta internos e as opções operacionais selecionadas no Compose. Heartbeat de cinco segundos, limite de vinte segundos para sinal recente e atualização do Dashboard a cada cinco segundos são regras do código, sem opção no `.env` atual.

## Aplicar uma alteração

| Alteração | Aplicação |
| --- | --- |
| Frequência ou política do scheduler | Recriar `scheduler` |
| Porta, fuso ou opções exibidas no monitor | Recriar `monitor` |
| Grants de leitura do monitor | Executar `monitor:provision` com credencial administrativa |
| Porta publicada do banco | Executar `docker compose up -d db`, preservando o volume |
| Dependência/extensão/imagem PHP | Rebuild e atualização pelo guia de operação |

Para carregar novas opções no scheduler e no monitor, com o banco disponível:

```powershell
docker compose --profile schedule --profile monitor up -d --force-recreate scheduler monitor
```

Confira o intervalo efetivo no Dashboard. Reiniciar um container antigo não atualiza seu ambiente. Não imprima `docker compose config` ou `docker inspect` completos para compartilhar diagnóstico: podem conter a configuração privada.

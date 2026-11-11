# ListenLab

Fundação pessoal de dados musicais em PHP 8.4 + PostgreSQL 17. A primeira versão coleta a API oficial do Spotify, preserva cada resposta original e constrói um histórico relacional auditável. O RAW é a fonte da verdade; as tabelas normalizadas podem ser reconstruídas.

**Estado em 11/11/2026:** monitor e análises locais no Windows, com isolamento de arquivos e usuário PostgreSQL de leitura; **120 testes e 1.231 assertions** passando em banco separado. A coleta usa intervalo de 15 minutos e impede misturar contas Spotify. RAW idênticos compartilham conteúdo, com compressão sem perdas e arquivo privado após backup restaurado e verificado. A rotação preserva arquivos ocultos e falhas na varredura inicial são registradas. [Correções, validações e limites de segurança](docs/correcoes-seguranca-2026-11-10.md). A interface foi reformulada e conferida no navegador em celular e desktop, incluindo tela ampla, temas, filtros e diálogos. O primeiro probe relatado observou 50 eventos distintos em aproximadamente três horas, seguido de uma página vazia ao retroceder. [Evidência e limites do resultado](docs/experiment-results.md).

O projeto agora inclui um **monitor local de coleta** no navegador: sinal do agendador, intervalo em execução, contagem regressiva para a próxima busca e histórico com filtros e detalhes. [Instalação e uso do monitor](docs/monitor.md).

**Análises é a página inicial:** `/` redireciona para `/analytics`. O menu único reúne **Análises**, **Dashboard** (`/dashboard`, operação da coleta) e **Testes** (`/tests`, explicações da suíte). A paleta usa verde, preto e cinza inspirados no Spotify, com tema escuro inicial e opção clara. As [análises](docs/analises.md) trazem rankings em abas de faixas/artistas/álbuns, resumo do período e gráficos por horário, dia da semana e data. A navegação se adapta a telas pequenas. [Decisão de interface](docs/decisions/009-analytics-first-interface.md).

A ingestão agora omite linhas individuais para repetições completas já conhecidas, mantendo os contadores, os RAW e todos os registros anteriores. [Política de auditoria enxuta](docs/auditoria-enxuta.md).

Para retomar o trabalho nesta pasta, leia [Continuar no VS Code](docs/continuar-no-vscode.md).

A revisão inicial está em [Avaliação de segurança e confiabilidade](docs/auditoria-2026-11-10.md), seguida pelas [correções aplicadas](docs/correcoes-seguranca-2026-11-10.md). Para entender o fluxo completo, consulte [Como o ListenLab funciona hoje](docs/funcionamento-atual.md).

Para ver o formato recebido sem expor histórico pessoal, consulte o [exemplo JSON com dados fictícios](examples/README.md). As regras para manter credenciais, RAW e banco fora do Git estão em [Git e dados privados](docs/git-privacy.md).

## Rodar em desenvolvimento

Requer Docker com Compose v2.24+ (ou PHP 8.4, Composer e PostgreSQL locais).

```bash
cp .env.example .env
# Edite POSTGRES_PASSWORD. Não versione o .env.
docker compose build
docker compose up -d db
docker compose run --rm app composer install
docker compose run --rm app php app db:migrate
docker compose run --rm app php app monitor:provision
```

O banco fica em um volume persistente e sua porta é publicada somente em `127.0.0.1`. A aplicação usa o usuário local 1000 por padrão; ajuste `LOCAL_UID`/`LOCAL_GID` se necessário. O host do banco é `db` dentro dos containers e `127.0.0.1` no PHP local. Alterar a senha no `.env` não altera uma senha de um volume PostgreSQL já inicializado.

Se o Windows informar que a porta `5432` está indisponível ou que o acesso ao socket foi proibido, ela pode estar ocupada ou reservada. Altere `DB_PORT=15432` no `.env` e execute `docker compose up -d db` novamente. A aplicação em Docker continua conectando em `db:5432`; ferramentas no Windows passam a usar `127.0.0.1:15432`. Essa mudança só altera a publicação da porta e não requer rebuild da imagem nem remoção do volume.

O Dockerfile aceita um secret BuildKit opcional `proxy_ca` para ambientes com proxy TLS. Nesse caso:

```bash
docker build --secret id=proxy_ca,src=/caminho/para/ca.pem -t listenlab-php:local .
```

A CA de um proxy também precisa estar disponível ao processo PHP/Composer durante a execução, conforme a configuração do ambiente. Mantenha a verificação TLS habilitada.

## Criar o aplicativo Spotify e autorizar

1. Entre no [Developer Dashboard](https://developer.spotify.com/dashboard) com a conta que será coletada e crie um app pessoal para a Web API.
2. Em Development Mode, o proprietário precisa ter Premium ativo. Confira a allowlist de usuários nas configurações. As regras consultadas permitem até 5 usuários; quotas são compartilhadas pelos apps do desenvolvedor.
3. Cadastre exatamente `http://127.0.0.1:8888/spotify/callback` como redirect URI. `localhost` não é aceito pelo Spotify. Um ambiente remoto também pode usar uma URL HTTPS registrada.
4. Preencha `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` e `SPOTIFY_REDIRECT_URI` no `.env` privado ou nos secrets do ambiente. **Não cole secrets nem tokens em chat, issues ou comandos.**

Para receber o callback automaticamente em desenvolvimento:

```bash
docker compose --profile auth up -d callback
docker compose run --rm app php app spotify:auth
# Abra a URL exibida e conceda user-read-recently-played.
docker compose run --rm app php app spotify:auth:status
docker compose --profile auth stop callback
```

O navegador precisa alcançar o callback no ambiente onde a aplicação está rodando. Em cloud, `127.0.0.1` no seu computador e no servidor são diferentes. Como alternativa, após autorizar, copie a URL completa que ficou na barra de endereço, mesmo se a conexão ao callback falhar, e cole-a no prompt oculto:

```bash
docker compose run --rm app php app spotify:auth:complete
```

Não passe a URL em argumentos do shell. O state expira em 10 minutos e só pode ser usado uma vez. O fluxo usa Authorization Code com Client Secret no servidor. Tokens são persistidos em `tokens/spotify.json` com permissão `0600`; renovação é automática, serializada por lock e aceita rotação do refresh token. `invalid_grant` remove a autorização inválida e exige novo OAuth. A data da autorização e a previsão de nova autorização em seis meses são preservadas; renovar o access token não reinicia esse prazo. `spotify:auth:status` mostra apenas datas/scope.

O serviço de callback desabilita o access log do servidor PHP de desenvolvimento para não registrar o code OAuth na query string. Em um servidor definitivo, configure a mesma omissão de query/credenciais nos logs.

Esta versão tem **uma conta por banco/diretório de armazenamento**. O backend verifica o `account_id` estável e guarda seu hash no banco, recusando outra conta antes de substituir a autorização ou aceitar eventos. A verificação de perfil é reutilizada para o mesmo access token e refeita quando ele muda. O perfil não entra nos RAW musicais. Em uma instalação já autorizada que recebe esta atualização, execute `php app spotify:account:bind` com o token atual antes de reautorizar ou retomar a coleta; nesta instalação esse vínculo já foi aplicado. As chaves dos eventos existentes permanecem iguais. Não há suporte a múltiplas contas (ver [decisão 006](docs/decisions/006-local-security-boundaries.md)).

## Primeira coleta e inspeção

```bash
docker compose run --rm app php app spotify:sync
docker compose run --rm app php app spotify:sync
docker compose run --rm app php app history:list --details --limit=50
docker compose run --rm app php app history:list --json --limit=50
docker compose run --rm app php app ingestion:list
docker compose run --rm app php app raw:verify
```

Na repetição imediata do mesmo conjunto, `new_source_records` e `plays_created` devem ser zero. Itens completos com conteúdo já processado entram nos contadores sem outra linha por item; variantes, erros e dados incompletos conservam detalhe. `audit_records_created` e `duplicate_records_omitted` mostram a diferença no relatório. Todas as respostas RAW e os registros anteriores ficam preservados. [Auditoria enxuta](docs/auditoria-enxuta.md). `history:list --details` inclui `played_at`, track ID/URI/name, artistas ordenados, álbum, duração da track, contexto, `is_local` e fonte. Datas de consulta e relatórios usam UTC.

```sql
SELECT p.played_at, t.name
FROM plays p
JOIN tracks t ON t.id = p.track_id
ORDER BY p.played_at DESC;
```

`duration_ms` é duração de catálogo. A API não fornece `ms_played`, `skipped` ou `shuffle` nesse endpoint: esses campos permanecem `NULL`. Não existe filtro de duração mínima ou regra de play estatisticamente válido na ingestão.

## Investigar a janela acessível

```bash
docker compose run --rm app php app spotify:probe --max-pages=100 --json
```

O sync segue a URL `next` fornecida, após validar host, endpoint e parâmetros. O probe começa na janela atual e percorre `cursors.before`, o cursor documentado para a página anterior, inclusive quando `next` é `null`. Nenhum cursor é inventado a partir de timestamps.

Os dois comandos salvam cada resposta antes de validá-la, inclusive respostas de erro e tentativas de retry. Relatórios em `storage/reports/batch-<id>.json` e `ingestion_batches.metadata` contêm:

- total recebido e total de identidades válidas distintas observadas na execução;
- páginas HTTP 200 com estrutura válida e número total de respostas HTTP;
- novos registros, já existentes, rejeitados e parcialmente processados;
- timestamp válido mais antigo/recente e intervalo coberto;
- motivo de parada e caminhos RAW.

Limite de páginas, página/cursor repetido, cursor que não retrocede e falha HTTP ficam explícitos. `completed` significa que a execução chegou a uma parada natural disponibilizada pela API, **não que recuperou toda a vida da conta**. Um probe pode inserir eventos antigos ainda ausentes. [Resultados e pendências do experimento](docs/experiment-results.md).

`429` comum respeita `Retry-After` até o orçamento configurado; nunca espera menos que o valor pedido. Se a espera for longa, ausente ou as tentativas acabarem, encerra com erro auditável. `QUOTA_EXCEEDED` encerra sem retry cego. O próximo sync continua seguro graças à idempotência, sem depender de checkpoint.

## Automatizar a coleta

Após autorizar e validar a primeira coleta:

```bash
# SYNC_INTERVAL_SECONDS=900 no .env: coleta a cada 15 minutos.
docker compose --profile schedule up -d scheduler
docker compose logs --tail=100 scheduler
docker compose --profile schedule stop scheduler
```

O scheduler é um processo separado que executa o mesmo comando CLI; cron ou outro serviço podem substituí-lo. Não há fila ou Redis. Sobreposição é bloqueada por advisory lock PostgreSQL; constraints e transações protegem a identidade mesmo sem o scheduler.

O intervalo padrão é de 15 minutos, reduzindo de 288 para 96 ciclos diários em relação a cinco minutos. Paginação e retries podem acrescentar requisições. Isso não garante recuperação completa: a API pode atrasar eventos e sua janela acessível é limitada. O ambiente precisa permanecer ligado para coletar continuamente. [Confiabilidade do histórico](docs/spotify-history-reliability.md).

Após mudar `SYNC_INTERVAL_SECONDS` no `.env`, aplique `docker compose --profile schedule up -d --force-recreate scheduler`. A variável é carregada na criação do container; apenas reiniciar um container antigo não atualiza seu ambiente.

Códigos de saída: `0` = concluído, `2` = parcial (inclusive metadados incompletos), `1` = falha. Monitore batches parciais/falhos e autorizações vencidas. Encerramento abrupto pode deixar um batch `running`; não apague seus RAW. Os registros ligados aos artefatos permitem auditar e repetir o processamento.

## Reprocessar sem buscar novamente

```sql
SELECT id, batch_id, path, sha256, http_status FROM raw_artifacts ORDER BY id;
```

```bash
docker compose run --rm app php app ingest:replay 1
```

O comando verifica o SHA-256, cria outro batch e outra referência RAW aos mesmos bytes, compartilhando o conteúdo já armazenado. Reprocessa uma resposta HTTP 200 registrada do recently-played; não é um importador histórico. A data original de observação dos metadados é mantida. Repetir um artefato não cria novas plays.

Não há comando destrutivo de rebuild. Para uma futura versão do normalizador, mantenha RAW/registro de artefatos e auditoria, recrie as tabelas derivadas e reaplique os artefatos originais. A normalização não depende de tokens ou consultas novas ao catálogo.

## Armazenamento de longo prazo

Cada resposta mantém caminho, SHA-256, tamanho original e recebimento. Corpos com os mesmos bytes são guardados uma única vez e comprimidos com gzip quando isso reduz o tamanho. Os caminhos apontam para pequenas referências; `raw:read`, `raw:verify` e replay recuperam e conferem os bytes originais, inclusive no arquivo privado.

O scheduler faz manutenção no máximo uma vez a cada 24 horas. Antes de converter arquivos antigos ou liberar conteúdo da pasta ativa, cria backup dos RAW e do banco/índice, restaura o dump em um banco temporário `_test` e compara todas as tabelas. Por padrão, conteúdos com pelo menos 30 dias podem sair da pasta ativa; o conteúdo original permanece em `storage/archive`. São mantidos três snapshots completos verificados do banco e todos os conteúdos RAW únicos.

```powershell
# Apenas simula, sem converter nem remover arquivos.
docker compose run --rm app php app raw:maintain --archive
# Aplica a política configurada, com backup e restauração verificados.
docker compose run --rm app php app raw:maintain --apply --archive
docker compose run --rm app php app raw:verify
```

A pasta privada está ignorada pelo Git, pelo build Docker e pelo servidor web. Por estar dentro do projeto e no mesmo disco, precisa também de cópia em outro disco para proteção contra falha física. O painel mostra os tamanhos ativos e do arquivo separadamente. O banco e a auditoria continuam crescendo, mesmo sem duplicar corpos JSON. [Configuração, restauração e limites](docs/raw-storage.md).

## Testes

```bash
docker compose run --rm app composer test
docker compose exec db sh -c 'createdb -U "$POSTGRES_USER" listenlab_test'
docker compose run --rm app composer test:all
docker compose run --rm app composer lint
docker compose run --rm app composer validate --strict
```

Crie o banco de testes apenas uma vez. Os testes de integração exigem `TEST_DB_NAME` separado do banco principal e terminando em `_test`; usam schemas temporários isolados e fixtures sintéticas. Nunca usam credenciais Spotify reais. A CI executa a mesma suíte com PostgreSQL 17.

Se os testes de integração pararem nessa validação, confira `POSTGRES_DB` e `TEST_DB_NAME` no `.env`: no setup padrão, são `listenlab` e `listenlab_test`, respectivamente. `TEST_DB_NAME` vazio ou igual ao banco principal é recusado antes da conexão. Não altere o banco principal para `_test` para tentar corrigir isso. O Compose também configura `safe.directory=/app` apenas para os processos dos containers do projeto, evitando o aviso de propriedade do Git em bind mounts do Windows.

Cobertura: identidade determinística, timestamps/timezones, artistas múltiplos, `NULL`, local tracks, replay da mesma track em outro horário, mesma resposta/mesmo artefato repetido, erro parcial/JSON malformado, paginação/overlap/orçamento, quotas/retry, OAuth state/refresh/revogação e integridade RAW/constraints/lock.

## Estrutura e decisões

```text
Spotify API → conteúdo RAW + referência → batch/artefato/ocorrências
                                            ↓
                         catálogo + plays + evidências + problemas
```

- [Schema e limites da primeira versão](docs/schema.md).
- [Documentação oficial consultada e regras atuais](docs/spotify-api.md).
- [001 — RAW como fonte da verdade](docs/decisions/001-raw-is-source-of-truth.md).
- [002 — Tracks específicas da plataforma](docs/decisions/002-provider-specific-tracks.md).
- [003 — Deduplicação conservadora por fonte](docs/decisions/003-conservative-source-deduplication.md).
- [004 — Monitor local e sinal do agendador](docs/decisions/004-local-collection-monitor.md).
- [005 — Conteúdo RAW compartilhado e arquivamento verificado](docs/decisions/005-shared-raw-storage.md).
- [008 — Auditoria detalhada para conteúdo novo e exceções](docs/decisions/008-compact-item-audit.md).

`storage/raw`, `tokens`, relatórios, `.env`, dumps e diretórios de dados pessoais ficam fora do Git. Não coloque exports na raiz do repositório. Guarde-os em `storage/raw/spotify/exports/` e faça backup privado dos RAW, do índice de artefatos e do banco. Permissões de arquivo protegem contra alterações acidentais; não substituem backup ou armazenamento WORM.

Exports Account/Extended aguardam arquivos reais: primeiro inspecionar estruturas, tipos, timestamps, mídias e sobreposição. Importadores, matching cross-source e agrupamento de obras/gravações são etapas futuras. O monitor operacional e os rankings/distribuições locais já estão disponíveis. Não foi definida equivalência entre `API.played_at` e `Extended.ts`.

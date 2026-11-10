# Resultado do primeiro experimento

Data: 10/11/2026. **Sync autenticado e primeiro probe validados no Windows pelos logs fornecidos pelo proprietário.** A autorização e a coleta ocorreram no computador local; os resultados reais abaixo são evidência relatada pelo usuário, separada dos testes sintéticos executados em nuvem. A janela observada é uma medição pontual, sem garantia de retenção futura.

## Coleta real relatada pelo proprietário

Após confirmar que os testes locais passaram, o proprietário executou `docker compose run --rm app php app spotify:sync` e compartilhou o resumo do batch 2:

| Métrica informada pelo coletor | Resultado |
| --- | --- |
| Status | `completed` |
| Registros recebidos | 50 |
| Novos registros da fonte | 0 |
| Ocorrências já ingeridas | 50 |
| Plays criadas | 0 |
| Registros com falha | 0 |
| Páginas | 2 |
| Motivo de parada | `empty_page` |
| Intervalo coberto nesta execução | Aproximadamente 3 horas |
| Arquivos RAW indicados no resumo | 2, incluindo a resposta vazia final |

O resultado é consistente com a idempotência: todas as ocorrências recebidas já tinham evidência e nenhuma play nova foi criada nessa execução. O log não contém o resultado do batch anterior nem o total de plays do banco; não inferimos esses números. Não foram copiados nomes de músicas, identidades da conta, payloads pessoais ou timestamps individuais para este documento.

As duas páginas representam uma página com 50 itens e uma página vazia de encerramento do sync. Esse comportamento isolado não mede a capacidade de retroceder no histórico. O probe relatado a seguir investigou o cursor `before`. Os logs iniciais não incluíam resultado de `raw:verify`; a conferência local foi realizada posteriormente e está registrada abaixo.

## Primeiro probe real

O proprietário também compartilhou o relatório JSON do batch 3, operação `probe`:

| Métrica informada pelo coletor | Resultado |
| --- | --- |
| Status e parada | `completed`, `empty_page` |
| Registros recebidos / identidades distintas observadas | 50 / 50 |
| Registros novos / já existentes | 0 / 50 |
| Plays criadas / falhas / parcialmente processados | 0 / 0 / 0 |
| Páginas / respostas HTTP | 2 / 2 |
| Intervalo temporal observado | 3 horas e aproximadamente 34 segundos |
| Artefatos RAW indicados | 2 |

A consulta inicial devolveu 50 itens; a consulta anterior usando o cursor fornecido recebeu uma página vazia. O probe não esgotou o orçamento de 100 páginas. Essa conta, nessa execução, não forneceu eventos anteriores por esse percurso. Não concluímos que existe uma retenção universal de 50 eventos ou de três horas, nem que toda reprodução real foi exposta pelo endpoint.

## Frequência inicial

Cinco minutos é a configuração conservadora inicial, não uma exigência do Spotify. Como o primeiro probe só disponibilizou os 50 eventos recentes, intervalos longos aumentam o risco de eventos saírem da janela entre coletas bem-sucedidas. O volume de eventos, sequências de skips, indisponibilidade do PC e quotas precisam continuar sendo observados.

Intervalos de 10–15 minutos podem reduzir chamadas e armazenamento RAW, com menor margem contra lacunas. Nenhuma alteração de frequência foi aplicada ao ambiente Windows pelo assistente. A configuração permanece em `SYNC_INTERVAL_SECONDS`; após alterá-la no `.env`, recrie o container do scheduler com `docker compose --profile schedule up -d --force-recreate scheduler` para atualizar as variáveis de ambiente.

## Validação já realizada

### Continuidade local em 10/11/2026

A atualização do monitor foi integrada à pasta Windows existente. Após adicionar tema claro/escuro, o catálogo de testes e a separação explícita em `/dashboard` e `/tests`, a suíte local final passou com **67 testes e 568 assertions**, em PHP 8.4.27 e PostgreSQL 17, usando somente `listenlab_test` e schemas temporários. Composer, lint PHP e rotas HTTP locais passaram. Os testes HTTP permanentes usam uma conexão somente leitura; o teste do scheduler confirma heartbeat durante uma tarefa sintética e encerramento por SIGTERM. O catálogo é conferido contra os métodos e variações da suíte. A lógica do tema e da busca foi validada em execução JavaScript isolada, sem considerar isso uma revisão visual do navegador.

`raw:verify` confirmou SHA-256 dos 48 artefatos registrados na verificação final, incluindo novos RAW do scheduler. Os arquivos privados anteriores e os registros existentes de plays/artefatos mantiveram seus hashes. A migration 002 acrescentou a tabela operacional, preservando o volume e as tabelas de histórico. Monitor e scheduler estão ativos localmente; o intervalo efetivo observado foi de 300 segundos, com última coleta concluída. A inspeção visual foi bloqueada pela ferramenta por não conseguir confirmar a URL do Chrome e permanece pendente. Não houve commit ou push. Ver [continuidade no VS Code](continuar-no-vscode.md).

### Evidência anterior registrada no pacote

PHP 8.4.27, PostgreSQL 17 em Docker e fixtures inteiramente sintéticas. A base de ingestão foi validada com 53 testes e 158 assertions. A versão do monitor recebida no pacote passou com **60 testes e 187 assertions**. Lint PHP e validação estrita do Composer também passaram.

O monitor foi validado por HTTP com PostgreSQL e fixtures em banco separado: rotas da página/assets/API, filtros, detalhes, restrição a GET/HEAD e bloqueio de caminhos privados. Um processo real do scheduler com tarefa sintética confirmou sinal periódico, alternância de espera/coleta, intervalo efetivo de 15 segundos em teste e encerramento por SIGTERM com remoção da próxima execução. Nenhuma chamada Spotify foi feita nessa validação. A conferência visual no navegador ficou pendente porque o serviço de prévia não conseguiu abrir o relay da porta 4173; a interface não foi apresentada como visualmente validada.

| Cenário sintético | Resultado |
| --- | --- |
| Mesmo payload com 3 eventos, processado duas vezes | Primeira execução: 3 plays; segunda: 0 novas, 3 existentes; 6 ocorrências auditáveis |
| Mesma track às 07:00 e 07:08 | 2 plays distintas; uma entidade de catálogo |
| Track com dois artistas | 2 relações em ordem |
| Duração da track conhecida, tempo escutado/skip ausentes | `duration_ms=180000`, `ms_played=NULL`, `skipped=NULL` |
| 2 páginas sobrepostas com 4 ocorrências | 3 identidades distintas, 3 plays, 1 ocorrência duplicada |
| Probe com `next=null`, mas `cursors.before` disponível | Retrocedeu pelo cursor retornado e parou na página vazia |
| Orçamento esgotado/página repetida | Status parcial e motivo explícito; RAW preservado |
| Um timestamp impossível entre eventos válidos | Só o item inválido rejeitado, issue registrada e RAW completo mantido |
| JSON da resposta inválido ou erro 429 | RAW salvo, batch falho e erro auditável |
| Tabelas derivadas esvaziadas, RAW reaplicado sem API | 3 plays reconstruídas e os artefatos originais preservados |
| JSON válido com `\u0000`, incompatível com JSONB | RAW intacto, issue explícita, demais itens processados |

Esses números demonstram comportamento da implementação, não limites ou dados da API da conta.

Também foram verificados HTTPS com a documentação oficial usando o trust store do ambiente, proteção das rotas do callback contra acesso ao `.env` e ausência do code/state nos logs do callback. O serviço de callback foi parado após o teste; o PostgreSQL 17.11 permanece disponível.

Durante a preparação em nuvem, o CLI foi executado sem credenciais no banco desse ambiente: batch 1 registrado como `failed`, erro de configuração explícito, zero respostas HTTP e zero plays. Isso validou a falha auditável antes da autorização. Esse banco é independente do banco Windows que produziu o batch 2 relatado acima. O `.env` do workspace em nuvem contém a senha local de desenvolvimento e a seleção do override privado de certificados; não deve substituir o `.env` já configurado no Windows.

## Próximas verificações na conta real

Com o OAuth, o sync e o primeiro probe funcionando, confira o histórico e os RAW:

```bash
docker compose run --rm app php app history:list --details --limit=50
docker compose run --rm app php app raw:verify
```

Em novos experimentos, registre neste documento uma síntese sem dados pessoais desnecessários: IDs de batches, páginas/respostas, total recebido, total distinto observado, intervalo e motivo de parada. Os relatórios privados completos já são salvos automaticamente no banco e em `storage/reports/`.

Se o limite de páginas for atingido, o resultado é parcial. Se a API não retroceder, documente o comportamento observado sem concluir que o limite é universal. O sucesso do teste de idempotência real depende de comparar o mesmo conjunto: eventos novos ocorridos entre dois syncs devem ser inseridos normalmente.

Importação repetida de um arquivo histórico (caso 6 do briefing) será testada quando existirem arquivos reais e um importador específico. O replay atual cobre respostas RAW da API e não substitui essa validação.

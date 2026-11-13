# Auditoria enxuta das coletas

[Índice](../README.md) · [Fluxo da ingestão](../funcionamento-atual.md#5-resposta-ocorrência-evidência-e-play) · [Comandos e contadores](comandos.md)

Em 11/11/2026, o proprietário autorizou parar de criar detalhes individuais para repetições idênticas já conhecidas. A ingestão cria `ingestion_records` para eventos novos, variantes de conteúdo e exceções. [Decisão 008](../decisions/008-compact-item-audit.md).

## Exemplo

Uma coleta recebe 50 itens, dos quais 48 já têm evento e conteúdo completo processados na versão atual do normalizador:

| Campo do relatório | Valor |
| --- | ---: |
| `api_records_received` | 50 |
| `plays_created` | 2 |
| `already_ingested` | 48 |
| `audit_records_created` | 2 |
| `duplicate_records_omitted` | 48 |

O relatório e `ingestion_batches.metadata` recebem `audit_policy=distinct_payloads_v1`. O dashboard continua mostrando recebidos, novas plays e já existentes, com a mesma interpretação. A quantidade de linhas de `ingestion_records` deixa de representar todos os itens recebidos; use os contadores de batches para esse total.

## O que continua detalhado

- Uma reprodução ainda não conhecida: cria play, evidência e primeira ocorrência.
- A mesma identidade com outro conteúdo: cria detalhe da variante, reutilizando a play. Repetir uma variante completa e já processada pode ser omitido.
- Dados incompletos, erros e mídias para revisão: conservam detalhe por recebimento.
- Outra versão do normalizador: a evidência de processamento anterior não autoriza sozinha a omissão.

A igualdade é do hash canônico do item inteiro, incluindo campos desconhecidos. O mesmo ID e horário com outro nome, contexto ou campo adicional não é descartado sem registro da variante. As regras existentes de atualização do catálogo não mudam: a variante fica preservada no RAW e na auditoria; não provoca atualização automática de metadados de uma play já deduplicada.

Ouvir a mesma música em outro horário continua produzindo uma nova reprodução. Hashes de payload, versão da chave e identidade das plays existentes não foram alterados.

## Preservação e limite da auditoria

Todas as respostas continuam preservadas no RAW com seu recibo e hash, inclusive as que só contêm itens repetidos. Antes de interpretar uma página, a ingestão confere seu corpo contra o hash do artefato registrado. Um artefato sem detalhes por item não pode ser usado com outro conteúdo silenciosamente.

Os registros antigos ficam intactos, incluindo JSONB legado e ocorrências duplicadas anteriores. Não houve limpeza do histórico, alteração de `.env`, remoção de RAW ou redução da política de backups. Não há nova migration ou alteração de permissões. Em outra instalação, atualize os arquivos e reinicie o processo do coletor para carregar o código; os mounts do Compose usam a pasta do projeto.

O detalhe individual de cada repetição omitida deixa de existir. A origem recebida permanece no RAW e o resultado agregado no batch. Investigar uma repetição específica pode exigir ler/reprocessar os originais, sem recuperar necessariamente o instante ou a decisão original por item.

O ganho é evitar crescimento de linhas para repetições normais. RAW, recibos, metadados de batches e relatórios continuam crescendo; o espaço total deve ser medido novamente antes de substituir as estimativas anteriores por uma previsão anual.

## Validação registrada em 11/11/2026

Os testes usam apenas fixtures e bancos separados terminados em `_test`. Cobrem a janela de 50 itens com dois novos, preservação das linhas anteriores, variantes, replay, erros/incompletos, JSON incompatível, payload legado, versão do normalizador, adulteração de RAW sem auditoria por item e dois processos concorrentes. A suíte completa também verifica restauração de backups, análises e monitor.

Validação final: **120 testes e 1.196 assertions**, sem falhas ou skips, e lint PHP aprovado. Na primeira coleta real após retomar o scheduler, foram recebidos 50 itens: quatro plays novas, quatro registros detalhados e 46 repetições omitidas. A execução terminou como `completed` e o agendador voltou a aguardar com intervalo de 900 segundos. Os hashes do `.env` e das linhas anteriores de plays, evidências, auditoria e índice RAW permaneceram iguais ao baseline privado. A verificação integral conferiu 195 artefatos RAW por SHA-256. Os checks HTTP do monitor e os bloqueios de origem/caminhos privados passaram; a varredura de privacidade não encontrou credenciais ou arquivos pessoais nos candidatos ao Git.

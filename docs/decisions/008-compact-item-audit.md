# 008 — Auditoria detalhada somente para conteúdo novo e exceções

Status: aceita por solicitação do proprietário em 11/11/2026. Substitui a parte da decisão 003 que gravava uma linha em `ingestion_records` para todo item de todo recebimento. As identidades das plays e o princípio de RAW como fonte da verdade permanecem.

Uma reprodução válida, completa e já conhecida, com o mesmo hash canônico de payload e comprovadamente processada na mesma versão do normalizador, entra nos contadores de recebidos/processados/repetidos sem criar outra linha por item. Uma nova identidade continua criando sua play, evidência e primeira ocorrência. Ouvir a mesma faixa em outro horário é outro evento.

Uma identidade conhecida com conteúdo diferente ganha uma ocorrência detalhada ligada à evidência existente, sem outra play. Quando essa mesma variante completa é recebida novamente, ela também pode ser omitida. Hashes contemplam todos os campos, inclusive desconhecidos pelo parser. Ordenação de propriedades e espaços do JSON não constituem outra variante de item; os corpos RAW continuam distintos por bytes.

Erros, itens pendentes de revisão e dados incompletos conservam o detalhe por recebimento. Itens incompletos mantêm a execução como parcial mesmo quando a identidade já existe. A versão do normalizador participa da autorização para omitir: um resultado de outra versão não é suficiente. O hash canônico é o existente; não se altera a chave ou a versão do parser para mudar apenas a política de auditoria.

Os relatórios acrescentam `audit_policy=distinct_payloads_v1`, `audit_records_created` e `duplicate_records_omitted`. `already_ingested` continua incluindo todos os eventos com evidência existente, tanto variantes detalhadas quanto repetições omitidas. Os contadores são por execução, não por linha persistida. Não foram introduzidos contadores por evento que exigissem atualizações a cada repetição.

Cada resposta HTTP continua com artefato/recibo próprio, SHA-256, timestamp e conteúdo RAW compartilhado/comprimido. A ingestão compara o hash do corpo inteiro com o índice imutável antes de processar, inclusive quando não existem linhas detalhadas para aquele artefato. JSON com U+0000 continua rejeitado por item com RAW preservado; o uso de hash no banco não relaxa essa regra.

A decisão de omitir e a criação de play/evidência/ocorrência usam o mesmo lock transacional da chave na fonte. As constraints de unicidade seguem sendo a última defesa. Se um escritor externo ganhar a constraint, a tentativa inteira é revertida e reavaliada contra a evidência vencedora. Não ficam plays, catálogo ou ocorrências candidatos órfãos.

As linhas antigas são preservadas. A primeira ocorrência legada com JSONB pode comprovar igualdade canônica sem reescrever seu payload. Não há DELETE, backfill ou migration de dados. O código usa os índices existentes por chave e as relações atuais; os grants do monitor não mudam.

O tradeoff aceito é deixar de guardar o instante e resultado individual do processamento de cada repetição omitida. Os contadores por coleta e a versão da política continuam disponíveis; o conteúdo recebido continua no RAW. Consultar em quais respostas um evento apareceu pode exigir ler/reprocessar os originais. Isso não recria necessariamente a decisão e o horário originais por item.

Replay, paginação e limite de páginas mantêm seus contadores mesmo quando não criam registros detalhados. As plays e os rankings permanecem baseados nas identidades canônicas existentes. A redução de linhas vale para novas coletas; não reduz retroativamente o espaço já ocupado. As estimativas anteriores de crescimento de ocorrências descrevem o modelo antigo e precisam de novas medições após a mudança.

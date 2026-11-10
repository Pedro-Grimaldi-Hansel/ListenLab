# 001 — RAW é a fonte da verdade

Status: aceita. Data: 10/11/2026.

Cada resposta musical HTTP é gravada com seus bytes de corpo intactos antes de parsing, interpretação de status, retry ou transação de normalização. Arquivos têm nome temporal com batch e aleatoriedade, criação exclusiva, publicação sem sobrescrita, `fsync`, SHA-256 e permissão final `0440`. Falhas de JSON, estrutura e normalização não removem o arquivo.

O índice `raw_artifacts` guarda caminho relativo, hash, tamanho, recebimento, status e metadados da chamada sem Authorization. Um trigger rejeita UPDATE/DELETE desse índice. JSONB operacional auxilia auditoria; não substitui o arquivo. Respostas OAuth são credenciais e ficam apenas no armazenamento privado de tokens.

Filesystem e PostgreSQL não têm transação distribuída. A ordem é salvar o RAW e depois registrar no banco. Uma falha no segundo passo deixa um arquivo órfão preservado e seu caminho é reportado; deve ser reconciliado antes de qualquer limpeza. Um crash durante escrita pode deixar `.partial`, que também não é apagado automaticamente. Não existe atomicidade falsa entre os dois sistemas.

Replay verifica o hash e usa dados originais, sem buscar o catálogo novamente. A projeção relacional e seus vínculos operacionais podem ser refeitos. Não existe comando destrutivo de rebuild nesta entrega.

Imutabilidade é garantida pelo caminho normal da aplicação e por proteções contra alterações acidentais. O proprietário do filesystem ainda pode mudar permissões; faça backup privado, idealmente com versionamento/imutabilidade no destino. Backup de RAW é prioritário e precisa preservar seu índice/proveniência. Nenhum dado real ou token será versionado.

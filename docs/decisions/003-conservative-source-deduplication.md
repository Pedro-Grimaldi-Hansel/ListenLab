# 003 — Deduplicação conservadora dentro da fonte

Status: aceita para a primeira vertical slice. Data: 10/11/2026.

Chave API v1: SHA-256 de uma representação determinística contendo versão da chave, `played_at` validado em UTC com microssegundos e identidade da track. A identidade usa Spotify ID quando fornecido; senão URI; sem ambos, fingerprint conservador do payload completo. Nomes/contexto/metadados mutáveis não participam da chave quando há identidade externa.

`play_evidence` tem `UNIQUE(source_id, source_record_key)`. Usamos transações por item, lock transacional por chave e INSERT com ON CONFLICT. O coletor também tem um advisory lock de sessão para serializar execuções da primeira versão. Nenhuma garantia depende de um `exists()` PHP, checkpoint, cron ou memória de processo.

Cada recebimento é uma ocorrência em `ingestion_records`, ligado ao seu RAW. Um overlap liga outra ocorrência à evidência existente. Repetir o mesmo artefato/posição também é seguro. O catálogo/play candidato é revertido se outra transação ganhar a constraint: não ficam plays órfãs por conflito.

Limites explícitos: a API não fornece um identificador único do evento. Dois eventos da mesma track no mesmo instante e mesma precisão são indistinguíveis nesta chave, embora ambas as ocorrências e bytes permaneçam preservados. Mudança de identidade entre um payload parcial e outro completo pode manter dois eventos; sem dados suficientes, não tentamos uni-los por nome. A versão atual pressupõe uma única conta pessoal por dataset. Suporte a outras contas exige escopo de conta na fonte/chave, preferencialmente usando o `account_id` estável atualmente documentado, antes de habilitar troca de contas.

Cross-source matching é outro problema. Fontes Account, Extended e API permanecem distintas. Não comparamos `API.played_at == Extended.ts`, nem deduzimos início/término ou fundimos eventos por proximidade. Ao chegar dados sobrepostos reais, estudaremos regras/confiança. Em ambiguidade, preservar separados.

Mudanças de chave/normalizador exigirão decisão explícita e eventual reconstrução das projeções usando RAW. Não mude a versão da chave em um banco existente esperando que isso seja automaticamente idempotente.

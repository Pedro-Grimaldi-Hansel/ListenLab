# Decisões de arquitetura

[Índice](../README.md) · [Funcionamento](../funcionamento-atual.md)

As decisões registram as razões e os compromissos das regras do sistema. Leia as complementações: uma decisão posterior pode substituir parte do texto anterior sem invalidar suas outras garantias.

| Decisão | Data | Regra e evolução |
| --- | --- | --- |
| [001 — RAW como fonte da verdade](001-raw-is-source-of-truth.md) | 10/11/2026 | Preservar antes de interpretar; representação física complementada pela 005 |
| [002 — Tracks específicas do provedor](002-provider-specific-tracks.md) | 10/11/2026 | Separar catálogo de eventos e não fundir versões por nome/ISRC |
| [003 — Deduplicação conservadora](003-conservative-source-deduplication.md) | 10/11/2026 | Identidade, locks e constraints; detalhe de repetidos substituído pela 008; conta protegida pela 006 |
| [004 — Monitor e heartbeat](004-local-collection-monitor.md) | 10/11/2026 | Sinal explícito do agendador e web local de leitura; análises ampliadas pela 007 |
| [005 — Conteúdo compartilhado e arquivo](005-shared-raw-storage.md) | 10/11/2026 | Compressão sem perdas e liberação de cópias ativas após backup restaurado e conferido |
| [006 — Isolamento e conta vinculada](006-local-security-boundaries.md) | 10/11/2026 | Secret/role web próprios, origem local, vínculo de conta e proteções de manutenção; leitura ampliada pela 007 |
| [007 — Análises locais](007-local-listening-analytics.md) | 11/11/2026 | Contagem por play, períodos locais e catálogo restrito; apresentação complementada pela 009 |
| [008 — Auditoria enxuta](008-compact-item-audit.md) | 11/11/2026 | Repetições completas conhecidas só nos contadores; novos, variantes e exceções detalhados |
| [009 — Análises como início](009-analytics-first-interface.md) | 11/11/2026 | `/` leva a `/analytics`; navegação única, temas e interface adaptável |

As descrições atuais de comandos, gráficos e permissões estão nos [guias e referências](../README.md). Os textos datados das decisões não substituem conferir o código em revisão.

Para uma nova mudança de regra, acrescente uma decisão numerada com contexto, comportamento escolhido, preservação/migração, consequências e relação com as anteriores. Não reescreva a motivação histórica para esconder uma mudança de direção.

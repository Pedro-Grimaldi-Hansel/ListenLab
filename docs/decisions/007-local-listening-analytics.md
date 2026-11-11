# 007 — Rankings e distribuição das reproduções registradas

Status: aceita por solicitação do proprietário em 11/11/2026. Amplia a decisão 004 com uma tela analítica local e complementa o escopo de leitura da decisão 006.

`/analytics` apresenta rankings de faixas, artistas e álbuns e distribuições por hora, dia da semana e data. A unidade de medida é uma linha de `plays`. Recebimentos em `ingestion_records` e evidências adicionais não multiplicam os eventos. Cada artista creditado recebe uma contagem por play, mesmo que apareça em várias posições; por isso as participações dos artistas podem somar mais de 100%. Faixas, artistas e álbuns mantêm suas identidades de catálogo, sem fusão por nome ou ISRC. Metadados refletem a projeção atual do catálogo.

O período padrão é todo o histórico coletado. Os atalhos de 7, 30 e 90 dias usam dias do calendário local, incluindo hoje. Datas personalizadas incluem os dois extremos. Os limites locais são convertidos para um intervalo UTC fechado no início e aberto no fim, preservando o índice de `plays.played_at` e as transições de horário de verão. A série diária é limitada aos últimos 90 dias do recorte; esse limite fica visível e não reduz os rankings, totais ou outras distribuições. Rankings têm Top 10, 20 ou 50, com desempate por nome e ID interno.

Os painéis de uma resposta usam a mesma transação REPEATABLE READ, READ ONLY. Não há escrita, chamada Spotify, alteração da ingestão ou cálculo de duração. Dias e horários sem eventos aparecem com zero, sem afirmar cobertura contínua da escuta. O primeiro/último evento e o alcance da base ficam visíveis; uma coleta concluída não prova histórico vitalício completo.

Para atender a solicitação, a role web passa a ler apenas `id`, `track_id` e `played_at` de `plays`; `id`, `name` e `album_id` de `tracks`; `id` e `name` de artistas/álbuns; e as colunas da relação `track_artists`. O provisionamento continua administrativo, preserva o secret e não concede escrita, payloads, contexto de escuta, credenciais ou vínculo privado da conta. Isso substitui a restrição inicial da decisão 006 que permitia somente contar IDs de plays. Nenhuma migration ou modificação dos registros é necessária.

A interface usa os temas e a navegação existentes, com HTML/CSS/JavaScript locais, sem CDN ou dependências novas. Os dados são consultados ao abrir a página ou aplicar/atualizar filtros. A coleta automática e o dashboard operacional mantêm seu funcionamento. A aplicação e seu histórico continuam restritos à máquina local.

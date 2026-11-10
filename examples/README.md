# Exemplos públicos e sintéticos

[`spotify-recently-played.mock.json`](spotify-recently-played.mock.json) ilustra o corpo JSON de uma resposta HTTP 200 do endpoint `GET /v1/me/player/recently-played`, com alguns dos campos usados pelo ListenLab. Todos os nomes, IDs, contextos e horários foram inventados. Não foi copiado nem anonimizado um RAW pessoal.

O arquivo contém três eventos: a faixa A às 12:00, a faixa B às 12:04 e a faixa A novamente às 12:08, em uma data fictícia. O JSON foi organizado do evento mais recente para o mais antigo. São três plays e duas faixas. Receber a mesma resposta novamente continua produzindo três plays; a aplicação registra outra consulta e reutiliza o corpo idêntico.

| Campo | Significado no exemplo |
| --- | --- |
| `items` | Eventos disponibilizados nesta página |
| `played_at` | Timestamp do evento, em UTC |
| `track` | Identidade e metadados da faixa, álbum e artistas |
| `track.duration_ms` | Duração de catálogo da faixa; não indica tempo efetivamente ouvido |
| `context` | Contexto da reprodução; pode ser `null` |
| `limit` | Limite solicitado por página; o exemplo traz apenas três itens |
| `next` | `null` neste exemplo; o coletor segue a paginação fornecida pelo serviço |
| `cursors` | Valores ilustrativos da página fictícia; não use estes valores em consultas reais |

Este é um exemplo reduzido, não uma especificação completa da API. A resposta não inclui `ms_played`, `skipped` ou `shuffle`: o ListenLab não deduz esses campos. Os IDs sintéticos não correspondem a recursos Spotify e não devem ser usados em chamadas à API.

Não há token, cabeçalho Authorization ou identidade da conta no arquivo. O JSON pode ser versionado no GitHub. Os RAW reais continuam exclusivamente nas pastas privadas; novos exemplos também devem ser escritos do zero.

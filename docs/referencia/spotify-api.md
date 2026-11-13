# Integração com a Spotify Web API

[Índice](../README.md) · [Instalação e OAuth](../guias/instalacao.md) · [Funcionamento](../funcionamento-atual.md)

Referências oficiais consultadas em **12/11/2026**. Esta página distingue o contrato externo das escolhas do ListenLab. Regras de acesso e quotas podem mudar; não há número fixo de dias recuperáveis assumido pelo projeto.

## Histórico recente e paginação

O endpoint musical é `GET https://api.spotify.com/v1/me/player/recently-played`, com scope `user-read-recently-played`. A referência informa até 50 itens por página e não oferece episódios de podcast nesse endpoint. O ListenLab usa `limit=50` por padrão e aceita 1–50. [Recently Played](https://developer.spotify.com/documentation/web-api/reference/get-recently-played).

`after` e `before` são posições exclusivas em milissegundos Unix, mutuamente excludentes. A resposta pode fornecer `next` e cursores; `before` é descrito para a página anterior. O sync segue `next`; o probe retrocede por `cursors.before`, inclusive quando `next` é nulo. [Contrato de paginação](https://developer.spotify.com/documentation/web-api/reference/get-recently-played).

A implementação valida HTTPS, `api.spotify.com`, endpoint, porta e parâmetros, e reconstrói a consulta. Não segue redirects HTTP nem encaminha bearer a outra origem. Orçamentos, página repetida e cursor que não retrocede encerram a execução com motivo explícito. [Api.php](../../src/Spotify/Api.php), [Collector.php](../../src/Ingestion/Collector.php).

Limite por página não define retenção total. O [primeiro probe](../pesquisas/experiment-results.md) é uma medição pontual; `completed` significa parada natural da consulta, sem afirmar histórico vitalício completo.

## OAuth e renovação

O ListenLab usa Authorization Code com Client Secret no backend, state aleatório de uso único e validade local de dez minutos. Tokens são credenciais privadas, não RAW musical. O fluxo oficial está em [Authorization Code](https://developer.spotify.com/documentation/web-api/tutorials/code-flow); a implementação está em [OAuth.php](../../src/Spotify/OAuth.php).

A URI local cadastrada é `http://127.0.0.1:8888/spotify/callback`. O Spotify exige correspondência do retorno, permite HTTP em IP de loopback explícito e não aceita `localhost` nesse cadastro. [Redirect URIs](https://developer.spotify.com/documentation/web-api/concepts/redirect_uri).

A documentação consultada informa validade de seis meses do refresh token desde a autorização; renovar o access token não estende esse período. `expires_in` descreve o access token. `invalid_grant` exige descartar a autorização inválida e refazer OAuth. O ListenLab serializa a renovação, conserva o refresh token anterior quando não há substituto e mantém as datas de autorização. [Refreshing tokens](https://developer.spotify.com/documentation/web-api/tutorials/refreshing-tokens).

## Perfil e conta vinculada

A consulta `GET /v1/me` serve à conferência de `account_id`, descrito pelo Spotify como identificador estável para vínculo de conta. O projeto usa seu hash para impedir mistura de contas; o perfil não é arquivado como resposta musical nem exibido no monitor. [Get Current User's Profile](https://developer.spotify.com/documentation/web-api/reference/get-current-users-profile).

A conferência acontece antes de persistir uma autorização candidata e ao usar um novo access token. Tokens já conferidos reutilizam o resultado. Não são solicitados scopes extras de email ou perfil privado. O dataset aceita uma conta e não oferece troca ou coleta multiusuário. [AccountGuard.php](../../src/Spotify/AccountGuard.php), [decisão 006](../decisions/006-local-security-boundaries.md).

## Acesso, quotas e erros

No Development Mode consultado, o proprietário precisa de Premium e há allowlist de até cinco usuários. As quotas são compartilhadas por apps do mesmo desenvolvedor, com agrupamentos/limites sujeitos a mudança. Excedê-las pode produzir `429` com `QUOTA_EXCEEDED`. [Quota modes](https://developer.spotify.com/documentation/web-api/concepts/quota-modes).

Rate limits são outro mecanismo. A orientação oficial descreve janela móvel de trinta segundos e uso de `Retry-After` quando retornado. [Rate limits](https://developer.spotify.com/documentation/web-api/concepts/rate-limits).

| Resposta | Comportamento do ListenLab |
| --- | --- |
| 200 | Preservar RAW, validar página e normalizar itens |
| 401 | Preservar resposta e tentar uma renovação; persistindo, encerrar |
| 403 | Preservar e encerrar; conferir erro, condições de acesso e allowlist |
| 429 comum | Preservar; repetir respeitando `Retry-After` se couber no limite de espera e tentativas |
| `QUOTA_EXCEEDED` | Preservar e encerrar sem retry cego |
| JSON/estrutura incompatível | Manter RAW e registrar falha/issue |

O backend não encurta uma espera acima do orçamento: encerra a tentativa. Um erro de transporte sem resposta não tem corpo HTTP para preservar. Os detalhes estão em [Api.php](../../src/Spotify/Api.php), [Transport](../../src/Http/GuzzleTransport.php) e [Comandos](comandos.md).

## Semântica e limitações

O parser usa `played_at` como timestamp da fonte, sem reinterpretá-lo como início/término. A duração de catálogo não é tempo ouvido; campos ausentes de escuta/skip/shuffle ficam `NULL`. Metadados opcionais são preservados quando recebidos e não se usa ISRC para unir versões.

Atraso do feed, disponibilidade histórica, quota efetiva e equivalência com exports exigem evidência própria. A [pesquisa de confiabilidade](../pesquisas/spotify-history-reliability.md) distingue documentação, relatos e observação local. O importador Account/Extended e a correspondência entre fontes ainda não existem.

## Mudanças externas acompanhadas

A revisão inicial também consultou os registros oficiais de [fevereiro de 2026](https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide), [março](https://developer.spotify.com/documentation/web-api/references/changes/march-2026), [maio](https://developer.spotify.com/documentation/web-api/references/changes/may-2026) e [julho](https://developer.spotify.com/documentation/web-api/references/changes/july-2026). Esses links documentam evolução externa; a aplicação deve ser revista contra o contrato vigente ao mudar autenticação, campos ou quotas.

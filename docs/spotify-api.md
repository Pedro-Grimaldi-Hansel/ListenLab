# Spotify Web API — verificação em 10/11/2026

Fontes oficiais consultadas diretamente antes da implementação:

- [Recently Played](https://developer.spotify.com/documentation/web-api/reference/get-recently-played).
- [Authorization Code](https://developer.spotify.com/documentation/web-api/tutorials/code-flow).
- [Refreshing tokens](https://developer.spotify.com/documentation/web-api/tutorials/refreshing-tokens).
- [Redirect URIs](https://developer.spotify.com/documentation/web-api/concepts/redirect_uri).
- [Quota modes](https://developer.spotify.com/documentation/web-api/concepts/quota-modes).
- [Rate limits](https://developer.spotify.com/documentation/web-api/concepts/rate-limits).
- [Migração de fevereiro de 2026](https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide).
- [Mudanças de março](https://developer.spotify.com/documentation/web-api/references/changes/march-2026), [maio](https://developer.spotify.com/documentation/web-api/references/changes/may-2026) e [julho de 2026](https://developer.spotify.com/documentation/web-api/references/changes/july-2026).

## Endpoint e paginação

`GET https://api.spotify.com/v1/me/player/recently-played`, scope `user-read-recently-played`. Retorna tracks, atualmente sem episódios de podcast. `limit`: padrão 20, mínimo 1 conforme o texto da referência, máximo 50. O exemplo de range gerado na página começa em 0; a implementação usa o mínimo textual de 1.

`after` e `before` são timestamps Unix em milissegundos, exclusivos e mutuamente excludentes. A resposta fornece `next`, `cursors.after` e `cursors.before`. A documentação descreve `before` como cursor da página anterior. O sync segue o `next` oficial; o probe faz requisições com `before` oficial e registra o comportamento efetivo da conta. A resposta não estabelece um número universal de dias ou eventos recuperáveis. Não inferimos a retenção histórica pelo máximo de 50 itens por página.

URLs `next` só são aceitas para HTTPS, `api.spotify.com`, o endpoint correto e parâmetros documentados. A requisição é reconstruída a partir dos parâmetros, sem seguir redirects HTTP ou enviar bearer a outra origem.

## OAuth e credenciais

Authorization Code é apropriado para esta aplicação server-side, capaz de proteger Client Secret. `/authorize` usa state aleatório; `/api/token` troca o code usando HTTP Basic e form URL encoded. Tokens não aparecem no terminal, URLs da aplicação ou logs. Respostas do endpoint de tokens não são RAW musical: são credenciais privadas em `tokens/`, fora do Git.

O access token usa `expires_in`; o refresh pode devolver um novo refresh token, ou omiti-lo, mantendo o anterior. Conforme a documentação atual, refresh tokens de apps registrados no Dashboard duram seis meses **desde a autorização do usuário**. Renovar o access token não estende essa vida. `invalid_grant` exige descartar a autorização inválida e iniciar novo OAuth. Não presumimos validade indefinida.

Redirect URIs precisam corresponder ao cadastro, usar HTTPS ou HTTP com um IP de loopback explícito. `localhost` não é permitido. O callback local padrão é `http://127.0.0.1:8888/spotify/callback`.

## Dashboard, quotas e mudanças recentes

Development Mode exige Premium ativo do proprietário. Há allowlist e limite de 5 usuários (com condições de grandfathering para apps existentes). A restrição de um Client ID anunciada em fevereiro foi atualizada em julho: até 25 apps por conta de desenvolvedor. Não implementamos lógica dependente desse limite.

Desde julho, quotas de Development Mode são contadas por conta de desenvolvedor e compartilhadas entre seus apps. Buckets e limites podem mudar e não têm um número fixo assumido pelo projeto. Quota excedida pode retornar `429` com `error.reason = QUOTA_EXCEEDED`. Rate limits comuns são outro mecanismo, calculado em janela móvel de 30 segundos, com `Retry-After` quando aplicável.

Alguns campos foram removidos/deprecados em Development Mode. O normalizador tolera campos opcionais ausentes, inclusive `linked_from`; preserva-os quando recebidos. `external_ids` de tracks/álbuns teve a remoção revertida em março; ISRC é opcional, nunca identidade para unificação. Desde maio, o endpoint de perfil oferece `account_id` estável para futuras integrações com múltiplas contas. Esta versão não coleta perfil nem adiciona scopes de email/perfil.

## O que ainda depende dos dados reais

Estabilidade da janela acessível, frequência apropriada, quotas efetivas, NULLs/local tracks reais, semântica de timestamps entre fontes e qualquer matching entre exports e API. O proprietário forneceu logs de sync e probe concluídos no Windows. O primeiro probe recuperou 50 eventos distintos cobrindo aproximadamente três horas e encontrou uma página vazia ao retroceder. Essa medição pontual não determina uma retenção universal de eventos ou tempo. Ver [resultados do experimento](experiment-results.md).

# Confiabilidade do histórico recente do Spotify

> Pesquisa de 10/11/2026, preservada como evidência datada. Algumas propostas de frequência e armazenamento foram implementadas depois, e o vínculo de conta passou a usar identificador estável. Veja o [funcionamento atual](../funcionamento-atual.md) e as decisões [005](../decisions/005-shared-raw-storage.md), [006](../decisions/006-local-security-boundaries.md) e [008](../decisions/008-compact-item-audit.md). Relatos de incidentes não identificam a causa de uma falha atual.

Pesquisa em 10/11/2026. Este documento registra evidências e propostas; não altera o intervalo, as credenciais, o schema ou a política de armazenamento.

## Conclusão

Há evidência histórica de falhas intermitentes e defasagem no endpoint `recently-played`. A documentação consultada não publica um prazo máximo de atualização nem uma rotina de disponibilização a cada determinado número de horas. Não há fundamento para assumir que uma espera fixa de cinco horas seja o funcionamento normal contratado.

O caso observado no ListenLab é compatível com uma defasagem desse feed. Não foi encontrada confirmação pública específica de um incidente geral atual. A pesquisa não prova ausência de incidente, nem identifica a causa interna do comportamento desta conta.

## Fontes e força da evidência

| Fonte | Evidência | Limite da conclusão |
| --- | --- | --- |
| [Referência oficial do endpoint](https://developer.spotify.com/documentation/web-api/reference/get-recently-played) | Define até 50 itens por página, paginação e os parâmetros `after`/`before`. Não publica prazo de atualização do histórico. | O limite por página não estabelece retenção histórica nem garante entrega imediata. |
| [Issue 666 no repositório do Spotify](https://github.com/spotify/web-api/issues/666), outubro de 2017 | Uma resposta técnica atribuiu perda aleatória de eventos em horários de pico à infraestrutura de entrega e informou uma correção. | É um incidente antigo, encerrado, em repositório arquivado. Não demonstra a causa do caso atual. |
| [Issue 918](https://github.com/spotify/web-api/issues/918), 2018–2019 | Relatos de histórico sem avançar por horas ou dias, seguido de retomada. | São relatos primários de usuários; não são um prazo oficial nem um diagnóstico atual. |
| [Spotify API delayed](https://community.spotify.com/forum/en/discussion/7006349/spotify-api-delayed), 12–13/06/2025 | Usuários relataram mais de 90 minutos de atraso na API enquanto o histórico do app estava atualizado; no dia seguinte relataram normalização. Também houve relato de timestamps inconsistentes. | Caso parecido, mas sem confirmação técnica da causa. Não determina um atraso fixo para outras contas. |

Problemas de ordenação de álbuns ou de “Recentes” na interface dos apps não foram usados como prova de incidente neste endpoint da Web API.

## Evidência local

Na investigação desta instalação, as respostas musicais foram preservadas como RAW e os hashes conferidos. As consultas retornaram HTTP 200, sem rejeições de itens, mas não continham as reproduções relatadas pelo proprietário como presentes no histórico do app. O nome do perfil da autorização correspondia ao perfil aberto no Spotify; isso é consistente com a conta informada pelo proprietário, sem constituir uma validação por identificador imutável.

Uma consulta com cabeçalhos de não utilização de cache, a consulta normal de 50 itens, a consulta de um item e a consulta `after` usando o cursor fornecido pelo servidor apresentaram limites coerentes entre si. Não foi encontrado filtro de horário antigo ou cache de respostas no transporte PHP. Posteriormente, o feed avançou e a coleta automática inseriu uma play, passando o total de 50 para 51. Os relatórios privados preservam os detalhes operacionais; este documento não replica títulos ou identificadores pessoais.

Isso comprova divergência entre o conteúdo disponível ao coletor e o histórico relatado no app. Não comprova a topologia dos serviços do Spotify, que todos os eventos ausentes chegarão depois, nem que `played_at` sempre corresponde ao instante esperado pelo usuário.

## Como lidar no ListenLab

1. **Separar conexão e atualidade no dashboard.** Mostrar a última consulta bem-sucedida e o timestamp do último evento retornado. HTTP 200 confirma atendimento da consulta, não completude ou atualidade do histórico. Um evento antigo, isoladamente, também pode significar que a pessoa não ouviu músicas; o aviso deve ser neutro, como “sem novos eventos observados”, até haver evidência adicional.

2. **Continuar aceitando eventos atrasados.** A coleta atual volta à janela recente e deduplica pela identidade da reprodução. Preservar isso. Não substituir por uma regra que descarte eventos anteriores ao maior `played_at` já visto, nem avançar um checkpoint para o horário da consulta: uma chegada tardia pode ter timestamp anterior. A paginação deve continuar usando os cursores e URLs retornados pelo servidor. Consultas com `after`, se adotadas, precisam de sobreposição e varreduras da janela recente; não oferecem recuperação ilimitada.

3. **Medir antes de fixar uma frequência definitiva.** De 10 a 15 minutos pode ser um ponto de partida operacional para reduzir chamadas, não uma garantia de cobertura. Comparar por alguns dias as primeiras aparições, alterações do feed e reproduções de referência anotadas privadamente. `primeira_observação - played_at` mede a diferença entre dois timestamps, não prova sozinho a latência real do serviço. Mais consultas não obrigam o servidor a atualizar seu feed. Em HTTP 429, respeitar `Retry-After`, conforme as [orientações oficiais de limites](https://developer.spotify.com/documentation/web-api/concepts/rate-limits); não insistir em tentativas rápidas.

4. **Reduzir redundância sem apagar a evidência.** Para uma evolução de armazenamento, guardar conteúdo idêntico uma vez por hash e manter referências de cada recebimento, horário e resultado. A auditoria também pode referenciar um payload compartilhado em vez de repetir JSON completo. Prever compressão sem perdas, verificação de integridade, replay e compatibilidade com os RAW existentes. Isso exige desenho e testes próprios, não apenas deixar de salvar uma resposta porque os IDs dos eventos já existem: os metadados podem ter mudado.

5. **Adicionar observação de reprodução apenas como fonte complementar.** O endpoint [currently-playing](https://developer.spotify.com/documentation/web-api/reference/get-the-users-currently-playing-track) informa estado atual e requer `user-read-currently-playing`. Uma integração futura exigiria ampliar a autorização. Um snapshot deve ser armazenado como observação, separado das plays confirmadas pelo histórico; amostragem pode perder transições e não reconstrói automaticamente tempo ouvido, skips ou todas as reproduções.

6. **Conferir o longo prazo com export oficial.** O [Extended Streaming History](https://support.spotify.com/us/article/understanding-your-data/) é descrito pelo Spotify como histórico da vida da conta, com tempo ouvido e horário de término em UTC. Pode ser uma fonte complementar para investigar lacunas. O importador ainda precisa ser implementado e validado com arquivos reais; não há equivalência automática entre esse horário de término e `API.played_at`. Manter as fontes separadas até definir regras de correspondência.

## Próximas alterações propostas

Prioridade inicial: indicador de último evento recebido e avisos neutros de ausência de novidades, junto de economia de armazenamento por conteúdo. Depois, medição de chegadas tardias e importação de exports para conferência. A observação de reprodução atual é opcional e não oferece garantia de cobertura completa.

Antes de mudar cursores ou armazenamento, acrescentar cenários sintéticos para evento atrasado com timestamp anterior ao mais recente, resposta HTTP 200 antiga, sequência idêntica recebida várias vezes, metadados alterados sem nova play e retomada após um período sem novidades. O monitor não deve afirmar que o histórico está completo por causa do sucesso HTTP.

# Análises das reproduções

[Índice](../README.md) · [Monitor](monitor.md) · [Funcionamento](../funcionamento-atual.md)

Abra [127.0.0.1:8787/analytics](http://127.0.0.1:8787/analytics), também a página inicial de `/`. A tela lê as reproduções já registradas no PostgreSQL, sem chamar o Spotify. As regras abaixo refletem o código local revisado em **12/11/2026**, inclusive extensões das análises ainda presentes como alterações locais.

## Filtros e atualização

Escolha todo o histórico, últimos 7/30/90 dias ou datas personalizadas, e Top 10, 20 ou 50. Atalhos de período e Top consultam ao selecionar. Datas personalizadas exigem os dois limites e **Aplicar**. **Atualizar** refaz a consulta selecionada.

Os atalhos incluem hoje e os dias anteriores do calendário em `MONITOR_TIMEZONE`, padrão `America/Sao_Paulo`. Um período personalizado inclui as duas datas inteiras. Os limites locais são convertidos para início UTC inclusivo e fim exclusivo; o banco continua armazenando horários em UTC.

A tela não atualiza automaticamente. O horário da consulta aparece no rodapé, e o dia atual pode estar incompleto. Fechar ou atualizar a página não altera o agendador.

## Resumo e métricas

| Métrica | Como é calculada |
| --- | --- |
| Reproduções | Quantidade de linhas de `plays` no recorte |
| Faixas, artistas e álbuns | Identidades distintas do catálogo relacionadas às plays do recorte |
| Dias ativos | Datas locais com pelo menos uma play registrada |
| Média por dia ativo | Plays divididas por dias com registros; sem dados, fica indisponível |
| Participação dos artistas mais ouvidos | Plays que incluem pelo menos um dos dez artistas mais ouvidos, ou todos se houver menos de dez |

Na concentração dos artistas, cada play conta uma vez mesmo com várias colaborações do Top 10. Essa métrica não depende do Top escolhido para exibir o ranking. Dias sem registros ficam fora da média por dia ativo; ela não representa média por todos os dias do calendário.

## Comparação com o período anterior

Para recortes com datas, o código calcula uma janela anterior com a mesma quantidade de dias de calendário. Se hoje estiver incompleto, compara com o mesmo trecho transcorrido do último dia anterior correspondente, no fuso local.

A comparação fica indisponível para todo o histórico, datas futuras, janela anterior fora do intervalo suportado ou quando o primeiro evento da base não alcança o começo do período anterior. Alcançar essa data não prova cobertura contínua: ambas as janelas podem ter lacunas.

Variações de contagens e média são percentuais sobre o valor anterior, quando maior que zero. A concentração usa diferença em pontos percentuais. Quando o valor anterior é zero ou ausente, não há percentual calculável.

## Rankings

As abas mostram faixas, artistas e álbuns, com quantidade e participação sobre o total de plays do recorte. Colaborações contam uma vez para cada artista creditado; por isso a soma das participações dos artistas pode superar 100%.

Identidades diferentes do catálogo ficam separadas, mesmo com o mesmo nome. Empates são ordenados por nome e ID interno. O Top pode cortar itens com a mesma contagem. Metadados são os atuais da projeção do catálogo.

Cada linha mostra primeira e última reprodução daquela identidade em **todo o histórico registrado**, não apenas no filtro. Quando há comparação, a mudança de posição usa o ranking completo do período anterior antes de selecionar as identidades exibidas agora. **Novo** significa ausência de plays no período anterior, sem afirmar primeira escuta da vida.

Eventos sem relação com artista ou álbum ficam fora desse ranking; a tela informa a quantidade excluída. Eles continuam nos totais e distribuições. Entidades existentes sem nome recebem um rótulo explícito.

Use setas esquerda/direita, Home e End para alternar abas; Tab leva ao painel selecionado. Em desktop amplo, gráficos ficam ao lado; em telas pequenas, abaixo.

## Distribuições

| Gráfico | Critério |
| --- | --- |
| Horas | 24 faixas de hora local; tabela expansível com contagens e participações |
| Dias da semana | Totais por dia local, sem dividir pela quantidade de dias observados |
| Datas | Plays por data, preenchendo ausências com zero; no máximo os últimos 90 dias do recorte |
| Décadas de lançamento | Ano do `release_date` do álbum atual, agrupado por década; ausências em grupo desconhecido |

A limitação de 90 dias vale só para a série diária; rankings, resumo e demais distribuições usam o recorte completo. A década do álbum não representa necessariamente o ano da gravação original: remasters e outras versões mantêm suas identidades e metadados.

## Limites da interpretação

A unidade é uma play normalizada. Repetições do RAW, novas evidências e replay não multiplicam eventos conhecidos. Não há filtro adicional de duração nem cálculo de tempo ouvido.

A base pode ter lacunas por interrupções, atrasos e janela limitada da API. Primeiro e último evento e dias com zero não comprovam continuidade. Com poucos dias de dados, comparações descrevem apenas os eventos registrados naquele intervalo.

**Sobre os dados** reúne alcance da base, critérios e limitações. As consultas de cada resposta usam a mesma transação `REPEATABLE READ, READ ONLY`, mantendo os painéis consistentes entre si.

## API e permissões

`GET /api/analytics` aceita `period=all|7d|30d|90d|custom` e `limit=10|20|50`. Apenas `custom` aceita/exige `from=AAAA-MM-DD` e `to=AAAA-MM-DD`. Parâmetros desconhecidos, arrays, datas inválidas/invertidas ou limites não aceitos retornam HTTP 400.

A role web lê colunas selecionadas de data e catálogo, incluindo `albums.release_date` para décadas, sem payloads, contexto de escuta ou vínculo privado da conta. Após alterar os grants necessários, execute o provisionamento administrativo:

```powershell
docker compose run --rm app php app monitor:provision
```

Não é uma migration nem altera o histórico. Veja [MonitorAccess.php](../../src/Monitoring/MonitorAccess.php), [Analytics.php](../../src/Analytics/Analytics.php) e [Period.php](../../src/Analytics/Period.php). As motivações iniciais estão nas decisões [007](../decisions/007-local-listening-analytics.md) e [009](../decisions/009-analytics-first-interface.md); validações anteriores estão no [histórico](../historico/README.md).

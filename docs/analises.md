# Análises locais

Abra **http://127.0.0.1:8787**: Análises é a página inicial, em `/analytics`. A tela usa somente as reproduções já registradas no PostgreSQL e não faz novas chamadas ao Spotify.

## O que está disponível

- Rankings em abas de faixas, artistas e álbuns, com quantidade e participação no total do período. Escolha Top 10, 20 ou 50. Use setas esquerda/direita, Home e End para trocar de aba pelo teclado; Tab leva ao painel selecionado.
- Distribuição pelas 24 horas e pelos sete dias da semana, no fuso `MONITOR_TIMEZONE` (padrão `America/Sao_Paulo`).
- Série de reproduções por data, com zero nos dias sem eventos. Esse gráfico mostra no máximo os últimos 90 dias do recorte; os demais painéis usam o período completo.
- Filtros para todo o histórico, últimos 7/30/90 dias ou datas personalizadas. Atalhos e Top atualizam ao selecionar. Para datas personalizadas, preencha os dois limites e clique em **Aplicar**. **Atualizar** consulta novamente os filtros selecionados.

O resumo do período vem primeiro, seguido do ranking e dos gráficos. Em desktop amplo, os gráficos ficam ao lado do ranking; no celular, abaixo dele. **Sobre os dados** reúne o alcance da base, os critérios de contagem e as limitações. O gráfico horário tem uma tabela expansível **Ver valores por hora**, com as 24 contagens e participações.

Os atalhos incluem o dia atual e os dias anteriores do calendário local. Um período personalizado inclui as duas datas inteiras. O dia atual pode estar incompleto. As análises não são atualizadas automaticamente; o horário da consulta aparece no rodapé. Fechar ou atualizar a tela não altera o scheduler.

## Como interpretar

A contagem usa uma linha de `plays` por evento. Repetições do RAW, novas ocorrências da mesma evidência e replay não multiplicam as plays já deduplicadas. Não há filtro adicional de duração nesta tela.

Uma colaboração conta uma vez para cada artista da faixa. Assim, a soma das contagens/participações dos artistas pode superar o total de reproduções/100%. A participação de cada item sempre usa o total de plays do recorte como denominador. As distribuições por hora e dia da semana são totais, sem divisão pelo número de dias observados.

Cada identidade do catálogo tem sua posição. Duas versões com o mesmo nome podem aparecer separadas. Empates são ordenados por nome e ID interno; os rankings podem terminar em um empate por causa do Top escolhido. Nomes/artistas/álbuns são os metadados atuais da projeção, não uma fotografia de cada observação histórica.

Eventos sem relação com artista ou álbum ficam fora do respectivo ranking; a tela informa a quantidade excluída. Entidades existentes sem nome recebem um rótulo explícito. Todos esses eventos continuam no total e nas distribuições.

A base pode conter lacunas por interrupções da coleta e limites da janela disponibilizada pela API. As datas do primeiro e último evento não comprovam cobertura contínua. Os resultados permitem explorar o que foi registrado; com poucos dias, ainda não representam um padrão duradouro de escuta.

## Instalação e acesso

Nesta instalação, as novas permissões de leitura já foram aplicadas. Para levar o código a outra instalação que já tenha monitor, execute após atualizar os arquivos:

```powershell
docker compose run --no-deps --rm -T app php app monitor:provision
```

Não é necessária uma nova migration, reconstrução do histórico ou troca de tokens. O monitor continua com secret próprio, somente leitura, código em mounts de leitura e sem acesso a arquivos privados. A role lê as datas e o catálogo necessários às análises, mas não payloads, contexto de escuta ou a tabela de vínculo de conta. Veja a [decisão 007](decisions/007-local-listening-analytics.md).

A API `GET /api/analytics` aceita `period=all|7d|30d|90d|custom` e `limit=10|20|50`. Somente `custom` aceita/exige `from=AAAA-MM-DD` e `to=AAAA-MM-DD`. Arrays, datas inválidas/invertidas, parâmetros desconhecidos e limites excessivos retornam HTTP 400. Os painéis usam uma única transação REPEATABLE READ, READ ONLY, com os mesmos limites de origem local do monitor.

## Validação em 11/11/2026

A suíte completa após a reformulação passou com **120 testes e 1.231 assertions**, sem falhas ou skips, em banco separado terminado em `_test`. Os cenários analíticos cobrem colaborações, créditos repetidos, versões de mesmo nome, evidências adicionais, dados ausentes, limites de datas, horário de verão, preenchimento com zero, recortes vazios, Top e janela diária. A API foi exercitada com a credencial restrita, incluindo negação de escrita e leitura de campos privados. Os testes HTTP também verificam a entrada em Análises, o menu único, IDs sem repetição e a estrutura acessível das abas.

Na instalação local, a revisão visual cobriu celular e desktop, incluindo tela ampla, tema claro/escuro, filtros, recorte vazio, abas pelo teclado e tabela de horários. Dashboard e Testes também foram conferidos, com diálogos, paginação, busca, filtros e navegação móvel. A tabela operacional mantém rolagem dentro da própria região. Nenhum dado pessoal foi adicionado aos arquivos de teste ou documentação.

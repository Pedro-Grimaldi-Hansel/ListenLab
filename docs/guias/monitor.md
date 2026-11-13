# Monitor e Dashboard da coleta

[Índice](../README.md) · [Início rápido](inicio-rapido.md) · [Operação](operacao.md)

O Dashboard em [127.0.0.1:8787/dashboard](http://127.0.0.1:8787/dashboard) acompanha a operação: atividade do agendador, frequência efetiva, próxima busca, última coleta, histórico e armazenamento. A entrada `/` leva a Análises; use o menu para alternar entre as três telas.

## O que observar primeiro

1. Estado do agendador e horário do sinal.
2. Intervalo realmente carregado e próxima execução prevista.
3. Resultado e contadores da última coleta.
4. Histórico de execuções, com filtros e detalhes.
5. Política de armazenamento e data da última restauração verificada.

A página consulta o banco a cada cinco segundos enquanto está visível. Essa atualização não chama o Spotify nem controla a coleta. Fechar a aba não interrompe o scheduler.

## Estados do agendador

| Indicação | Significado |
| --- | --- |
| Coletando agora | Há sinal recente e o processo iniciou o comando de coleta |
| Agendador ativo | Há sinal recente enquanto o processo aguarda o próximo ciclo |
| Agendador parado | O encerramento foi registrado |
| Sem sinal recente | O heartbeat está há mais de vinte segundos sem atualização |
| Sem sinal do agendador | Não existe registro operacional do scheduler |
| Conexão interrompida | A página não consegue consultar o backend; dados anteriores podem continuar visíveis |

O scheduler publica sinal a cada cinco segundos, inclusive durante o processo filho e a manutenção. Uma última coleta concluída não comprova atividade presente. Quando o sinal fica antigo, a página deixa de confirmar a previsão da próxima busca.

A contagem regressiva usa o horário informado pelo processo. Uma coleta/manutenção longa pode atrasar o próximo ciclo. O intervalo mostrado não é inferido de uma edição recente do `.env`.

## Histórico de execuções

A tabela tem paginação, filtro por status e detalhes de cada batch. Mostra operação, horário, duração, itens recebidos, plays criadas, eventos existentes e resultado. `completed`, `partial`, `failed` e `running` são explicados em [Operação](operacao.md#interpretar-o-resultado).

Os detalhes incluem issues e referências RAW: caminho, SHA-256, tamanho e representação de armazenamento. Os hashes são os registrados na coleta; a atualização da página não relê todos os arquivos. Para conferir os bytes, execute `raw:verify` pela CLI.

Repetições completas conhecidas podem constar apenas dos contadores. Portanto, linhas de `ingestion_records` não equivalem ao total recebido. [Política de auditoria](../referencia/auditoria-enxuta.md).

Um batch `running` antigo pode ter sido interrompido abruptamente. O painel não altera seus registros para inventar um encerramento. Consulte heartbeat e logs para entender o caso.

## Armazenamento e backup

O painel diferencia respostas recebidas, corpos únicos, bytes ativos catalogados, arquivo privado e snapshots. A medição do arquivo vem da última manutenção concluída, sem varredura de disco a cada atualização.

Esses valores não representam alocação NTFS nem espaço reservado pelo Docker. O banco, relatórios e corpos novos continuam crescendo. O estado de falha aparece como **Requer atenção**; uma medição anterior pode permanecer visível.

O navegador não oferece download dos corpos RAW ou backups. Consulte [RAW e recuperação](../referencia/raw-storage.md) para verificar, ler ou restaurar localmente.

## Navegação, tema e Testes

No desktop, o menu fica na lateral; em telas pequenas, no topo. **Tema claro/escuro** guarda a escolha no navegador e vale nas três páginas. Sem escolha anterior, o padrão é escuro.

[Testes](http://127.0.0.1:8787/tests) oferece busca, filtros por tipo, grupos expansíveis e comandos para copiar. É um catálogo explicativo; abrir a página não executa a suíte nem consulta o Spotify. O catálogo pode abrir sem banco, desde que servidor e dependências estejam disponíveis.

## Inicialização e isolamento

Para ligar somente a visualização e o banco necessário:

```powershell
docker compose --profile monitor up -d monitor
```

Preparação inicial e atualizações de permissões usam `monitor:provision`, conforme [Instalação](instalacao.md) e [Operação](operacao.md). O secret é preservado pelo provisionamento.

O monitor aceita GET/HEAD pela origem local, usa credencial PostgreSQL restrita e transações somente leitura. Não monta arquivos privados nem o Docker socket. Iniciar/parar serviços e mudar a frequência são operações do terminal. A aplicação não está preparada ou autorizada para publicação externa.

## Rotas de consulta

| Rota local | Conteúdo |
| --- | --- |
| `GET /api/status` | Runtime do agendador, última coleta, contadores e resumo de armazenamento |
| `GET /api/batches?page=1&status=all` | Lista paginada; status `all`, `completed`, `partial`, `failed` ou `running` |
| `GET /api/batches/ID` | Detalhes de um batch; 404 se não encontrado |
| `GET /api/analytics` | Agregações conforme os [filtros analíticos](analises.md#api-e-permissões) |

As rotas também aceitam HEAD e não oferecem operações de escrita. A lista de batches aceita páginas de 1 a 100000. Valores inválidos de filtro retornam 400; indisponibilidade de banco pode retornar 503. O contrato implementado está em [router.php](../../monitor/router.php) e [Monitor.php](../../src/Monitoring/Monitor.php).

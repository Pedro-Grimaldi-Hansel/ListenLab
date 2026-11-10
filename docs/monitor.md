# Monitor local do ListenLab

O monitor permite acompanhar a operação do coletor em `http://127.0.0.1:8787`. Ele exibe o sinal do agendador, o intervalo efetivamente carregado pelo processo, a próxima busca prevista, a última coleta e o histórico de batches. Não inclui rankings, gráficos de hábitos musicais ou regras analíticas de plays.

## Dashboard e testes em páginas separadas

- **Dashboard:** `http://127.0.0.1:8787/dashboard`, com os dados operacionais da coleta.
- **Testes:** `http://127.0.0.1:8787/tests`, com as explicações e os comandos da suíte.

A entrada `/` redireciona para `/dashboard`. Use os botões Dashboard/Testes no topo ou o menu lateral para trocar de página. Cada tela carrega seu conteúdo e seu script; o catálogo tem apresentação de documentação, separada dos indicadores operacionais.

A paleta combina verde `#1DB954`, destaques `#1ED760` e fundos pretos/cinza no modo escuro. A inspiração vem das [referências de design do Spotify](https://developer.spotify.com/documentation/design). O nome e o símbolo continuam sendo do ListenLab.

## Tema e tela de testes

Use **Modo escuro** ou **Modo claro** no menu para alternar o tema. A escolha fica salva neste navegador e vale também na tela de testes. Sem uma escolha salva, o monitor acompanha o tema do sistema. Se o navegador bloquear o armazenamento, o botão ainda funciona na página aberta.

O item **Testes** abre `http://127.0.0.1:8787/tests`. Cada grupo explica os cenários existentes e informa seu arquivo de origem. Use a busca por assunto ou filtre entre testes unitários e de integração. Os comandos no final da tela podem ser copiados para o terminal.

As quantidades incluem as variações de dados de um mesmo teste. O catálogo descreve a cobertura; consulte a saída do PHPUnit para saber o resultado de uma execução. Abrir essa tela não inicia testes nem chamadas Spotify, e ela pode ser consultada mesmo sem conexão ao banco.

## Atualizar uma instalação existente

Extraia os arquivos da atualização na pasta do projeto. O pacote não contém `.env`, tokens, dados RAW, dumps, dependências nem o volume PostgreSQL. Preserve os seus arquivos privados.

Na pasta do projeto, execute:

```powershell
docker compose build
docker compose run --rm app php app db:migrate
docker compose --profile schedule --profile monitor up -d --force-recreate scheduler monitor
```

Abra **http://127.0.0.1:8787**. O rebuild inclui sinais de encerramento e ferramentas PostgreSQL 17 para verificar a restauração dos backups; não exige Node.js no seu computador. A migration 002 acrescenta o estado do agendador; 003 e 004 acrescentam o índice de conteúdo compartilhado, medidas e hashes de payload. Nenhuma tabela de eventos ou RAW é apagada.

Para iniciar apenas a visualização, sem iniciar o agendador:

```powershell
docker compose --profile monitor up -d monitor
```

## O que aparece

- **Coletando agora:** o agendador está enviando sinal e iniciou o comando de coleta.
- **Agendador ativo:** o processo continua enviando sinal enquanto aguarda o próximo intervalo.
- **Agendador parado:** houve encerramento registrado pelo processo.
- **Sem sinal recente:** o sinal passou de 20 segundos sem atualização; o monitor não confirma uma próxima execução.
- **Sem sinal do agendador:** ainda não existe um registro desta versão. Inicie/recrie o serviço `scheduler` após atualizar o código.
- **Conexão interrompida:** a página não consegue consultar o backend; os valores eventualmente visíveis são os últimos dados recebidos.

O sinal é gravado no PostgreSQL a cada cinco segundos, inclusive enquanto o processo filho faz a coleta. Um advisory lock mantém apenas um agendador por banco. A última execução concluída, isoladamente, nunca é usada como prova de que o scheduler continua ativo.

A contagem regressiva depende do horário confirmado pelo agendador. Se o sinal ficar antigo, o monitor remove a previsão. Quando uma coleta termina com falha ou alerta, o processo pode continuar aguardando a próxima tentativa; o histórico mantém o resultado individual da coleta.

## Histórico e rastreabilidade

A lista tem paginação, filtro por status e detalhes de cada execução. Inclui operação, horário, duração, eventos recebidos, plays criadas e ocorrências já ingeridas. Os detalhes mostram os arquivos RAW registrados, seu SHA-256 e os problemas de ingestão.

Os hashes exibidos são os registrados na coleta, sem uma verificação automática de todo o filesystem a cada atualização. Para conferir os arquivos:

```powershell
docker compose run --rm app php app raw:verify
```

Execuções interrompidas abruptamente podem continuar com status `running` no histórico. O sinal do agendador é uma informação independente, e o monitor não altera o resultado de batches antigos para inventar um encerramento.

## Frequência e funcionamento

A tela consulta o banco a cada cinco segundos, enquanto a aba está visível. Isso não dispara uma chamada ao Spotify. O coletor segue `SYNC_INTERVAL_SECONDS`, mesmo com a aba fechada.

O padrão atual é `900` segundos, ou 15 minutos. O painel usa o intervalo realmente carregado pelo agendador. Essa frequência reduz consultas, mas não resolve atrasos ou limitações da janela de histórico fornecida pelo Spotify.

Após alterar o intervalo no `.env`, recrie o scheduler:

```powershell
docker compose --profile schedule up -d --force-recreate scheduler
```

`MONITOR_PORT` é opcional e usa `8787` por padrão. `MONITOR_TIMEZONE` usa `America/Sao_Paulo`; os dados continuam armazenados em UTC. O monitor lê o intervalo efetivo do processo, e não presume que editar o `.env` já alterou um container existente.

## Armazenamento e backup

O painel separa respostas recebidas de conteúdos únicos. A mesma resposta pode aparecer em vários recebimentos sem ocupar outra cópia do corpo. Mostra bytes ativos, quantidade de conteúdos compactados/arquivados e tamanho do arquivo privado, além da data da última restauração de backup verificada e quantidade de snapshots.

Os bytes ativos vêm do índice de armazenamento. O tamanho do arquivo privado é uma medição da última manutenção concluída, conservada mesmo se uma tentativa posterior falhar. Não representa o espaço reservado pelo Docker nem o tamanho total do volume PostgreSQL. A manutenção automática ocorre diariamente; o painel não a dispara. O estado de falha aparece como **Requer atenção**.

Nos detalhes, cada RAW informa tamanho original, hash e se o conteúdo está compartilhado, comprimido ou arquivado. O caminho pode conter uma referência: `raw:read ID` recupera seus bytes originais pela CLI. O navegador continua sem acesso aos corpos ou aos backups. [Configuração e recuperação](raw-storage.md).

## Acesso local

A porta é publicada somente em `127.0.0.1`. O monitor tem endpoints de consulta em transações somente leitura, não oferece downloads dos RAW, não lê arquivos de tokens e não devolve payloads de eventos ou metadados arbitrários. Nenhum Docker socket é montado e o navegador não controla containers.

Para parar apenas a interface:

```powershell
docker compose --profile monitor stop monitor
```

O scheduler continua trabalhando enquanto seu próprio serviço, o banco, o computador e o Docker estiverem em execução.

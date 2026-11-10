# 004 — Monitor local e sinal explícito do agendador

Status: aceita. Data: 10/11/2026.

O acompanhamento solicitado é operacional: confirmar se o coletor está ativo e quando executou/voltará a executar. A interface permanece local, na stack PHP/PostgreSQL existente, e consulta os batches já registrados. Não publica dados pessoais nem introduz uma plataforma de analytics.

O horário da última coleta não prova que o agendador continua ligado. A tabela `scheduler_runtime` recebe um sinal a cada cinco segundos, inclusive durante a execução do processo filho. Depois de 20 segundos sem sinal, a interface deixa de confirmar a atividade e a previsão de execução. Um identificador de instância impede que um encerramento antigo sobrescreva uma nova instância.

O scheduler usa lock PostgreSQL de sessão separado do lock da ingestão. O lock do scheduler garante um agendador por banco; as constraints e transações da ingestão continuam garantindo a integridade dos eventos. Encerramento por SIGTERM/SIGINT é tratado quando a extensão pcntl está disponível; o Dockerfile a inclui. Em morte abrupta ou indisponibilidade do banco, o sinal antigo passa a ser tratado como não confirmado.

O monitor só aceita GET/HEAD, usa consultas em transações READ ONLY e devolve uma seleção explícita de metadados operacionais. Não expõe tokens, .env, corpos RAW ou o Docker socket. Iniciar/parar o scheduler e mudar sua configuração continuam sendo operações locais de administração pelo Compose.

O frontend usa HTML, CSS e JavaScript sem dependências de build e atualiza dados do banco a cada cinco segundos. Atualizar a tela não chama o Spotify. O intervalo mostrado vem da instância em execução. Nenhum estado de demonstração é usado na instalação normal; fixtures de validação ficam em banco separado.

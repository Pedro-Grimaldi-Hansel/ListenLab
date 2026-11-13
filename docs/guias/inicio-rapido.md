# Início rápido: coletar e abrir a tela

[Índice](../README.md) · [Problemas comuns](solucao-de-problemas.md)

Use este guia para a instalação já configurada e autorizada. Em uma pasta nova, faça primeiro a [instalação](instalacao.md).

## 1. Abrir o Docker Desktop

Abra **Docker Desktop** pelo menu Iniciar e espere o motor ficar disponível. No terminal, confira:

```powershell
docker info --format '{{.ServerVersion}}'
```

O comando deve mostrar a versão do servidor. Se aparecer `dockerDesktopLinuxEngine` ou “failed to connect to the docker API”, siga a [correção desse erro](solucao-de-problemas.md#docker-desktop-não-responde).

## 2. Entrar na pasta do projeto

No PowerShell ou terminal do VS Code:

```powershell
cd "C:\Users\Pedro\Desktop\Projetos\ListenLab"
```

Se o projeto estiver em outro lugar, ajuste o caminho. Os demais comandos partem da raiz, onde está `compose.yaml`.

## 3. Iniciar a coleta e a interface

```powershell
docker compose --profile schedule --profile monitor up -d db scheduler monitor
docker compose ps
```

`db`, `scheduler` e `monitor` devem aparecer em execução; o banco deve ficar `healthy`. O `app` é usado para comandos pontuais e não precisa ficar aberto como serviço permanente.

O agendador inicia uma coleta ao entrar em execução e repete o ciclo no intervalo configurado, por padrão **15 minutos**. Executar `up -d` sobre um agendador já ativo mantém sua execução; não força outra busca imediata.

## 4. Abrir o navegador

| Tela | Endereço | Uso |
| --- | --- | --- |
| Análises | [127.0.0.1:8787/analytics](http://127.0.0.1:8787/analytics) | Rankings e gráficos das reproduções registradas |
| Dashboard | [127.0.0.1:8787/dashboard](http://127.0.0.1:8787/dashboard) | Atividade, próxima busca e resultados das coletas |
| Testes | [127.0.0.1:8787/tests](http://127.0.0.1:8787/tests) | Explicações da suíte; abrir a página não executa testes |

Abrir `http://127.0.0.1:8787` leva a Análises. Se `MONITOR_PORT` foi alterado, use essa porta no endereço.

## 5. Confirmar o funcionamento

No Dashboard, procure **Agendador ativo** ou **Coletando agora**, o intervalo em execução e o resultado da última coleta. Zero plays novas pode significar que todos os eventos recebidos já eram conhecidos.

Para ver as últimas mensagens:

```powershell
docker compose logs --tail=50 scheduler
```

`scheduler sync_exit=0` indica coleta concluída. Saída `2` indica resultado parcial; saída `1`, falha. Consulte [Operação local](operacao.md) para interpretar o resultado.

## Manter ou interromper

Fechar o navegador ou o terminal não para a coleta. Mantenha o computador ligado, sem suspensão, com Docker e banco disponíveis. Atualizar a interface consulta o banco, sem disparar buscas no Spotify.

Para parar somente a coleta:

```powershell
docker compose --profile schedule stop scheduler
```

Para iniciá-la novamente:

```powershell
docker compose --profile schedule up -d scheduler
```

Outras rotinas, como parar a interface ou alterar o intervalo, estão no [guia de operação](operacao.md).

# Solução de problemas

[Índice](../README.md) · [Operação](operacao.md)

Comece na raiz do projeto. Use os sintomas abaixo para localizar a etapa: Docker → banco → agendador → autorização/coleta → interface. Os procedimentos preservam a configuração e o volume existentes.

## Docker Desktop não responde

Sintoma:

```text
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine
The system cannot find the file specified.
```

O cliente `docker` foi encontrado, mas o motor Linux não está acessível. Abra Docker Desktop pelo menu Iniciar e aguarde. Confira:

```powershell
docker info --format '{{.ServerVersion}}'
docker context show
```

Se Desktop estiver aberto sem concluir a inicialização, confira a mensagem mostrada nele e se está usando containers Linux. O contexto habitual é `desktop-linux`; um contexto diferente pode ser intencional, então confira antes de trocá-lo. Para diagnosticar o backend WSL, use `wsl --status`.

Quando `docker info` responder, execute:

```powershell
docker compose --profile schedule --profile monitor up -d db scheduler monitor
```

No PowerShell, use a linha completa sem uma barra `\` ao final.

## Banco não inicia ou porta está ocupada

```powershell
docker compose ps --all
docker compose logs --tail=50 db
```

Se houver conflito ou reserva da porta `5432`, configure uma porta livre, como `DB_PORT=15432`, no `.env`, e aplique:

```powershell
docker compose up -d db
```

Dentro do Docker, a conexão permanece `db:5432`; clientes do Windows usam a nova porta. Essa mudança não exige apagar o volume ou reconstruir a imagem. Uma senha alterada apenas no `.env` não altera o usuário de um banco já inicializado.

## Página não abre

Abra `http://127.0.0.1:8787/dashboard`, ajustando a porta se `MONITOR_PORT` for diferente. Confira:

```powershell
docker compose --profile monitor up -d monitor
docker compose logs --tail=50 monitor
```

| Sintoma | O que conferir |
| --- | --- |
| Conexão recusada | Container do monitor, porta publicada e Docker |
| Página abre, mas mostra conexão interrompida/503 | Banco, migrations, secret e grants do monitor |
| HTTP 403 | Use a origem local e a porta configurada; hosts/origens externos são recusados |
| Arquivo de secret ausente | Execute `monitor:provision` com o banco preparado e a credencial administrativa |
| Dependência/autoload ausente | Execute `docker compose run --rm app composer install` |

Para preparar banco e permissões após atualização:

```powershell
docker compose run --rm app php app db:migrate
docker compose run --rm app php app monitor:provision
docker compose --profile monitor up -d --force-recreate monitor
```

O monitor não lê o `.env` administrativo diretamente; recebe um ambiente próprio e o secret de leitura. Consulte [Configuração](../referencia/configuracao.md).

## Agendador parado ou sem sinal recente

```powershell
docker compose --profile schedule up -d scheduler
docker compose logs --tail=100 scheduler
```

O heartbeat é enviado a cada cinco segundos; depois de vinte segundos sem atualização, a interface deixa de confirmar atividade. Banco indisponível, Docker parado ou suspensão do computador podem interrompê-lo. A última coleta concluída não prova que o agendador continua ativo.

Se o log informar outro agendador/ingestão ativa, aguarde ou identifique o processo responsável. Os locks impedem sobreposição; não remova registros do banco para contorná-los.

## Autorização Spotify falhou

Confira as datas, sem imprimir tokens:

```powershell
docker compose run --rm app php app spotify:auth:status
```

| Mensagem/sintoma | Ação |
| --- | --- |
| `invalid_grant` ou autorização expirada/revogada | Refazer o [OAuth](instalacao.md#4-autorizar-a-conta) com a mesma conta |
| `state` vencido ou já usado | Iniciar outra autorização; a solicitação vence em dez minutos |
| Redirect URI incompatível | Conferir cadastro e `SPOTIFY_REDIRECT_URI`, usando `127.0.0.1` |
| Retorno não alcança o callback | Usar `spotify:auth:complete` e seu prompt oculto |
| Outra conta recusada | Autorizar a conta já vinculada; a instalação não oferece troca de conta |
| HTTP 403 da API | Conferir o erro, scope e condições do app/allowlist no Spotify |

## Coleta funciona, mas não aparecem músicas novas

Confira o resultado no Dashboard: recebidos, novas plays, repetidos e falhas. Receber só eventos já conhecidos pode ser normal. A interface não consulta o Spotify; atualizar Análises consulta novamente o banco.

Na tela de Análises, confira período e timezone e use **Atualizar**. Para realizar uma busca manual, use `spotify:sync`, ciente de que ela chama a API e grava outra execução.

A fonte pode atrasar eventos ou deixar de expô-los na janela recente. HTTP 200 e batch `completed` não comprovam histórico completo. Não descarte RAW nem avance cursores manualmente para tentar corrigir essa diferença. Veja a [pesquisa de confiabilidade](../pesquisas/spotify-history-reliability.md).

## Resultado parcial, quota ou manutenção falha

| Caso | Conduta |
| --- | --- |
| `partial` / saída 2 | Abrir detalhes do batch; conferir `stop_reason` e issues |
| `429` com `Retry-After` | Respeitar a espera; o cliente só repete dentro do orçamento configurado |
| `QUOTA_EXCEEDED` | Rever uso/quotas; o cliente encerra sem retry cego |
| `running` antigo | Conferir heartbeat e logs; uma interrupção abrupta pode deixar o batch aberto |
| Manutenção “Requer atenção” | Conferir integridade e logs; a tentativa automática falha bloqueia outra por 24 horas |

Uma tentativa manual de manutenção sem `--if-due` permite repetir após corrigir a causa. Veja [armazenamento e recuperação](../referencia/raw-storage.md). Preserve RAW, parciais e órfãos para investigação.

## Testes recusam o banco

`TEST_DB_NAME` deve ser diferente de `POSTGRES_DB` e terminar em `_test`. Mantenha o banco principal com seu nome original; crie/configure o banco de testes conforme [Desenvolvimento](desenvolvimento.md#preparar-o-banco-de-testes).

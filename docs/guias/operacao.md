# Operação local

[Índice](../README.md) · [Início rápido](inicio-rapido.md) · [Comandos](../referencia/comandos.md)

Execute os comandos na raiz do projeto, com Docker Desktop disponível. O banco e os dados persistem quando os serviços são parados.

## Serviços e acompanhamento

| Ação | Comando |
| --- | --- |
| Ligar banco, coleta e interface | `docker compose --profile schedule --profile monitor up -d db scheduler monitor` |
| Conferir containers | `docker compose ps` |
| Ver mensagens recentes da coleta | `docker compose logs --tail=100 scheduler` |
| Acompanhar mensagens continuamente | `docker compose logs --follow --tail=50 scheduler` |
| Ligar apenas a interface e o banco necessário | `docker compose --profile monitor up -d monitor` |
| Parar somente a coleta | `docker compose --profile schedule stop scheduler` |
| Parar somente a interface | `docker compose --profile monitor stop monitor` |
| Parar coleta, interface e banco | `docker compose --profile schedule --profile monitor stop scheduler monitor db` |

`Ctrl+C` encerra o acompanhamento de logs; o serviço iniciado com `-d` continua. Computador suspenso, Docker fechado ou banco indisponível impedem novas coletas. O navegador pode permanecer fechado.

## Executar uma busca manual

```powershell
docker compose run --rm app php app spotify:sync
```

O comando consulta o Spotify, grava RAW e registra uma nova execução. Se já houver ingestão ou manutenção em andamento, o lock recusa sobreposição; aguarde terminar. Não é necessário parar o agendador para uma consulta manual ocasional.

Para consultar o resultado e a integridade:

```powershell
docker compose run --rm app php app ingestion:list --limit=10
docker compose run --rm app php app raw:verify
```

Consultas `history:list` e leitura `raw:read` exibem dados pessoais no terminal; use-as localmente. Os relatórios completos ficam em `storage/reports/batch-<id>.json` e nos metadados do batch.

## Interpretar o resultado

| Resultado | Código da coleta | Significado |
| --- | ---: | --- |
| `completed` | 0 | Parada natural da consulta disponível |
| `partial` | 2 | Limite de paginação/progresso ou itens incompletos, rejeitados ou para revisão |
| `failed` | 1 | Erro que encerrou a coleta ou o processamento |
| `running` | — | Em execução ou sem encerramento registrado após interrupção |

Os logs do agendador separam `sync_exit` e `maintenance_exit`. Uma falha de manutenção não transforma uma coleta concluída em falha. O heartbeat informa se o agendador está ativo; um batch antigo concluído não confirma atividade atual.

`completed` não garante que toda a vida da conta foi recuperada. Zero plays novas pode ser uma repetição normal da janela recente. Veja [limites da fonte](../referencia/spotify-api.md).

## Alterar o intervalo

Edite localmente `SYNC_INTERVAL_SECONDS` no `.env`; `900` significa 15 minutos. Depois recrie o agendador para carregar o ambiente:

```powershell
docker compose --profile schedule up -d --force-recreate scheduler
```

Confira o intervalo efetivo no Dashboard. Apenas reiniciar o container antigo não atualiza suas variáveis. O ciclo é contado desde o início da coleta; execução ou manutenção demoradas podem atrasar a próxima busca.

Mudanças em opções mostradas pelo monitor, como política de arquivo, exigem também recriá-lo. Consulte [Configuração](../referencia/configuracao.md).

## Atualizar a aplicação

Revise o diff e preserve as alterações locais antes de substituir arquivos. Para mudanças de código/dependências ou permissões, sem troca da imagem do banco:

```powershell
docker compose --profile schedule stop scheduler
docker compose up -d db
docker compose build app monitor
docker compose run --rm app composer install
docker compose run --rm app php app db:migrate
docker compose run --rm app php app monitor:provision
docker compose --profile schedule --profile monitor up -d --force-recreate scheduler monitor
```

O provisionamento reaplica os grants necessários e preserva o secret gerenciado. O vínculo de conta é um passo adicional apenas para históricos anteriores a essa proteção; veja [Instalação](instalacao.md#conta-vinculada-ao-conjunto-de-dados).

Trocar a imagem do banco exige uma janela própria, com scheduler parado e backup restaurado e verificado antes da troca. Preserve o major PostgreSQL, o volume e os dados; consulte o [histórico das correções](../historico/correcoes-seguranca-2026-11-10.md) e o procedimento de [backup e recuperação](../referencia/raw-storage.md).

## RAW e manutenção

O scheduler verifica a manutenção depois da coleta. Com a política padrão, tenta no máximo a cada 24 horas, arquiva conteúdo ativo elegível com 30 dias e conserva três snapshots verificados do banco. Falhas também contam para o intervalo diário.

Para apenas simular o plano:

```powershell
docker compose run --rm app php app raw:maintain --archive
```

A aplicação do plano, conferência de snapshots, replay e recuperação estão descritos em [RAW, arquivo privado e restauração](../referencia/raw-storage.md). O arquivo no mesmo disco precisa de cópia privada em outro meio para proteger contra falha física.

# RAW, arquivo privado e restauração

## O que é preservado

Uma resposta HTTP mantém seu registro e recebimento, mesmo quando repete a anterior. A aplicação guarda um corpo por SHA-256, com os bytes exatos recebidos. Gzip é sem perdas; SHA-256 é conferido após a leitura. Duas respostas com espaços ou bytes diferentes permanecem distintas.

`storage/raw` contém referências pequenas e conteúdos ativos compartilhados. `storage/archive/objects` guarda corpos únicos comprimidos; `storage/archive/snapshots` guarda dumps completos, manifestos e provas de restauração. Os caminhos antigos continuam existindo como referências. Abrir uma referência diretamente mostra seu hash; use a CLI para obter o corpo original:

```powershell
docker compose run --rm app php app raw:read 1
docker compose run --rm app php app raw:verify
docker compose run --rm app php app ingest:replay 1
```

`raw:read` recebe o ID de `raw_artifacts` e escreve exatamente o corpo, sem acrescentar quebra de linha. Replay e verificação funcionam tanto com arquivos antigos como com referências, gzip e corpos arquivados.

As novas linhas de `ingestion_records` preservam artefato, posição, hash canônico e resultado, sem outra cópia JSONB. Desde a [auditoria enxuta](auditoria-enxuta.md), itens completos já conhecidos na mesma versão do normalizador ficam apenas nos contadores da execução. Conteúdo novo, variantes, incompletos e erros conservam detalhe; linhas antigas não são apagadas. O banco continua crescendo com novos eventos e metadados; todos os RAW e recibos permanecem preservados.

## Configuração atual

| Variável | Padrão | Efeito |
| --- | --- | --- |
| `SYNC_INTERVAL_SECONDS` | `900` | Um ciclo de coleta a cada 15 minutos |
| `RAW_ARCHIVE_PATH` | `storage/archive` | Arquivo privado, fora da pasta RAW ativa |
| `RAW_ARCHIVE_AFTER_DAYS` | `30` | Idade mínima desde o primeiro recebimento do corpo para liberar sua cópia ativa |
| `RAW_MAINTENANCE_ENABLED` | `1` | Scheduler verifica a necessidade de manutenção após a coleta |
| `RAW_BACKUP_KEEP` | `3` | Quantidade de snapshots completos verificados; mínimo 2 ou 0 para não rotacionar |

As configurações alteradas no `.env` exigem recriação dos containers:

```powershell
docker compose --profile schedule --profile monitor up -d --no-deps --force-recreate scheduler monitor
```

Conteúdos novos já são deduplicados e comprimidos no recebimento. A manutenção pesada ocorre no máximo uma vez a cada 24 horas após a tentativa anterior, inclusive em caso de falha, enquanto o scheduler estiver ligado. Isso evita gerar dumps repetidos a cada coleta quando uma restauração falha. Uma execução manual sem `--if-due` permite tentar novamente após a correção. A manutenção cria backup dos RAW e um dump do banco no mesmo snapshot transacional, restaura o dump em um banco temporário `_test`, compara todas as tabelas e confere os hashes dos corpos. A conta PostgreSQL precisa poder criar esse banco; sem isso a liberação falha preservando os conteúdos locais.

Uma manutenção com backup normalmente leva segundos nesta instalação, mas seu custo cresce com o histórico. O heartbeat do scheduler continua ativo durante esse trabalho. A previsão da próxima coleta pode atrasar se a manutenção durar mais que o intervalo.

## Operação manual

```powershell
# Plano, sem conversão ou remoção.
docker compose run --rm app php app raw:maintain --archive
# Executa a política de 30 dias.
docker compose run --rm app php app raw:maintain --apply --archive
# Verifica a necessidade diária, sem novo trabalho se já tentou nas últimas 24 h.
docker compose run --rm app php app raw:maintain --apply --archive --if-due
```

A conversão de RAW legados exige backup verificado, mesmo sem `--archive`. A liberação remove apenas o objeto ativo gerido pela aplicação após conferir sua cópia no arquivo e o snapshot de banco/índice. Os corpos únicos do arquivo não expiram. Relatórios, tokens, dumps preexistentes, órfãos e arquivos parciais não são limpos automaticamente. Diretórios com arquivos desconhecidos são preservados pela rotação.

Na implantação local de 10/11/2026 foi usada uma manutenção inicial com `--older-than-days=0`, após simulação e backup restaurado, para compactar também os dados existentes. A rotina futura continua usando 30 dias.

## Conferir e recuperar um backup

Os nomes dos snapshots aparecem na saída da manutenção e nas pastas de `storage/archive/snapshots`. Substitua `NOME_DO_SNAPSHOT` pelo nome real:

```powershell
docker compose run --rm app php app raw:backup:verify NOME_DO_SNAPSHOT
docker compose run --rm app php app raw:restore NOME_DO_SNAPSHOT /app/storage/backups/recuperacao-nova
```

O primeiro comando confere o dump, todos os corpos RAW e restaura o banco em um novo `_test`, removido ao terminar. O segundo também recria cada caminho RAW em uma pasta nova, usando o índice restaurado. Recusa pastas existentes e não sobrescreve a pasta ativa ou o banco principal. A pasta restaurada contém referências que dependem dos objetos em `RAW_ARCHIVE_PATH`.

O dump `database.dump` pode ser recuperado pelo `pg_restore` do PostgreSQL 17 em um banco novo. A CLI deliberadamente não oferece restauração sobre o banco principal. Para recuperação após perda da instalação, preserve a pasta completa `storage/archive`, mantenha o arquivo configurado no destino e restaure o banco em um banco novo antes de apontar a configuração para ele. As ferramentas PostgreSQL 17 estão incluídas na imagem PHP.

## Espaço e limites do backup

O painel separa bytes lógicos recebidos, arquivos RAW ativos catalogados e tamanho total da pasta de arquivo/backup medido na última manutenção. Não faz varredura do disco a cada atualização da tela. Esse tamanho não inclui relatórios, dependências, volume PostgreSQL nem o espaço reservado pelo Docker ou a alocação mínima de arquivos do Windows.

O arquivo fica dentro do projeto, ignorado pelo Git, pelo contexto de build e pelas rotas HTTP. Por estar no mesmo disco, não protege contra defeito físico, perda da máquina ou exclusão de toda a pasta. Copie `storage/archive` para outro disco quando possível. `.env`, tokens e relatórios não entram no dump e exigem backup privado separado. Não mova o arquivo nem troque seu caminho sem copiar seus objetos e snapshots.

Há até 24 horas de dados novos entre backups automáticos enquanto o serviço funciona; uma falha antes do primeiro backup exige a pasta ativa. A retenção de três snapshots limita a quantidade de dumps diários, mas o banco, os relatórios e os conteúdos RAW realmente novos continuam crescendo. A API também não garante histórico completo: aumentar o intervalo pode perder eventos que saiam da janela disponibilizada pelo serviço.

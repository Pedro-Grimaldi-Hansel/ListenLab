# Referência de comandos

[Índice](../README.md) · [Operação](../guias/operacao.md) · [Configuração](configuracao.md)

Execute na raiz do projeto, com Docker Desktop e o banco disponíveis quando necessários. A CLI está em [Console.php](../../src/Console.php). A forma geral é:

```powershell
docker compose run --rm app php app list
docker compose run --rm app php app help spotify:sync
```

`app` é o serviço Docker; o segundo `app` é o arquivo de entrada PHP. `--no-deps` evita iniciar dependências automaticamente e pressupõe banco já disponível. `-T` desativa TTY, útil em verificações automatizadas; mantenha a forma interativa para prompts OAuth.

## Preparação e autorização

| Comando após `php app` | Efeito |
| --- | --- |
| `db:migrate` | Aplica migrations pendentes com lock, transação e checksum |
| `monitor:provision` | Cria/reconfigura a role web gerenciada e seu secret privado, preservando o existente |
| `spotify:auth` | Grava state temporário e exibe URL para autorização |
| `spotify:auth:complete` | Recebe URL de retorno em prompt oculto, verifica conta e persiste tokens |
| `spotify:auth:status` | Mostra datas e scope sem revelar tokens |
| `spotify:account:bind` | Vincula o token atual a um dataset antigo; recusa trocar vínculo existente |

O callback também pode concluir OAuth automaticamente. O passo a passo está em [Instalação](../guias/instalacao.md).

## Coleta e investigação

| Comando | Padrão | Efeito |
| --- | --- | --- |
| `spotify:sync` | Até 20 páginas e 50 itens por página | Consulta janela recente, segue `next`, salva RAW e normaliza |
| `spotify:probe` | Até 100 páginas e 50 itens por página | Retrocede por `cursors.before`; também pode inserir eventos |
| `ingest:replay ID_DO_ARTEFATO` | Artefato HTTP 200 registrado | Confere SHA-256 e reprocessa sem API, criando batch e recebimento RAW |

Os dois comandos Spotify aceitam:

| Opção | Valores | Significado |
| --- | --- | --- |
| `--max-pages=N` | 1–10000 | Orçamento de páginas; não é prova de cobertura |
| `--limit=N` | 1–50 | Itens pedidos por página |
| `--json` | Flag | Exibe relatório estruturado |

Exemplo de investigação, que chama o Spotify e grava resultados:

```powershell
docker compose run --rm app php app spotify:probe --max-pages=100 --json
```

`sync`, `probe` e `replay` registram relatório em `storage/reports/batch-<id>.json`. Códigos: **0** para `completed`, **2** para `partial`, **1** para falha. Erros anteriores à abertura de batch, como lock indisponível, também podem encerrar com 1 sem criar um novo batch. Outros comandos de administração usam 0 em sucesso e 1 em erro.

## Consulta e contadores

| Comando | Opções | Observação |
| --- | --- | --- |
| `history:list` | `--limit=20`, `--details`, `--json` | Lista plays recentes em UTC; limite permitido 1–10000; saída contém histórico pessoal |
| `ingestion:list` | `--limit=20` | Lista batches, status e contadores; limite permitido 1–10000 |
| `raw:read ID_DO_ARTEFATO` | ID obrigatório | Escreve bytes originais exatos, sem quebra de linha adicional; saída privada |
| `raw:verify` | Sem opções específicas | Lê e confere todos os artefatos registrados, sem alterar seus bytes |

| Campo do relatório | Interpretação |
| --- | --- |
| `api_records_received` | Quantidade de itens recebidos; pode incluir repetidos entre páginas |
| `unique_source_records_observed` | Identidades válidas distintas observadas na execução |
| `new_source_records`, `plays_created` | Eventos novos normalizados nesta implementação |
| `already_ingested` | Identidades com evidência existente, inclusive variantes detalhadas |
| `audit_records_created` | Linhas detalhadas criadas |
| `duplicate_records_omitted` | Repetições completas conhecidas mantidas só nos contadores |
| `records_failed`, `partially_processed` | Falhas e itens incompletos/para revisão |
| `pages`, `http_responses` | Páginas válidas processadas e total de respostas HTTP capturadas |
| `oldest`, `newest`, `covered_seconds` | Extremos dos timestamps válidos observados e distância entre eles |
| `stop_reason` | Razão de encerramento, como `empty_page`, `next_exhausted`, `page_limit` ou `error` |

`covered_seconds` não comprova cobertura contínua. O número de detalhes individuais não substitui `api_records_received`. [Auditoria enxuta](auditoria-enxuta.md).

## Manutenção e recuperação

| Comando | Efeito |
| --- | --- |
| `raw:maintain` | Simula o plano por padrão; obtém lock e verifica conteúdos |
| `raw:maintain --apply` | Aplica conversão de legados e atualização do índice; backup verificado quando necessário |
| `raw:maintain --apply --archive` | Também permite liberar objetos ativos elegíveis, preservados no arquivo verificado |
| `raw:backup:verify NOME_DO_SNAPSHOT` | Confere corpos e dump, restaura em banco temporário `_test` e grava nova prova de verificação |
| `raw:restore NOME_DO_SNAPSHOT DESTINO_NOVO` | Verifica snapshot e recria caminhos RAW em pasta nova; referências dependem dos objetos do arquivo |

Opções de `raw:maintain`:

- `--archive`: incluir arquivamento no plano/aplicação.
- `--older-than-days=N`: idade entre 0 e 36500 dias; padrão `RAW_ARCHIVE_AFTER_DAYS=30`.
- `--if-due`: não repetir se houver tentativa registrada nas últimas 24 horas.
- `--apply`: efetivar; sem ela, não cria execução de manutenção ou converte/remove arquivos.

Verificar backup não é uma operação puramente de leitura: cria um banco temporário e um comprovante privado. Nenhum comando restaura sobre o banco principal. Os passos completos e limites estão em [RAW, arquivo privado e restauração](raw-storage.md).

## Testes e dependências

| Comando no serviço `app` | Finalidade |
| --- | --- |
| `composer install` | Instalar as versões do lock |
| `composer validate --strict` | Validar manifesto e lock |
| `composer lint` | Conferir sintaxe PHP |
| `composer test` | Testes unitários |
| `composer test:integration` | Integração em banco separado `_test` |
| `composer test:all` | Ambas as suítes |
| `composer audit --locked` | Consultar avisos das dependências do lock |

Veja [Desenvolvimento](../guias/desenvolvimento.md) para preparar o banco de testes e escolher as verificações adequadas.

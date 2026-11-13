# Correções de segurança aplicadas — 10/11/2026

> Registro datado de implementação e validação. Para procedimentos atuais, use [Operação local](../guias/operacao.md) e [Instalação](../guias/instalacao.md). Avisos de imagens abaixo descrevem a consulta daquela data, sem uma nova análise de vulnerabilidades nesta revisão documental.

As alterações foram implementadas e aplicadas à instalação local por solicitação do proprietário. Banco, monitor e coleta estão ativos. O `.env` e os registros anteriores de `plays` e `raw_artifacts` mantiveram seus hashes; a coleta retomada terminou com código zero. O volume PostgreSQL existente foi preservado.

Este documento complementa o [diagnóstico inicial](auditoria-2026-11-10.md) e a [explicação do funcionamento](../funcionamento-atual.md). O código permanece na árvore de trabalho para revisão e commit do proprietário; não houve commit, push, PR ou publicação externa pelo assistente.

## Situação dos achados

| Achado | Situação aplicada |
| --- | --- |
| A1 — Rotação ignora arquivos ocultos | Corrigido: enumeração completa e nova conferência das entradas antes da remoção. Snapshots com notas ou diretórios ocultos são preservados. |
| A2 — Falha inicial não é registrada | Corrigido: a tentativa aplicada é registrada antes da primeira varredura e finalizada como falha. O monitor mostra o erro e a rotina respeita o intervalo diário. |
| A3 — Monitor com acesso administrativo e privado | Corrigido para o monitor: usuário exclusivo de leitura, grants limitados, secret separado, código em leitura e ausência de `.env`, tokens, RAW e backups no container. |
| A4 — Host/origem externos | Corrigido: validação antes de consultar o banco. Hosts, portas e origens inesperados recebem 403. Navegações explícitas para as páginas continuam funcionando. |
| A5 — Alertas das imagens | Corrigidos componentes com atualização disponível e reduzida a imagem. O `gosu` foi recompilado com dependências corrigidas. Permanecem avisos de bibliotecas nativas sem correção disponível nas distribuições usadas, detalhados abaixo. |
| A6 — Mistura de contas | Corrigido para o dataset pessoal: vínculo persistente com o `account_id` estável, recusa de outra conta antes de trocar tokens ou aceitar eventos. |
| A7 — Verificações de segurança da CI | Implementado: Actions fixadas por SHA, permissões mínimas, auditoria Composer e análise das três imagens, também semanalmente. O workflow foi validado localmente; sua execução no GitHub depende do push do proprietário. |

## Evidências de validação

| Verificação | Resultado |
| --- | --- |
| Suíte completa na imagem final | **102 testes e 884 assertions**, sem falhas ou skips |
| Composição | 40 cenários unitários e 62 de integração em oito grupos |
| Sintaxe PHP e Composer | Aprovados; sem advisories ou pacotes abandonados reportados pelo Composer |
| Instalação limpa de dependências em `/tmp` | 47 pacotes instalados pelo lock, sem scripts/plugins; requisitos de plataforma atendidos; `vendor` original preservado |
| Workflow | Validado com actionlint 1.7.12, em container sem rede e com somente os workflows montados em leitura |
| Integridade RAW | Todos os artefatos consultados conferiram por SHA-256 após a atualização |
| Preservação | Hash do `.env` e fingerprints das linhas anteriores de plays/índice RAW iguais ao baseline privado |
| Backup anterior à troca de serviços | Novo snapshot privado criado e restaurado em banco temporário `_test`; sem rotacionar os snapshots existentes |
| Coleta após atualização | `sync_exit=0`, intervalo efetivo de 900 segundos; manutenção diária já realizada foi corretamente ignorada |
| HTTP em execução | Páginas e API 200; Host/Origin externos 403; caminhos privados 404; escrita 405 |
| Imagens com Trivy 0.76.2 | Nenhum achado alto/crítico com versão corrigida disponível nos componentes detectados das três imagens |

Os testes adicionais cobrem arquivos/diretórios ocultos, corrupção anterior ao registro, simulação sem escrita, TRUNCATE, permissões reais do usuário web, recusa de roles alheias, Host/Origin, navegação normal, troca de conta, renovação de token e preservação da autorização anterior.

No monitor efetivamente em execução, as consultas e verificações de arquivos retornaram:

```text
rolsuper=false; rolcreatedb=false; rolcreaterole=false
default_transaction_read_only=on
can_update_batches=false; can_read_played_at=false
env_mounted=false; tokens_mounted=false; storage_mounted=false
code_writable=false
admin_password_in_environment=false; spotify_secret_in_environment=false
```

Os testes também desativam o padrão READ ONLY da sessão e comprovam que os grants continuam impedindo INSERT, UPDATE, TRUNCATE e leitura dos payloads. A proteção não depende apenas da escolha de transação feita pelo PHP.

## Mudanças no banco e no armazenamento

A migration 005 acrescenta um trigger por instrução que recusa TRUNCATE de `raw_artifacts`, além do bloqueio anterior de UPDATE/DELETE. A migration 006 cria `spotify_account_binding`, com hashes de conta e token verificado, datas e proteção contra troca do vínculo, DELETE e TRUNCATE. São mudanças aditivas; as chaves de identidade de eventos não mudaram.

A manutenção registra uma tentativa aplicada antes de ler os RAW. Falhas nessa fase deixam uma execução `failed`, visível no painel, e impedem repetição automática nas próximas 24 horas. Uma simulação continua sem criar execução. Se o banco estiver indisponível, o processo ainda precisa ser acompanhado pelos logs, pois não é possível persistir um erro em uma conexão indisponível.

A rotação usa `FilesystemIterator::SKIP_DOTS`, que inclui entradas ocultas e exclui apenas `.` e `..`. Ela preserva qualquer snapshot com conteúdo desconhecido e reconfere suas entradas antes de remover arquivos gerenciados antigos. A atualização não executou rotação sobre os backups do proprietário.

## Isolamento do monitor

O monitor usa sua própria imagem, filesystem de container somente leitura, execução sem capabilities, `no-new-privileges`, limite de processos e `/tmp` limitado. Monta apenas `vendor`, `src` e `monitor` em leitura. Não monta a raiz do projeto.

O usuário `listenlab_monitor` não é superusuário e não pode criar bancos ou roles. Na correção original recebeu SELECT nas tabelas operacionais necessárias e somente a coluna `id` de `plays`, suficiente para contagem. Em 11/11/2026, a pedido do proprietário, as [análises locais](../guias/analises.md) ampliaram a leitura para `id/track_id/played_at` de `plays` e colunas selecionadas do catálogo. `ingestion_records`, contexto de escuta e vínculo privado da conta continuam sem acesso. Sua senha aleatória fica em `storage/secrets/monitor-db-password`, ignorada pelo Git, e entra no container como Docker secret. A senha administrativa do proprietário não foi alterada.

`monitor:provision` reutiliza o secret gerado, reaplica os grants mínimos da role gerenciada e recusa um usuário PostgreSQL existente que não tenha a identificação da aplicação. Deve ser executado com a credencial administrativa, depois das migrations. O ambiente CLI, o scheduler e o callback mantêm a configuração administrativa existente; esta alteração isola especificamente o processo do monitor. A instalação continua pessoal e local.

O router aceita apenas o host local e a porta publicada, valida `Origin` quando presente e recusa consultas cross-site. Uma navegação de documento para Dashboard/Testes pode vir de um link externo, sem conceder acesso cross-site à API. GET/HEAD, CSP, ausência de CORS aberto e seleção explícita dos campos continuam em vigor. Isso não autentica outros processos locais em uma máquina não confiável.

## Vínculo da conta Spotify

O backend consulta `/v1/me` quando precisa verificar um novo access token e usa o `account_id` recomendado na [documentação do Spotify](https://developer.spotify.com/documentation/web-api/reference/get-current-users-profile). Guarda somente o hash do identificador; o perfil não entra no RAW musical nem nas mensagens de erro.

A reautorização candidata é conferida antes de substituir o arquivo de tokens. A coleta também verifica o token antes de aceitar outro histórico. O resultado é reutilizado para o mesmo token, evitando uma consulta de perfil a cada página. Um 401 permite uma renovação antes de verificar novamente; uma conta diferente continua sendo recusada.

Nesta instalação, o vínculo inicial foi feito com o token já existente. Não houve troca de conta, alteração das plays ou mudança da versão da chave. Esse vínculo protege operações futuras; os RAW antigos não contêm a identidade da conta e não permitem provar retroativamente sua titularidade. Múltiplas contas continuam fora do escopo. [Decisão 006](../decisions/006-local-security-boundaries.md).

## Imagens e avisos que permanecem

A imagem PHP passou a Debian Trixie, recebe as atualizações disponíveis da base e não mantém compiladores. O monitor não contém Composer, Git ou ferramentas PostgreSQL. O ambiente CLI inclui Composer, unzip e ferramentas PostgreSQL 17; uma instalação limpa das dependências confirmou seu funcionamento sem Git no container. O Git do Windows permaneceu intacto.

O banco conserva PostgreSQL 17.11 e Debian Bookworm. Assim, a atualização não muda o major, o volume ou a base de locale do banco. Seu `gosu` agora é 1.20, compilado de commit upstream fixo com:

```text
Go / stdlib: v1.27.4
github.com/moby/sys/user: v0.4.1
golang.org/x/sys: v0.44.0
```

O binário em execução confirmou essa versão e sua troca de usuário. A análise independente do filesystem com Trivy identificou exatamente essas dependências e **nenhuma vulnerabilidade no binário Go detectado**.

O Scout 1.25.0 continuou associando Go 1.24.6 ao caminho substituído, inclusive após sua remoção explícita. A hipótese de metadados herdados da base vem da divergência entre essa saída e a análise do binário/filesystem; não foi feito um diagnóstico interno do Scout. Por isso, a verificação com outro scanner foi necessária. Os relatórios brutos foram preservados, sem esconder esses resultados. Eles estão em `.preview/security-fixes/`, ignorado pelo Git.

A imagem final do monitor passou de 200 avisos da antiga imagem compartilhada para 79 resultados no Scout, com oito classificados como altos e nenhum como crítico por esse scanner. Essa comparação mostra a redução do inventário, não uma medida de ataques possíveis. Trivy usa outras fontes/classificações e continua atribuindo severidade crítica a um aviso de libxml2; as contagens entre ferramentas não são diretamente comparáveis.

**Não foi obtida uma imagem sem todos os avisos conhecidos.** Exemplos remanescentes e sua triagem:

| Componente/aviso | Situação |
| --- | --- |
| libxml2 — CVE-2026-6653 | Sem patch disponível em Bookworm/Trixie consultados. O Trivy usa severidade NVD crítica; o Debian registra como problema menor adiado/sem DSA. O coletor e o monitor não recebem XML. A biblioteca continua presente; não foi declarada corrigida. [Debian](https://security-tracker.debian.org/tracker/CVE-2026-6653). |
| SQLite — CVE-2025-7458 na imagem do banco | Bookworm permanece sinalizado sem versão corrigida disponível. O domínio usa PostgreSQL, sem executar SQL SQLite. Isso reduz a aplicabilidade, mas não atualiza a biblioteca. [Debian](https://security-tracker.debian.org/tracker/CVE-2025-7458). |
| zlib/MiniZip — CVE-2023-45853 no Bookworm | O Debian informa que o código MiniZip vulnerável não é compilado nesse pacote; o aviso por versão de fonte não implica presença desse código no `zlib1g`. [Notas do Debian](https://security-tracker.debian.org/tracker/CVE-2023-45853). |
| Kerberos e outros componentes nativos | Permanecem avisos sem correção na distribuição, dependentes das funções/serviços efetivamente usados. A análise deve continuar nas próximas atualizações; não foi aplicada uma lista global para ocultá-los. |

A zlib das imagens PHP foi atualizada para `1:1.3.dfsg+really1.3.1-1+deb13u1`, que inclui as correções disponíveis. A ausência de achados altos/críticos **com correção disponível** no Trivy é o critério de bloqueio configurado na CI, e não uma afirmação de ausência de vulnerabilidades. O primeiro passo da CI continua mostrando os avisos sem correção disponível; o segundo reprova os corrigíveis. As atualizações de toolchain, imagens-base e dependências precisam continuar sendo revisadas.

## Uso e manutenção

Na instalação atual, as migrations, o provisionamento e o vínculo inicial já foram aplicados. Para outra instalação já autorizada que receba esta versão:

```powershell
docker compose build
docker compose run --no-deps --rm -T app php app db:migrate
docker compose run --no-deps --rm -T app php app monitor:provision
docker compose run --no-deps --rm -T app php app spotify:account:bind
docker compose --profile monitor --profile schedule up -d --no-deps monitor scheduler
```

A atualização da imagem do banco deve ser feita em janela curta com o scheduler parado, após um backup restaurado e verificado, mantendo o volume existente. Não use `down -v`. Em instalação nova sem token, a autorização inicial vincula a conta após o schema estar preparado; `spotify:account:bind` é a etapa de atualização de um dataset já autorizado.

O secret do monitor não entra no Git, na imagem ou no dump do schema. Pode ser recriado pelo provisionamento administrativo em uma recuperação. `.env`, tokens, relatórios, RAW, objetos e snapshots continuam exigindo cópias privadas adequadas.

O proprietário informou não ter outro disco/destino privado disponível. Portanto, a cópia fora do disco continua pendente. O novo snapshot verificado permanece no mesmo disco e não elimina o risco de perda física da máquina ou de todos os arquivos locais.

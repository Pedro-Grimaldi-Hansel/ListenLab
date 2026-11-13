# Avaliação do ListenLab — 10/11/2026

[Histórico](README.md) · [Funcionamento atual](../funcionamento-atual.md)

Este documento registra o diagnóstico anterior às correções. O estado aplicado, as verificações e os avisos ainda pendentes estão em [Correções de segurança](correcoes-seguranca-2026-11-10.md).

Base examinada: commit `33ed9c809dd177fd2d7e61b0927707786347726f`, coincidente entre a instalação local e `main` no GitHub. O repositório está público. A árvore de trabalho estava limpa no início da revisão.

O coletor está operacional para o uso pessoal e local previsto. A revisão encontrou **duas falhas reproduzíveis nas rotinas de armazenamento**, permissões excessivas no monitor e uma proteção ausente contra hosts HTTP inesperados. As imagens Docker também contêm componentes associados a avisos de segurança. Não foi demonstrada invasão remota, execução de código por uma rota HTTP ou exposição de tokens pelo navegador.

Esta avaliação distingue defeitos reproduzidos, riscos condicionais de configuração e alertas de componentes. Testes aprovados e consultas de CVEs não constituem garantia de ausência de vulnerabilidades.

Para uma explicação do fluxo de dados, consulte [Como o sistema funciona](../funcionamento-atual.md).

## Escopo e verificação

Foram revisados os arquivos próprios de PHP, JavaScript, migrations, configuração Docker, dependências, testes, workflow e documentação de domínio. A revisão dinâmica utilizou a instalação local para consultas e um banco separado terminado em `_test` para os cenários que escrevem dados.

| Verificação | Resultado observado |
| --- | --- |
| PHPUnit completo, PHP 8.4.27 | **88 testes, 727 assertions**, sem falhas ou skips |
| Distribuição da suíte | 39 cenários unitários e 49 de integração |
| `composer validate --strict` | Aprovado |
| `composer lint` | Aprovado |
| Sintaxe dos três scripts JavaScript | Aprovada com `node --check` |
| Dependências instaladas versus lock | 20 pacotes de produção e 27 de desenvolvimento, sem divergência de versão |
| `composer audit --locked --format=json` | Nenhum advisory ou pacote abandonado reportado |
| `raw:verify` na instalação | Todos os artefatos consultados conferiram por SHA-256 |
| HTTP local | Status 200; filtros inválidos 400; métodos de escrita 405; caminhos privados e traversal 404 |
| Serviços | Banco saudável; monitor e scheduler em execução; intervalo efetivo de 900 segundos |
| Privilégios do monitor | Superusuário PostgreSQL e acesso ao diretório privado de tokens, código e dados |
| Git | 89 arquivos rastreados; 90 blobs alcançáveis examinados nos dois commits locais |

As credenciais atuais, tokens e identificador do aplicativo foram comparados localmente com os blobs Git, sem imprimir seus valores. Não houve correspondências. A busca adicional por padrões de chaves privadas, tokens GitHub, chaves AWS e JWTs também não encontrou correspondências. Não havia `.env`, RAW, tokens ou dumps rastreados nas pastas privadas examinadas.

Esse resultado não exclui segredos antigos desconhecidos, outros formatos ou conteúdo em referências remotas que não estejam no histórico local. Os testes da CI no GitHub não foram confirmados: a consulta de status disponível retornou uma lista vazia; isso não prova que um workflow tenha falhado ou deixado de executar.

## Prioridades

| ID | Prioridade | Natureza | Resultado |
| --- | --- | --- | --- |
| A1 | Alta | Defeito de preservação | Arquivo oculto não impede a exclusão parcial de um snapshot antigo |
| A2 | Alta | Defeito de observabilidade | Falha na verificação inicial pode não ser registrada como manutenção falha |
| A3 | Alta | Isolamento e privilégios | Monitor pode acessar segredos e usa superusuário no banco |
| A4 | Média | Proteção HTTP | API aceita `Host` externo sem autenticação; exploração por navegador é condicional |
| A5 | Alta para triagem | Componentes das imagens | Scanner encontrou avisos, incluindo quatro críticos associados ao Go do `gosu` na imagem do banco |
| A6 | Média antes de trocar conta | Integridade do domínio | Reautorização não impede a mistura de contas Spotify |
| A7 | Baixa | Automação de segurança | CI não executa auditoria explícita de dependências; Actions usam tags mutáveis |

Prioridade representa ordem de trabalho neste projeto. Não é uma pontuação CVSS nem indica que todos os itens sejam exploráveis pela internet.

## A1 — Arquivos ocultos escapam da proteção de rotação

Evidência: [RawBackup.php:106](../../src/Storage/RawBackup.php#L106) e [RawBackup.php:123](../../src/Storage/RawBackup.php#L123).

`rotate()` enumera os arquivos com `glob($directory.'/*')`. Esse padrão não inclui entradas iniciadas por ponto no ambiente Linux do container. A proteção que deveria preservar diretórios com arquivos desconhecidos, portanto, não enxerga um arquivo como `.owner-note`.

A reprodução usou três diretórios fictícios, manifestos sintéticos e retenção de dois snapshots. Um arquivo oculto foi colocado no snapshot mais antigo. O resultado foi:

```text
hidden_owner_file_preserved: true
snapshot_dump_preserved: false
snapshot_manifest_preserved: false
rotation_error: Não foi possível concluir a rotação do snapshot.
```

O arquivo oculto permanece, mas o dump, manifesto e comprovante desse snapshot já foram removidos quando `rmdir()` falha. Isso viola a regra documentada de preservar snapshots que contenham arquivos desconhecidos. A prova não atingiu backups reais. O cenário não demonstra perda dos objetos RAW únicos ou do snapshot mais recente.

Correção proposta: enumerar todas as entradas, incluindo ocultas, com `FilesystemIterator::SKIP_DOTS`; validar integralmente o diretório antes de qualquer remoção; recusar a rotação na presença de qualquer entrada desconhecida. O teste atual `testBackupRotationNeverDeletesUnknownFiles` cobre um arquivo visível e deve incluir arquivos ocultos e diretórios ocultos.

## A2 — Corrupção inicial pode deixar um resultado anterior como concluído

Evidência: [RawMaintenance.php:28](../../src/Storage/RawMaintenance.php#L28), [RawMaintenance.php:36](../../src/Storage/RawMaintenance.php#L36), [RawMaintenance.php:71](../../src/Storage/RawMaintenance.php#L71), [Monitor.php:111](../../src/Monitoring/Monitor.php#L111) e [app.js:55](../../monitor/public/assets/app.js#L55).

A manutenção verifica todos os RAW antes de criar sua linha em `raw_storage_runs`. Se um conteúdo ativo estiver corrompido, `readVerified()` lança uma exceção enquanto `$runId` ainda é `null`. O bloco de erro só atualiza uma linha quando esse identificador já existe.

A reprodução coletou uma fixture no banco `_test`, registrou uma manutenção concluída e corrompeu exclusivamente um objeto temporário. A tentativa seguinte falhou, mas o banco continuou contendo uma única execução com status `completed`:

```text
operation_failed: true
recorded_run_count: 1
last_recorded_status: completed
```

O processo retorna erro, porém o painel pode continuar exibindo o resultado anterior como “Verificado”. Quando a janela diária já venceu, a tentativa sem registro também não renova o controle de frequência, permitindo novas varreduras a cada ciclo. Não houve corrupção detectada nos RAW reais verificados nesta auditoria.

Correção proposta: registrar a tentativa aplicada antes da varredura que pode falhar e finalizar o registro em todos os caminhos de erro. A simulação deve continuar sem gravar dados. Acrescentar testes para corrupção ativa anterior à varredura, visibilidade da falha no monitor e frequência de novas tentativas. Se o próprio banco estiver indisponível, o erro precisa continuar visível no log do processo.

## A3 — O processo web tem privilégios de administração e acesso a segredos

Evidência: [compose.yaml:3](../../compose.yaml#L3), [compose.yaml:10](../../compose.yaml#L10), [compose.yaml:60](../../compose.yaml#L60), [Database.php:16](../../src/Database.php#L16) e [router.php:39](../../monitor/router.php#L39).

O monitor herda o mesmo ambiente, usuário e montagem gravável de todo o projeto usados pelo coletor. A conexão PostgreSQL também usa a mesma credencial. Uma leitura das permissões, executada no container do monitor, confirmou:

```text
rolsuper: true
rolcreatedb: true
rolcreaterole: true
default_transaction_read_only: off
env_readable: true
env_writable: true
token_file_readable: true
token_directory_writable: true
raw_directory_writable: true
archive_directory_writable: true
app_code_writable: true
```

As rotas atuais abrem transações `READ ONLY`, o que protege suas consultas normais. Entretanto, essa escolha do código não restringe as permissões do usuário PostgreSQL nem o acesso do processo aos arquivos. Uma futura falha de leitura de arquivos, SQL ou execução no monitor teria alcance sobre tokens, configuração, backups e banco inteiro.

Há ainda uma diferença entre proteção contra acidentes e imutabilidade: o trigger de `raw_artifacts` bloqueia `UPDATE` e `DELETE`, mas não `TRUNCATE`. Em schema sintético do banco `_test`, `TRUNCATE raw_artifacts CASCADE` foi permitido; a operação foi revertida com rollback. Um superusuário também pode desabilitar triggers e alterar o schema. Nenhum comando destrutivo foi executado no banco principal.

Correção proposta: criar um usuário exclusivo para o monitor com apenas os `SELECT` necessários, sem privilégios administrativos; montar somente código e assets necessários em leitura; retirar tokens, RAW, arquivo de backup e secrets Spotify do serviço web. Remover apenas `env_file` é insuficiente se o projeto inteiro, incluindo `.env`, continuar montado: o bootstrap também carrega esse arquivo. Migrações e restaurações de teste devem usar uma credencial administrativa separada. O coletor deve receber somente os privilégios de operação necessários.

O impacto de um comprometimento seria alto. A revisão não encontrou uma rota que permita ao visitante obter esses privilégios. A documentação do [PostgreSQL sobre superusuários](https://www.postgresql.org/docs/17/role-attributes.html) descreve por que esse papel deve ser reservado à administração.

## A4 — O servidor aceita nomes de host externos

Evidência: [monitor/router.php:8](../../monitor/router.php#L8). Não existe validação de `Host`, `Origin` ou autenticação na API operacional.

Uma requisição local a `/api/status`, com `Host: audit.invalid:8787` e `Origin: http://audit.invalid:8787`, recebeu HTTP 200 e o JSON operacional. Isso confirma a aceitação de um host inesperado. Não foi feito um ataque completo de DNS rebinding no navegador.

A publicação em `127.0.0.1` reduz muito a exposição, mas não identifica o usuário que faz a consulta. Outros processos locais e containers com acesso à rede do serviço podem alcançá-lo. Em condições específicas, DNS rebinding pode fazer uma origem controlada por um site alcançar um serviço local mantendo sua origem no navegador.

A resposta não concede CORS. Assim, um `fetch` comum de outro site não ganha automaticamente permissão para ler o JSON. Restrições de acesso à rede local do navegador também podem impedir tentativas; o [Chrome documenta essas proteções](https://developer.chrome.com/blog/local-network-access). O risco depende do navegador, das permissões e do caminho de acesso.

Correção proposta: validar uma lista exata de hosts locais e a porta esperada antes de conectar ao banco; rejeitar origens externas quando presentes; adicionar testes de regressão. Se o ambiente tiver usuários ou processos locais não confiáveis, adotar autenticação local. Preservar o bind em loopback. O conteúdo atualmente exposto é operacional: horários, contadores, problemas e referências RAW; os corpos e tokens não são servidos pelas rotas examinadas.

## A5 — Alertas nas imagens Docker exigem triagem por componente

Foram analisadas as imagens locais com Docker Scout 1.25.0, sem publicar imagens em registry. Os relatórios completos SARIF ficaram em `.preview/security-audit-20261110/`, pasta ignorada pelo Git. As contagens abaixo são as classificações do scanner naquele momento, não o número de ataques possíveis contra o sistema.

| Imagem | Pacotes indexados | Pacotes sinalizados | Críticos | Altos | Médios | Baixos | Sem classificação | Total |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `listenlab-php:local` | 270 | 30 | 0 | 18 | 19 | 157 | 6 | 200 |
| `postgres:17-bookworm` | 210 | 25 | 4 | 46 | 40 | 62 | 6 | 158 |

Identificadores locais das imagens: aplicação `sha256:fcd518acd06c8033d42e9ce2563eec6e71beab48887f3d84b97fc42b439b6a9e`; banco `sha256:665dc7b37ac52a17e298511414c21d23ffa4e6a6ef6225b3e5d59c7afb35e994`. Eles identificam os artefatos examinados; não são hashes de dados pessoais.

### Os quatro avisos críticos estão no `gosu`

Todos os quatro resultados críticos da imagem do banco apontam para `/usr/local/bin/gosu`, compilado com Go 1.24.6. O PostgreSQL em execução é 17.11. Não são quatro falhas críticas demonstradas no protocolo PostgreSQL.

| CVE reportada | Condição descrita no aviso | Avaliação do uso atual |
| --- | --- | --- |
| CVE-2026-78663 | Servidor HTTP/2 da biblioteca Go | Não foi identificado servidor HTTP/2 implementado pelo `gosu` |
| CVE-2026-39821 | Conversão IDNA usada em uma decisão de permissão | Não foi identificado esse fluxo no uso do utilitário |
| CVE-2026-56857 | Operações de diretório e junctions no Windows | A imagem examinada executa Linux/amd64 |
| CVE-2025-68121 | Retomada de sessão TLS após alteração de configuração | Não foi identificado esse fluxo na inicialização PostgreSQL |

O entrypoint local usa `gosu` para trocar de root para o usuário PostgreSQL e executar o processo. O [projeto gosu](https://github.com/tianon/gosu) descreve esse papel e informa que o utilitário deixa de permanecer no processo após o `exec`. O [aviso oficial de CVE-2025-68121](https://pkg.go.dev/vuln/GO-2026-4337) exige condições específicas de TLS. A ausência de caminho de exploração para os avisos de HTTP/2, IDNA e TLS é uma avaliação do fluxo observado, não uma análise completa de alcance de símbolos do binário.

O scanner indicou versões corrigidas para 61 resultados da imagem do banco, todos em componentes Go incorporados ao utilitário. Atualizar um compilador Go instalado em outro lugar não recompila esse executável: a correção depende de uma imagem ou binário atualizado e validado.

### Bibliotecas Linux e ferramentas presentes

Na imagem da aplicação, os 18 resultados altos se distribuem entre Kerberos, util-linux, glibc, Perl, Expat, zlib e SQLite. Muitos avisos baixos vêm de ferramentas de compilação que permanecem na imagem. Exemplos conferidos no tracker oficial do Debian:

- [CVE-2026-76844 em zlib](https://security-tracker.debian.org/tracker/CVE-2026-76844): a versão Bookworm presente é sinalizada; o aviso envolve a sequência de escrita `gz*` após falha. O armazenamento da aplicação usa `gzencode`/`gzdecode`, sem demonstrar esse caminho específico.
- [CVE-2026-117610 em Expat](https://security-tracker.debian.org/tracker/CVE-2026-117610): envolve processamento de entidades XML; o fluxo de ingestão examinado processa JSON.
- [CVE-2026-119373 em SQLite](https://security-tracker.debian.org/tracker/CVE-2026-119373): exige operações FTS5; a aplicação usa PostgreSQL.

Os servidores Kerberos `kadmind` e `krb5kdc` não estão presentes nos caminhos examinados na imagem PHP; `/etc/fstab` também não contém as entradas de montagem exigidas por alguns avisos de util-linux. Esses fatos reduzem a aplicabilidade de alguns resultados, sem descartar indiscriminadamente todas as CVEs das bibliotecas.

O Scout não indicou versão corrigida para os resultados da imagem PHP na distribuição examinada. Isso não significa que não existam correções upstream ou em outra distribuição. Uma mudança de base precisa de análise e testes. A própria [documentação do Docker Scout](https://docs.docker.com/scout/explore/exceptions/) distingue pacote vulnerável presente de vulnerabilidade explorável.

Ação proposta: reduzir a imagem final, separando ferramentas de build; acompanhar imagens oficiais e advisories da distribuição; atualizar o `gosu` por uma imagem/binário validado; repetir os testes e scans antes de substituir serviços. Registrar justificativas específicas para resultados não aplicáveis. Nenhuma imagem ou serviço foi atualizado nesta auditoria.

## A6 — A conta Spotify é uma convenção, não uma barreira persistente

Evidência: [OAuth.php:80](../../src/Spotify/OAuth.php#L80), [Batches.php:17](../../src/Ingestion/Batches.php#L17) e [RecentlyPlayedParser.php:87](../../src/Ingestion/RecentlyPlayedParser.php#L87).

A autorização guarda o identificador do aplicativo e os tokens, mas não vincula o dataset a um identificador estável da conta. A fonte é sempre `spotify_api`; a chave de evento usa horário e identidade da faixa, sem escopo de conta.

Se o proprietário autorizar outra conta no mesmo diretório/banco, seus eventos podem entrar no histórico atual. Eventos iguais entre contas podem ser deduplicados entre si. Isso é uma limitação já reconhecida no README e na decisão 003, não um suporte multiusuário existente nem uma troca de conta executada nesta revisão.

Antes de habilitar troca ou múltiplas contas, persistir a identidade estável da conta, conferir divergências e incluir esse escopo na fonte/chave por uma mudança de domínio planejada. Na instalação atual, continuar usando a mesma conta.

## A7 — A CI valida comportamento, mas pode automatizar mais verificações

Evidência: [.github/workflows/tests.yml:23](../../.github/workflows/tests.yml#L23).

O workflow instala dependências, valida Composer, verifica sintaxe e executa testes com PostgreSQL separado. Não inclui `composer audit --locked` explícito, scan de imagens nem uma declaração de permissões mínimas do token. As Actions são referenciadas por `@v4` e `@v2`, tags que podem mudar.

Proposta: acrescentar auditoria explícita e recorrente, declarar `permissions: contents: read` e fixar Actions por SHA completo com uma política de atualização. As permissões efetivas dependem também das configurações do repositório, que não foram auditadas. O [GitHub recomenda permissões mínimas e referências imutáveis](https://docs.github.com/en/code-security/tutorials/secure-your-organization/protect-against-threats).

## Proteções presentes e limites de operação

- OAuth com 32 bytes aleatórios de `state`, hash persistido, expiração de dez minutos, comparação constante e uso único. Renovação serializada por lock; tokens não entram no RAW musical.
- TLS verificado, redirects HTTP desativados, endpoint Spotify fixo e validação da URL/parâmetros de paginação. A revisão não encontrou encaminhamento de Bearer para hosts arbitrários.
- PDO com parâmetros vinculados e prepares nativos. Identificadores SQL dinâmicos examinados são internos ou escapados. Não foi encontrada SQL injection nas entradas HTTP examinadas.
- Lista explícita de arquivos públicos, recusa de métodos de escrita, CSP, `no-store` e ausência de CORS aberto. O JavaScript insere dados com `textContent`, sem os sinks de HTML examinados que permitiriam XSS direto.
- SHA-256 dos bytes originais, verificação antes de replay, constraints de identidade, locks e transações por item. Respostas inválidas e erros de quota permanecem auditáveis.
- Backups são efetivamente restaurados em bancos temporários `_test` nos testes de armazenamento. A checagem atual compara os dados das tabelas; não equivale a uma auditoria adversarial de todo o schema ou a assinatura criptográfica do backup.
- `.gitignore` e `.dockerignore` excluem configuração privada, tokens, RAW, arquivo e dumps. Um arquivo ignorado ainda pode ser forçado para o Git ou ter seu conteúdo copiado para código publicável.

O arquivo e os snapshots continuam no mesmo disco. Isso protege contra alguns erros lógicos, mas não contra perda do disco, ransomware ou exclusão da instalação. A medida de resiliência é manter uma cópia privada em outro meio e exercitar a recuperação; a existência dessa cópia externa não foi verificada.

O servidor `php -S` é adequado ao ambiente local controlado desta instalação. O [manual PHP](https://www.php.net/commandline.webserver) orienta que ele não seja usado em rede pública. O projeto não oferece autenticação multiusuário ou configuração de produção para publicação externa.

Não foram realizados fuzzing extensivo, testes de carga, análise completa de bibliotecas nativas ou revisão visual em navegador nesta avaliação. Windows, Docker Desktop, extensões do navegador, contas externas e configurações de proteção do GitHub ficaram fora do escopo. O teste de exploração por DNS rebinding também não foi executado.

## Sequência de trabalho recomendada

1. Corrigir A1 e A2 com regressões específicas de arquivos ocultos e falha anterior ao registro da manutenção.
2. Isolar o monitor conforme A3; verificar que ele continua funcionando sem acesso a tokens, dados RAW e privilégios de escrita.
3. Rejeitar hosts inesperados e cobrir A4 por testes HTTP.
4. Tratar os alertas de imagens por componente e alcance; repetir testes e scans em qualquer imagem candidata, preservando o volume existente.
5. Automatizar as consultas de segurança da CI e manter backup privado em outro meio.
6. Implementar a identidade de conta antes de permitir qualquer uso além do dataset pessoal atual.

O código funcional e a configuração privada não foram alterados nesta revisão. Os cenários destrutivos usaram exclusivamente arquivos fictícios e schemas temporários em banco `_test`. Não houve commit, push, PR, publicação do sistema ou reconfiguração dos serviços. O scheduler permaneceu operando durante a auditoria, podendo acrescentar dados normalmente.

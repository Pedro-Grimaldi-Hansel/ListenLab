# Revisão e organização da documentação — 12/11/2026

[Histórico](README.md) · [Índice principal](../README.md)

A documentação foi revisada para separar procedimentos atuais de registros de implementação. O README da raiz passou a apresentar o projeto, o comando de inicialização e os caminhos de leitura; o índice completo fica em `docs/README.md`.

## Organização adotada

| Local | Finalidade |
| --- | --- |
| `docs/README.md` | Entrada e navegação por objetivo |
| `docs/funcionamento-atual.md` | Componentes, fluxos e garantias |
| `docs/continuar-no-vscode.md` | Retomada do trabalho nesta instalação |
| `docs/guias/` | Instalação, uso diário, interface, problemas e desenvolvimento |
| `docs/referencia/` | Comandos, variáveis, schema, RAW, auditoria, API e privacidade |
| `docs/pesquisas/` | Experimentos e evidências sobre a fonte |
| `docs/historico/` | Revisões e resultados datados |
| `docs/decisions/` | Decisões e relações entre elas |

## Conteúdo revisado

Os fluxos foram conferidos com `Console`, `Collector`, `Ingestor`, `Scheduler`, armazenamento, router, consultas analíticas, grants, migrations e Compose. Foram corrigidas referências à entrada antiga `/dashboard`, à criação de detalhe para toda repetição e à ausência de consulta de perfil. `/` abre `/analytics`; repetições completas conhecidas podem ficar só nos contadores; o perfil é consultado para conferir o vínculo, sem entrar no RAW musical.

As análises foram descritas conforme o código local, incluindo média por dia ativo, concentração de artistas, comparação com período anterior, mudança de ranking, primeira/última reprodução e décadas de lançamento. Essas extensões já estavam como alterações locais antes desta revisão documental; sua implementação e seus arquivos foram preservados.

O registro longo de continuidade foi preservado em [continuidade-2026-11-10-11.md](continuidade-2026-11-10-11.md). Auditoria, correções e pesquisas mantêm seu contexto datado. Os guias passaram a distinguir instalação nova, inicialização cotidiana e atualização de uma instalação existente.

## Escopo de verificação

A revisão documental confere links e âncoras locais, estrutura Markdown, diagramas Mermaid, comandos e nomes contra os arquivos do projeto. A referência Spotify aponta para documentação oficial consultada nesta revisão. Não há nova execução da suíte funcional atribuída a esta alteração de texto; os resultados anteriores continuam identificados por data.

| Verificação desta revisão | Resultado |
| --- | --- |
| Markdown | 36 arquivos, incluindo README da raiz, documentação e exemplo |
| Links e âncoras locais | 293 referências válidas |
| Diagramas | 8 blocos Mermaid aceitos pelo parser |
| Comandos PowerShell | 45 blocos aceitos pelo parser do PowerShell, sem executar os procedimentos |
| Preservação de código/configuração | 91 hashes de arquivos existentes conferidos, incluindo `.env` e os arquivos de análises já modificados |
| Registros históricos | Conteúdo original dos cinco registros de continuidade, auditoria, correções e pesquisas preservado, com links ajustados e notas de contexto |
| Diff | Sem erros de whitespace em `git diff --check` |

Esta alteração não aplica migrations, atualiza serviços ou modifica configuração, histórico musical e dados privados. Commits e pushes continuam com o proprietário.

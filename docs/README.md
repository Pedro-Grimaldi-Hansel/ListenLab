# Documentação do ListenLab

O ListenLab coleta o histórico recente do Spotify, preserva as respostas originais e oferece acompanhamento e análises locais. A documentação está organizada por finalidade: guias de uso, funcionamento, referências técnicas, pesquisas e histórico.

Revisão de organização: **12/11/2026**, conferida com o código presente na pasta, inclusive alterações locais ainda em revisão. Resultados de testes e estados de serviços em documentos datados descrevem a verificação daquela época.

## Por onde começar

| Objetivo | Caminho de leitura |
| --- | --- |
| Só quero ligar e usar | [Início rápido](guias/inicio-rapido.md) → [Monitor](guias/monitor.md) → [Análises](guias/analises.md) |
| Quero entender tudo | [Funcionamento e fluxos](funcionamento-atual.md) → [Schema](referencia/schema.md) → [Armazenamento](referencia/raw-storage.md) |
| Vou preparar outra instalação | [Instalação e autorização](guias/instalacao.md) → [Início rápido](guias/inicio-rapido.md) |
| Algo não funciona | [Solução de problemas](guias/solucao-de-problemas.md) → [Operação](guias/operacao.md) |
| Vou continuar o desenvolvimento | [Continuidade no VS Code](continuar-no-vscode.md) → [Desenvolvimento](guias/desenvolvimento.md) → [Decisões](decisions/README.md) |

## Guias de uso

| Guia | Conteúdo |
| --- | --- |
| [Início rápido](guias/inicio-rapido.md) | Abrir Docker, iniciar serviços, acessar páginas e confirmar a coleta |
| [Instalação](guias/instalacao.md) | Pré-requisitos, configuração inicial, banco, permissões web e OAuth |
| [Operação](guias/operacao.md) | Coleta manual, serviços, frequência, atualização e verificações |
| [Solução de problemas](guias/solucao-de-problemas.md) | Docker indisponível, portas, autorização, banco e falta de novidades |
| [Monitor](guias/monitor.md) | Estados do agendador, histórico de execuções e armazenamento |
| [Análises](guias/analises.md) | Filtros, rankings, métricas, comparações e limites de interpretação |
| [Desenvolvimento](guias/desenvolvimento.md) | Mapa do código, testes isolados e cuidados ao alterar o domínio |

## Funcionamento e referências

O [guia de funcionamento](funcionamento-atual.md) explica os serviços e contém diagramas dos fluxos de autorização, coleta, consulta, replay e manutenção.

| Referência | Conteúdo |
| --- | --- |
| [Comandos](referencia/comandos.md) | CLI, opções, efeitos e códigos de saída |
| [Configuração](referencia/configuracao.md) | Variáveis, valores padrão, portas e aplicação de mudanças |
| [Schema](referencia/schema.md) | Tabelas, relações, migrations, identidade e timestamps |
| [RAW e recuperação](referencia/raw-storage.md) | Conteúdo compartilhado, compressão, backup verificado e restauração |
| [Auditoria enxuta](referencia/auditoria-enxuta.md) | Detalhe por item e interpretação dos contadores |
| [Spotify Web API](referencia/spotify-api.md) | Contrato externo, paginação, OAuth e tratamento de erros |
| [Git e dados privados](referencia/git-privacy.md) | Arquivos privados, exemplos e revisão antes de versionar |
| [Decisões de arquitetura](decisions/README.md) | Motivações, compromissos e decisões que complementam as anteriores |

## Pesquisas e histórico

- [Primeiro experimento com a API](pesquisas/experiment-results.md): resultados observados e limites da medição.
- [Confiabilidade do histórico recente](pesquisas/spotify-history-reliability.md): evidências de atrasos, hipóteses e propostas datadas.
- [Histórico de implementação e revisão](historico/README.md): continuidade, auditoria e correções, com as validações da época.

Pesquisas e histórico preservam o contexto original. Procedimentos antigos podem ter sido substituídos; use os guias para operar a versão atual.

## Como manter esta documentação

| Mudança | Atualizar |
| --- | --- |
| Rotina de uso ou mensagem de erro | Guia correspondente |
| Fluxo entre componentes | `funcionamento-atual.md` |
| Comando, variável ou rota | Referência e guia que a utiliza |
| Identidade, retenção, fonte ou permissões | Referência e decisão de arquitetura, quando houver mudança de regra |
| Resultado de revisão ou experimento | Registro datado em `historico/` ou `pesquisas/` |

Mantenha exemplos fictícios, links relativos e comandos PowerShell executáveis na raiz do projeto. Não registre credenciais, tokens ou identificadores do histórico pessoal. Ao mover documentos, atualize os links entre eles. Preserve `continuar-no-vscode.md`, citado em [AGENTS.md](../AGENTS.md).

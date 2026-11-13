# Continuar o ListenLab no VS Code

[Índice](README.md) · [Funcionamento e fluxos](funcionamento-atual.md)

Esta é uma instalação pessoal existente no Windows. Leia [AGENTS.md](../AGENTS.md), o [README do projeto](../README.md) e as [decisões](decisions/README.md) antes de alterar o domínio. Os registros de integração e validações de 10–11/11/2026 foram preservados no [histórico de continuidade](historico/continuidade-2026-11-10-11.md).

## Retomar o trabalho

1. Confira `git status --short` e preserve as alterações locais existentes.
2. Para usar a aplicação, siga o [início rápido](guias/inicio-rapido.md). Para modificar o código e executar verificações, use o [guia de desenvolvimento](guias/desenvolvimento.md).
3. Entenda o [fluxo da coleta e do armazenamento](funcionamento-atual.md) e consulte o [schema](referencia/schema.md) antes de mudar entidades, identidade ou auditoria.
4. Ao concluir, atualize a documentação correspondente e deixe as alterações para revisão do proprietário.

## Pontos de atenção desta instalação

| Item | Referência de operação |
| --- | --- |
| Navegador | `http://127.0.0.1:8787`; `/` redireciona para `/analytics` |
| Dashboard | `/dashboard`; confirma atividade pelo heartbeat do agendador |
| Banco | PostgreSQL 17 em volume persistente; dentro do Docker, `db:5432` |
| Porta no Windows | Foi configurada como `15432`; confirme o valor local de `DB_PORT` |
| Coleta | Padrão de `900` segundos, com frequência efetiva mostrada no Dashboard |
| Testes | Banco separado, normalmente `listenlab_test`; nunca o banco principal |
| Schema | Migrations 001–006; confira pendências pelo procedimento de atualização |

Datas, quantidades e estados de serviços registrados no histórico descrevem verificações passadas. Consulte `docker compose ps` e o Dashboard para saber o estado de agora.

## Preservação e autoria

Preserve `.env`, `tokens/`, toda a pasta `storage`, relatórios, dumps e o volume PostgreSQL. Não copie `.env.example` sobre o arquivo existente nem remova volumes para corrigir uma atualização. Exemplos e testes usam conteúdo sintético.

Commits, pushes e eventuais PRs ficam com o proprietário. Não altere sua identidade Git. As instruções completas estão em [AGENTS.md](../AGENTS.md) e em [Git e dados privados](referencia/git-privacy.md).

# Histórico de implementação e revisões

[Índice da documentação](../README.md) · [Funcionamento atual](../funcionamento-atual.md)

Os documentos desta pasta preservam diagnósticos, decisões operacionais e validações da época. Quantidades de testes, versões de imagem, estados dos serviços e instruções de integração são registros datados. Use os [guias](../README.md#guias-de-uso) para operar o código atual.

| Período | Registro | Conteúdo |
| --- | --- | --- |
| 10/11/2026 | [Auditoria inicial](auditoria-2026-11-10.md) | Achados A1–A7, reproduções sintéticas e limites da revisão |
| 10/11/2026, com notas posteriores | [Correções de segurança](correcoes-seguranca-2026-11-10.md) | Isolamento web, conta vinculada, preservação, CI e avisos de imagens remanescentes |
| 10–11/11/2026 | [Continuidade e integração](continuidade-2026-11-10-11.md) | Evolução do monitor, armazenamento, análises, interface e evidências de preservação |
| 12/11/2026 | [Reorganização documental](reorganizacao-2026-11-12.md) | Nova navegação, guias, fluxos e critério de revisão |

O [primeiro experimento](../pesquisas/experiment-results.md) e a [pesquisa de confiabilidade](../pesquisas/spotify-history-reliability.md) estão em `pesquisas/`, pois discutem evidências da fonte e limites de interpretação. As escolhas de arquitetura ficam em [decisions/](../decisions/README.md).

Diagnósticos antigos podem descrever falhas depois corrigidas; os próprios documentos indicam as correções. Referências a linhas de código nos relatórios localizam a análise original e podem mudar conforme o código evolui. Não reinterprete uma validação passada como aprovação automática de alterações posteriores.

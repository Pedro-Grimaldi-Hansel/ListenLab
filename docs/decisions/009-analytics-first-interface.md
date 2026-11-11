# 009 — Interface com Análises como início

Status: aceita por solicitação do proprietário em 11/11/2026. Complementa a decisão 007 e substitui a entrada operacional da primeira interface do monitor.

`/` redireciona para `/analytics`. O nome ListenLab também leva a Análises. Uma única navegação, na ordem Análises / Dashboard / Testes, separa exploração musical, operação da coleta e documentação da suíte. No desktop o menu é lateral; em telas pequenas fica no topo, com rótulos visíveis.

Análises apresenta filtros, resumo do período e um ranking principal com abas para faixas, artistas e álbuns. Gráficos diários, horários e semanais ficam em uma coluna secundária quando há espaço, e abaixo do ranking em telas menores. Os critérios e limitações ficam em “Sobre os dados”. Os atalhos de período e Top consultam ao selecionar; datas personalizadas exigem os dois limites e aplicação explícita. As regras de contagem da decisão 007 permanecem.

O Dashboard prioriza o estado e a próxima execução, depois as métricas e o histórico. Armazenamento/backup ficam após o histórico; a política pode ser expandida. Ajuda e detalhes continuam em diálogos. A tabela operacional tem rolagem própria. Testes apresenta contagens, busca, filtro e grupos expansíveis; abrir a página continua sem executar a suíte.

A paleta mantém verde `#1DB954`, destaque `#1ED760`, preto e cinza, com tipografia do sistema e símbolo próprio. O padrão passa a ser escuro, preservando preferências claras/escuras salvas no navegador. A opção clara permanece disponível nas três telas. Há navegação por teclado, foco visível, link para pular o menu, abas com setas/Home/End e tabela expansível com os valores das 24 horas.

HTML, CSS, JavaScript e ícones SVG são locais, sem novas dependências, fontes externas ou imagens fictícias. As rotas e os scripts das três telas permanecem separados. Esta decisão não modifica a ingestão, o banco, a duração das reproduções ou as permissões de acesso; não requer migration. Histórico e aplicação continuam locais.

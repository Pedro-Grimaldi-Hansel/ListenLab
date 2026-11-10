# ListenLab — instruções para agentes

## Git e autoria

O proprietário quer fazer pessoalmente seus commits e pushes, usando sua identidade Git.

- Edite os arquivos, execute as verificações apropriadas e deixe as alterações para revisão do proprietário.
- Não execute `git commit` ou `git push`, nem crie PRs, sem um pedido explícito posterior do proprietário.
- Não configure identidade de autor/committer de bot ou Codex no Git do usuário.
- Preserve alterações locais existentes. Não use `git reset --hard` ou `git clean` para forçar uma integração.

## Dados e configuração

- Preserve `.env`, `tokens/`, `storage/raw/`, relatórios, dumps e o volume PostgreSQL.
- Nunca publique credenciais, tokens ou arquivos de histórico pessoal. RAW é a fonte da verdade e deve ser preservado.
- Os testes de integração usam banco separado, com nome terminado em `_test`, nunca o banco principal.

## Contexto

Leia `README.md`, `docs/continuar-no-vscode.md` e as decisões em `docs/decisions/` antes de alterar o domínio. A stack é PHP + PostgreSQL; o monitor atual é operacional e local. Não há autorização para publicar o sistema ou seus dados em hospedagem externa.

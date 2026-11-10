# Git e dados privados

As regras que controlam quais arquivos entram no repositório estão no `.gitignore`. A identidade e o remoto do Git não substituem essas regras. A identidade de commit e o remoto do proprietário foram mantidos.

São ignorados `.env` e suas variantes/cópias, tokens, toda a pasta `storage`, backups, dumps, exports, volumes locais de banco, chaves e arquivos de banco ou arquivos compactados. As migrations SQL em `migrations/` são permitidas explicitamente. Somente `.env.example` na raiz é liberado como modelo de configuração; suas credenciais Spotify estão vazias e a senha de exemplo é um placeholder.

Exemplos que podem subir ficam em [`examples/`](../examples/README.md), com dados inteiramente inventados. Mantenha coletas reais, exports do Spotify, relatórios e cópias de banco dentro das pastas privadas. Não cole payloads ou credenciais reais no código, nos exemplos ou na documentação.

Para conferir antes de preparar um commit, na raiz do projeto:

```powershell
git check-ignore -v .env tokens/spotify.json storage/raw storage/archive storage/backups
git ls-files -- .env 'storage/**' 'tokens/**' 'dumps/**' 'exports/**' 'personal-data/**' 'backups/**'
git add --dry-run --all
git diff --cached --name-only
```

O primeiro comando deve identificar as regras de exclusão. O segundo deve ficar sem saída: um arquivo já rastreado continua rastreado mesmo depois de ser ignorado. O terceiro apenas simula os arquivos que entrariam, sem alterar o staging. O último mostra o que já foi preparado para commit.

Na revisão local de 10/11/2026, não havia arquivos privados rastreados ou no staging. Os arquivos publicáveis e os blobs alcançáveis pelas referências Git foram examinados localmente em busca das credenciais atuais e de identificadores de reprodução presentes no banco. Nenhum dado foi enviado a serviço externo para essa revisão.

O `.gitignore` protege o fluxo normal de `git add`; `git add -f` o ignora. Ele também não detecta dados reais colados dentro de um arquivo de código ou um JSON com outro nome. Revise o diff do commit. Nenhum commit, push, alteração de autoria ou envio ao GitHub foi feito pelo assistente.

# Instalação e autorização do Spotify

[Índice](../README.md) · [Início rápido](inicio-rapido.md) · [Configuração](../referencia/configuracao.md)

Este procedimento prepara uma instalação nova. Para ligar a pasta já configurada, use o início rápido. Para atualizar a instalação existente, siga [Operação local](operacao.md#atualizar-a-aplicação).

## Pré-requisitos

- Windows com Docker Desktop funcionando em modo de containers Linux.
- Docker Compose v2.24 ou superior: confira com `docker compose version`.
- Acesso à conta Spotify que será coletada e ao [Developer Dashboard](https://developer.spotify.com/dashboard).

PHP, Composer e ferramentas PostgreSQL ficam nas imagens Docker. Node.js não é necessário para usar a aplicação. A alternativa sem Docker exige PHP 8.4, as extensões e dependências de [composer.json](../../composer.json), PostgreSQL 17 e configuração explícita da credencial web.

## 1. Preparar a configuração privada

Na raiz do projeto, crie `.env` somente se ele ainda não existir:

```powershell
if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item -LiteralPath .env.example -Destination .env
}
```

Edite o arquivo localmente: defina uma senha própria em `POSTGRES_PASSWORD` e mantenha `POSTGRES_DB=listenlab` e `TEST_DB_NAME=listenlab_test` como bancos distintos. Não substitua a configuração de uma instalação existente.

`DB_PORT` controla a porta publicada no Windows. O padrão do modelo é `5432`; esta instalação usou `15432` para evitar conflito. Dentro do Docker, os serviços sempre usam `db:5432`.

## 2. Construir as imagens e preparar o banco

Com Docker Desktop aberto:

```powershell
docker compose build
docker compose up -d db
docker compose run --rm app composer install
docker compose run --rm app php app db:migrate
docker compose run --rm app php app monitor:provision
```

As migrations são incrementais e verificadas por checksum. O provisionamento cria o usuário PostgreSQL de leitura e o secret privado `storage/secrets/monitor-db-password`, sem trocar a senha administrativa. O banco usa um volume persistente.

Alterar `POSTGRES_PASSWORD` no `.env` não troca a senha de um volume já inicializado. Mantenha o volume e confira a configuração antes de tentar corrigir uma conexão.

## 3. Configurar o aplicativo Spotify

Crie ou selecione o app pessoal no Developer Dashboard. No modo de desenvolvimento, confira Premium do proprietário e autorização do usuário na allowlist. As condições são externas e podem mudar; consulte a [referência de quotas](https://developer.spotify.com/documentation/web-api/concepts/quota-modes).

Cadastre o retorno local:

```text
http://127.0.0.1:8888/spotify/callback
```

Preencha `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` e `SPOTIFY_REDIRECT_URI` no `.env` privado. A URI deve corresponder ao cadastro. O Spotify permite HTTP em IP de loopback explícito e recusa `localhost` como redirect URI. [Regras oficiais](https://developer.spotify.com/documentation/web-api/concepts/redirect_uri).

## 4. Autorizar a conta

```powershell
docker compose --profile auth up -d callback
docker compose run --rm app php app spotify:auth
```

Abra a URL exibida e autorize `user-read-recently-played`. O retorno ao callback conclui o processo. Depois:

```powershell
docker compose run --rm app php app spotify:auth:status
docker compose --profile auth stop callback
```

O status mostra datas e scope, sem revelar tokens. Se o navegador não conseguir entregar o retorno, use o prompt oculto:

```powershell
docker compose run --rm app php app spotify:auth:complete
```

Cole a URL completa de retorno apenas nesse prompt. Não a passe em argumentos de shell. O `state` vence em dez minutos e tem uso único; se vencer, inicie outra autorização.

A aplicação renova access tokens automaticamente. `invalid_grant` exige refazer este fluxo. Os tokens ficam em `tokens/`, separados dos RAW musicais.

## 5. Validar e ligar a coleta automática

```powershell
docker compose run --rm app php app spotify:sync
docker compose run --rm app php app raw:verify
docker compose --profile schedule --profile monitor up -d db scheduler monitor
```

Abra o [Dashboard](http://127.0.0.1:8787/dashboard) e as [Análises](http://127.0.0.1:8787/analytics). Uma coleta pode concluir sem novos eventos; confira status, contadores e motivo de parada.

## Conta vinculada ao conjunto de dados

Há uma conta por banco/diretório de dados. A primeira autorização vincula o hash de `account_id`; outra conta é recusada. Para atualizar um histórico antigo que ainda não tenha vínculo, use o token que já operava esse histórico:

```powershell
docker compose run --rm app php app spotify:account:bind
```

Esse é um passo de migração de uma instalação antiga, já realizado nesta pasta segundo o registro de continuidade. Não permite trocar a conta nem identifica retroativamente os RAW antigos. [Decisão 006](../decisions/006-local-security-boundaries.md).

## Ambientes com proxy TLS

O build aceita um secret BuildKit opcional `proxy_ca`:

```powershell
docker build --secret id=proxy_ca,src=C:/caminho/ca.pem -t listenlab-php:local .
```

A CA também precisa estar disponível ao PHP/Composer em execução quando o ambiente exigir. Mantenha a verificação TLS habilitada. Isso é uma configuração de ambiente, sem necessidade de alterar credenciais ou dados musicais.

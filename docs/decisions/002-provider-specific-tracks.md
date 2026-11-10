# 002 — Tracks são específicas do provedor por enquanto

Status: aceita, temporária. Data: 10/11/2026.

`tracks` é uma entidade de catálogo e `plays` é um evento. Cada Spotify ID conhecido identifica uma track própria, com ID interno. Versões original, single, remaster, deluxe, remix e ao vivo não são unificadas, mesmo com nome ou ISRC igual. `linked_from` é preservado como atributo, sem provocar merge.

Um atributo simples `provider` com unicidade de `(provider, external_id)` evita transformar o domínio inteiro em tabelas `spotify_*`. Não foi criada uma camada multiplataforma genérica. Artistas e álbuns seguem a mesma convenção. URIs e IDs recebidos permanecem também no RAW.

Local tracks e entidades sem ID são permitidas. Sem identidade externa confiável não usamos nome/artista como chave definitiva; conservamos uma entidade por evento novo. Repetições da mesma evidência reutilizam sua play/track. Não deduzimos equivalência de URIs locais.

Uma camada futura de obras, gravações e variantes poderá relacionar várias tracks sem apagar suas identidades de plataforma nem alterar a evidência original. A decisão depende de estudo do domínio e dados reais.

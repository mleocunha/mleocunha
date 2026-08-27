# Dependências WordPress (adapters do Painel)

## URLs públicas (sem symlinks)

| Peça | Comportamento |
|------|----------------|
| `/id.php` | Stub real de login (sempre); use este URL — não `wp-login.php` |
| `/painel/*.php` | Gateway de stubs reais; cookies de auth também em `/painel` |
| `/wp-admin` | **Sempre acessível** para activar/actualizar (nunca 404) |
| Nginx | Snippet opcional em `uploads/ve-painel-nginx.conf` |

### Sessão no `/painel` (importante)

O WordPress grava o cookie de autenticação só em `/wp-admin` por omissão. Sem espelhar o cookie para `/painel`, o Painel redirecciona para o login mesmo com sessão válida em `/wp-admin`.

O mu-plugin e o `PlatformUrlMask` espelham `AUTH`/`SECURE_AUTH` em `/painel` no `set_auth_cookie`. **Após actualizar para ≥1.0.37, termine a sessão e entre de novo por `/id.php`** para emitir o cookie novo.

Regra de ouro: **não mascarar links para `/painel` enquanto o gateway (incl. `plugins.php`) não existir em disco**.

Os filtros WordPress (`site_url`, `login_url`, `wp_redirect`, …) devem aceitar argumentos `null` do core — type hints estritos causam `TypeError` fatal na activação (corrigido em 1.0.38).

Fluxo de recuperação:

1. Abrir `https://votoeletronico.com.br/id.php` e autenticar
2. Usar `/wp-admin/` se `/painel/` ainda falhar (sessão antiga)
3. Terminar sessão → entrar outra vez → `/painel/` deve manter a sessão

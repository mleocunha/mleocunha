# Dependências WordPress (adapters do Painel)

## URLs públicas (sem symlinks)

| Peça | Comportamento |
|------|----------------|
| `/id.php` | Stub real de login (sempre); use este URL — não `wp-login.php` |
| `/painel/*.php` | Gateway de stubs reais; cookies de auth também em `/painel` |
| `/wp-admin` | **404** quando o gateway `/painel` está pronto (assets/async liberados) |
| Nginx | Snippet opcional em `uploads/ve-painel-nginx.conf` |

### `/wp-admin` fechado (1.0.43)

Com o gateway pronto, digitar `/wp-admin` devolve **404** (sem redirecionar para `/painel`, para não mapear o disfarce). Continuam acessíveis só:

- estáticos (`.css`, `.js`, fontes, imagens)
- `admin-ajax.php`, `admin-post.php`, `async-upload.php`, `load-scripts.php`, `load-styles.php`

Enquanto o gateway não existir, `/wp-admin` ainda serve de recuperação.

### Stub `/painel` e `$pagenow` (1.0.41)

Os stubs v2 forçam `PHP_SELF`/`SCRIPT_NAME` para `/wp-admin/{arquivo}` antes do `require`.

### Sessão no `/painel`

Espelho de cookies `AUTH`/`SECURE_AUTH` em `/painel`. Depois de atualizar, encerre a sessão e entre de novo por `/id.php`.

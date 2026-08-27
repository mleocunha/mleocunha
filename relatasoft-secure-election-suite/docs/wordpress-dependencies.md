# Dependências WordPress (adapters do Painel)

## URLs públicas (sem symlinks)

| Peça | Comportamento |
|------|----------------|
| `/id.php` | Stub real de login (sempre); use este URL — não `wp-login.php` |
| `/painel/*.php` | Gateway de stubs reais; cookies de auth também em `/painel` |
| `/wp-admin` | **404** quando o gateway `/painel` está pronto (assets/async liberados) |
| `/wp-login.php` | **404** quando `/id.php` existe (sem redirecionar) |
| Nginx | Snippet opcional em `uploads/ve-painel-nginx.conf` |

### Superfícies clássicas fechadas (1.0.43–1.0.44)

Com os stubs prontos, digitar `/wp-admin` ou `/wp-login.php` devolve **404** (sem redirecionar para `/painel` ou `/id.php`, para não mapear o disfarce).

`/wp-admin` ainda libera estáticos e async (`admin-ajax.php`, etc.). Enquanto os stubs não existirem, os caminhos clássicos servem de recuperação.

### Stub `/painel` e `$pagenow` (1.0.41)

Os stubs v2 forçam `PHP_SELF`/`SCRIPT_NAME` para `/wp-admin/{arquivo}` antes do `require`.

### Sessão no `/painel`

Espelho de cookies `AUTH`/`SECURE_AUTH` em `/painel`. Depois de atualizar, encerre a sessão e entre de novo por `/id.php`.

# Dependências WordPress (adapters do Painel)

## URLs públicas (sem symlinks)

| Peça | Comportamento |
|------|----------------|
| `/id.php` | Stub real de login (sempre); use este URL — não `wp-login.php` |
| `/painel/*.php` | Gateway de stubs reais; cookies de auth também em `/painel` |
| `/wp-admin` | **Sempre acessível** para ativar/atualizar (nunca 404) |
| Nginx | Snippet opcional em `uploads/ve-painel-nginx.conf` |

### Stub `/painel` e `$pagenow` (1.0.41)

O WordPress lê `PHP_SELF` com a regex `#/wp-admin/#`. Em `/painel/admin.php` isso **não casa**, e `$pagenow` vira `index.php`. Resultado: `user_can_access_admin_page()` nega todas as telas do plugin («Sem permissão para acessar esta página.»).

Os stubs v2 forçam `PHP_SELF`/`SCRIPT_NAME` para `/wp-admin/{arquivo}` antes do `require`. Depois de atualizar, recarregue o site (ou apague a pasta `painel/` na raiz) para regenerar os stubs.

### Sessão no `/painel`

O WordPress grava o cookie de autenticação só em `/wp-admin` por omissão. O mu-plugin e o `PlatformUrlMask` espelham `AUTH`/`SECURE_AUTH` em `/painel`. **Depois de atualizar, encerre a sessão e entre de novo por `/id.php`.**

Regra de ouro: **não mascarar links para `/painel` enquanto o gateway (incl. `plugins.php`) não existir em disco**.

Fluxo de recuperação:

1. Abra `https://votoeletronico.com.br/id.php` e autentique
2. Use `/wp-admin/` se `/painel/` ainda falhar (sessão ou stub antigo)
3. Encerre a sessão → entre outra vez → confira `/painel/admin.php?page=rses-mode-setup`

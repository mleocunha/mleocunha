# Dependências WordPress (adapters do Painel)

| API / hook | Onde | Motivo |
|------------|------|--------|
| `get_option` / `update_option` | WordPressSettingsRepository | Persistência settings `ve_painel_settings` |
| `get_current_user_id`, `get_userdata`, `wp_get_current_user` | User adapters | Resolver persona |
| `add_role` / `get_role` | GestorRoleRegistrar | Role `ve_gestor` |
| `remove_menu_page` | WordPressMenuChrome | Ocultar menus nativos |
| `admin_bar_menu` / `WP_Admin_Bar::remove_node` | AdminBarCleaner | Limpar admin bar |
| `admin_init` + `wp_safe_redirect` | AdminRedirect | `index.php` → `rses-dashboard` |
| `admin_enqueue_scripts`, `wp_enqueue_*`, `wp_localize_script` | WordPressAssetLoader | Assets sob demanda |
| `login_enqueue_scripts`, `login_headerurl`, `login_headertext`, `login_body_class`, `login_footer`, `gettext` | WordPressLoginBranding | Login branding |
| `in_admin_header` / `in_admin_footer` / `admin_body_class` | ShellView | Chrome do Painel |
| `admin_url`, `home_url`, `esc_*` | Presentation / branding | URLs e escape |
| `admin_url` / `login_url` / rewrites / gateway `/painel/*.php` / stub `id.php` | PlatformUrlMask | `/wp-admin` → `/painel`, `/wp-login.php` → `/id.php` |
| `wp_head` / `robots_txt` / REST / xmlrpc / script `ver=` | FingerprintHardening | Reduzir fingerprint WordPress para bots/crawlers |
| `ModeLock` (RSES) | Bootstrap / HomeView | Modo do sítio |

## URLs públicas (sem links simbólicos)

1. **Gateway PHP** em `ABSPATH/painel/` — ficheiros stub reais (não symlinks) que fazem `require` de `wp-admin/*.php`.
2. **Assets estáticos** (CSS/JS/imagens) continuam em `/wp-admin/` (filtros não mascaram extensões estáticas).
3. **Rewrites WordPress** `^painel/...` → front-controller, para quando o pedido chega ao `index.php` (nginx `try_files`).
4. **Login:** `ABSPATH/id.php` (stub real).
5. **Snippet opcional:** `wp-content/uploads/ve-painel-nginx.conf` para o operador incluir no `server{}` se quiser reforço Nginx — nunca é obrigatório criar `ln -s`.

Não use `ln -sfn wp-admin painel`. Se existir um symlink legado, o plugin remove-o e substitui pelo gateway.

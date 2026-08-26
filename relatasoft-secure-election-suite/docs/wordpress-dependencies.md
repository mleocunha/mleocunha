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
| `ModeLock` (RSES) | Bootstrap / HomeView | Modo do sítio |

Nenhuma edição a `wp-admin` / `wp-includes` / plugins de terceiros.

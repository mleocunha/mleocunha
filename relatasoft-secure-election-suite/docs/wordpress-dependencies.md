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
| `admin_url` / `login_url` / `site_url` / `wp_redirect` + `.htaccess` + stub `id.php` + alias `painel`→`wp-admin` | PlatformUrlMask | `/wp-admin` → `/painel`, `/wp-login.php` → `/id.php` (Apache e nginx) |
| `wp_head` / `robots_txt` / REST / xmlrpc / script `ver=` | FingerprintHardening | Reduzir fingerprint WordPress para bots/crawlers |
| `ModeLock` (RSES) | Bootstrap / HomeView | Modo do sítio |

Artefactos na raiz da instalação: `id.php` (login) e `painel` (symlink ou árvore-stub para `wp-admin`). Em nginx o `.htaccess` é ignorado — o alias em disco é o que torna `/painel/` funcional.

### Nginx (opcional, se o alias em disco falhar)

```nginx
location /painel/ {
    rewrite ^/painel/(.*)$ /wp-admin/$1 last;
}
location = /painel {
    rewrite ^ /wp-admin/ last;
}
```

Ou, na raiz do WordPress: `ln -sfn wp-admin painel`

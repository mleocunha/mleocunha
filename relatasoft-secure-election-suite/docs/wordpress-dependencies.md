# Dependências WordPress (adapters do Painel)

| API / hook | Onde | Motivo |
|------------|------|--------|
| `admin_url` / rewrites / gateway / `id.php` / mu-plugin | PlatformUrlMask | `/painel` e `/id.php` sem symlinks |
| demais | ver código | shell, branding, personas |

## URLs públicas (nginx-safe, sem symlinks)

Problema do nginx: `location ~ \.php$ { try_files $uri =404; }` devolve 404 **antes** do PHP se `/painel/plugins.php` não existir como ficheiro.

Solução do produto:

1. **URLs públicas sem `.php`**: `/wp-admin/plugins.php` → `/painel/plugins` (rewrites + front-controller).
2. **Gateway stub** em `ABSPATH/painel/*.php` (ficheiros reais) para bookmarks legados com `.php`.
3. **Must-use plugin** `wp-content/mu-plugins/ve-painel-gateway.php` — cria o gateway mesmo durante activate/update.
4. **Snippet Nginx** em `uploads/ve-painel-nginx.conf` (`location ^~ /painel`) — recomendado no Virtualmin; faz `/painel/*` ir ao `index.php` sem depender de stubs.
5. Enquanto o gateway não existir, **`/wp-admin` continua acessível** para activar/actualizar o plugin.

Não use `ln -s`.

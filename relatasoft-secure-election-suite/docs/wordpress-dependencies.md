# Dependências WordPress (adapters do Painel)

## URLs públicas (sem symlinks)

| Peça | Comportamento |
|------|----------------|
| `/id.php` | Stub real de login (sempre) |
| `/painel/*.php` | Gateway de stubs reais; só então os links `admin_url` passam a `/painel/…` |
| `/wp-admin` | **Sempre acessível** para activar/actualizar (nunca 404) |
| Nginx | Snippet opcional em `uploads/ve-painel-nginx.conf` |

Regra de ouro: **não mascarar links para `/painel` enquanto o gateway (incl. `plugins.php`) não existir em disco**. Caso contrário a activação no nginx falha com 404.

Fluxo de activação quando `/painel/…` está partido:

1. Abrir `https://votoeletronico.com.br/wp-admin/plugins.php`
2. Activar o plugin
3. Recarregar o sítio (gera gateway + mu-plugin)
4. Passar a usar `/painel/` quando o aviso desaparecer

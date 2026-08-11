# Apache vs Nginx webstack

Virtualmin installs differ:

| Flavour | Website feature | SSL feature | Typical ACME flag |
|---|---|---|---|
| Apache | `--web` | `--ssl` | `--letsencrypt` or `--acme` |
| Nginx plugin | `--virtualmin-nginx` | `--virtualmin-nginx-ssl` | `--acme` |

`virtualmin-snappymail` **detects** the correct stack at runtime and never hardcodes Apache.

## Detection order

1. **Parent domain features** (strongest) — child webmail follows the parent's website stack
2. **`virtualmin help create-domain` flags** — what this host is allowed to create
3. **`virtualmin list-features`** — features available for subservers
4. **OS binaries** (`nginx` / `apache2`) — weak hint only

## Commands

```bash
sudo virtualmin-snappymail audit
# shows: Virtualmin webstack: nginx|apache + feature list

sudo virtualmin-snappymail install exemplo.com.br
# prints: Web stack: nginx|apache

sudo virtualmin-snappymail diagnose exemplo.com.br
# includes: subserver_webstack
```

Mail is never enabled on `webmail.*` subservers, on either stack.

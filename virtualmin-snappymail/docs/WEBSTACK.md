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

## Parent webmail redirect conflict

Before `create-domain`, install clears the parent’s Virtualmin **webmail/admin
redirect** (`modify-web --no-webmail`). Otherwise Nginx/Apache already claims
`webmail.<parent>` and create fails with “virtual host with the same name
already exists”. See `docs/TROUBLESHOOTING.md`.

## Nginx SSL create quirks

Create registers the parent IP as a Virtualmin **shared address** when needed
(`create-shared-address`), then uses `--shared-ip` + `--skip-warnings` for
name-based multi-domain hosts. Falls back to inherit / `--ip/--ip-already`.
On SSL plugin failures it stages website enablement. See `docs/TROUBLESHOOTING.md`.

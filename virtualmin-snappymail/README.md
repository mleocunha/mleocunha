# virtualmin-snappymail

Administrative manager that provisions **SnappyMail** for Virtualmin as a
**web-only Sub-server** `webmail.<parent>` bound to the parent mail domain.

```text
Virtual Server (mail):  exemplo.com.br
Sub-server (web-only):  webmail.exemplo.com.br  → full SnappyMail copy
Login identity:         usuario@exemplo.com.br
```

## Invariants

- Sub-server is created with `--parent` (never `--subdom`).
- Features: website/DNS/SSL/dir/logrotate — **Mail OFF**.
- Full-copy install per domain (no shared `/opt/snappymail`, no symlinks).
- Portable via Virtualmin backup/restore + `discover` / `adopt`.

## Requirements

- Virtualmin/Webmin host (root)
- Parent domain with Mail enabled
- Python 3.10+
- Outbound HTTPS to GitHub Releases (SnappyMail)

This repository’s cloud CI host may not have Virtualmin; use `audit` on the target server.

## Quick start (on a Virtualmin server)

Use **`bash`** explicitly (do not use `sh`, and do not depend on `./` +x alone —
transfers sometimes strip execute bits or introduce CRLF, which makes sudo
report `command not found`):

```bash
cd /home/cunha/virtualmin-snappymail   # or your checkout path
sudo bash bin/install-to-system.sh
sudo virtualmin-snappymail audit
sudo virtualmin-snappymail install votoeletronico.com.br
sudo virtualmin-snappymail status --all
sudo virtualmin-snappymail diagnose votoeletronico.com.br
```

Run from checkout without installing:

```bash
sudo bash -c 'export PYTHONPATH="$PWD/src:$PYTHONPATH"; python3 -m virtualmin_snappymail audit'
# or
sudo python3 bin/virtualmin-snappymail audit
```

If you still get `command not found`, see `docs/TROUBLESHOOTING.md`.## CLI

```bash
virtualmin-snappymail audit
virtualmin-snappymail install dominio.com.br
virtualmin-snappymail status dominio.com.br
virtualmin-snappymail status --all
virtualmin-snappymail diagnose dominio.com.br
virtualmin-snappymail repair dominio.com.br
virtualmin-snappymail upgrade dominio.com.br
virtualmin-snappymail remove dominio.com.br --yes
virtualmin-snappymail remove dominio.com.br --yes --remove-subserver
virtualmin-snappymail discover
virtualmin-snappymail adopt dominio.com.br
virtualmin-snappymail adopt --all
```

Global flags: `--json`, `--verbose`, `--debug`, `--virtualmin-bin PATH`.

## Migration

```text
Server A: Virtualmin backup (include --parent / subservers)
    → Server B: Virtualmin restore
    → virtualmin-snappymail discover
    → virtualmin-snappymail adopt --all
```

Manifest: `{subserver_home}/.virtualmin-snappymail.json` (no secrets).

## Hooks

Optional: point Virtualmin post-domain command at `hooks/post-domain-change.sh`.
Default behaviour is conservative (no auto-install).

## Webmin UI

Skeleton module under `webmin/virtualmin-snappymail/` (Phase 7). Delegates to CLI.

## Tests

```bash
python3 -m unittest discover -s tests -v
```

## Docs

- `docs/AUDIT-PHASE1.md` — environment / API audit
- `docs/DESIGN.md` — architecture
- `docs/BACKUP-RESTORE.md` — backup notes
- `docs/ACCEPTANCE.md` — MVP acceptance checklist

## Licence

AGPL-3.0-or-later for integration code that ships beside SnappyMail (itself AGPL).

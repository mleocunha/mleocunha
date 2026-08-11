# Changelog

## 0.2.3 — 2026-08-11

- CLI accepts `webmail.<parent>` as shorthand for the mail parent.
- Suggest close domain names on missing parent / `status` MODE missing
  (typos like `relatosoft` → `relatasoft`).
- New command `admin-password`: show Admin URL + `admin_password.txt` path
  (file is created by SnappyMail on first `/?Admin` visit).
- `diagnose` checks `snappymail_white_list` (bare domain tokens fail) and
  whether `admin_password.txt` exists.
- Install success prints Admin URL; TROUBLESHOOTING covers `/mnt/…/home`
  Virtualmin homes and exact domain spelling.

## 0.2.2 — 2026-08-11

- Fix domain INI `white_list`: stop writing bare `domínio.com` (SnappyMail never
  matches it → every login “not whitelisted”). Empty whitelist = allow all
  mailboxes on the configured domain. `repair` rewrites the INI.
- Document where White List lives (SnappyMail `/?Admin` → Domains → tab) and
  SSH one-shot clear in `docs/TROUBLESHOOTING.md`.

## 0.2.1 — 2026-08-11

- Virtualmin **Manage Web Apps** installer (`scripts/snappymail.pl`), hybrid option C:
  - **Sub-server mode**: `webmail.<parent>` web-only via CLI (recommended)
  - **Path mode**: install under domain `public_html/<path>` (Roundcube-style)
- CLI: `install --mode subserver|path --path …`
- `install-to-system.sh` deploys the script to `/etc/webmin/virtual-server/scripts/`
- Webmin module from 0.2.0 is **kept** (fleet UI), not removed

## 0.2.0 — 2026-08-11

- Webmin/Virtualmin GUI module: manage SnappyMail as a web-only webapp.
  - Global status table, install form, per-domain manage (diagnose/repair/upgrade/remove)
  - Discover / adopt / audit pages
  - `virtual_feature.pl` plugin: Features and Plugins checkbox + Services → Manage SnappyMail
  - `install-to-system.sh` installs and registers the Webmin module + Virtualmin plugin
- Heal Virtualmin `defip`/`iface` for name-based Nginx hosts (ns1)
- Soft-skip invalid CLI tokens (`localhost`)

## 0.1.0 — 2026-08-10

- Initial release: CLI MVP + portability + upgrade + hooks + Webmin skeleton.
- Commands: audit, install, status, diagnose, repair, upgrade, remove, discover, adopt.
- Web-only subserver via Virtualmin `create-domain --parent` without `--mail`.
- Full-copy SnappyMail per domain with local management manifest.
- Unit and fake-Virtualmin integration tests.

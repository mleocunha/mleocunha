# Changelog

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

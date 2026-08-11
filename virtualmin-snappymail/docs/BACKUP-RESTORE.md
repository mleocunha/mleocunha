# Backup & restore notes

## What Virtualmin carries

When backing up a top-level virtual server **with sub-servers**:

```bash
virtualmin backup-domain \
  --domain exemplo.com.br \
  --parent \
  --all-features \
  --newformat \
  --dest /backup/
```

`--parent` includes the `webmail.exemplo.com.br` sub-server. Feature `dir`
includes the home tree, therefore:

- SnappyMail full copy under `domains/webmail…/public_html/`
- Manifest `.virtualmin-snappymail.json`
- Local `.snappymail-backups/` if present

## What is host-local (not in domain backup)

| Item | Notes |
|---|---|
| `/usr/local/bin/virtualmin-snappymail` | Install manager on target host |
| Webmin module registration | Reinstall module on target |
| Hook path in Virtualmin config | Re-point post-command if used |
| `/etc/virtualmin-snappymail/policy.conf` | Optional policy |
| Manager logs under `/var/log/` | Operational only |

## After restore

```bash
virtualmin-snappymail discover
virtualmin-snappymail adopt exemplo.com.br
# or
virtualmin-snappymail adopt --all
virtualmin-snappymail diagnose exemplo.com.br
```

`adopt` rewrites SnappyMail domain IMAP/SMTP endpoints for the **current**
host (via mail discovery) and refreshes the manifesto without wiping `data/`.

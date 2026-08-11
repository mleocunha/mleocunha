# Webmin / Virtualmin GUI

SnappyMail is managed as a **web-only webapp** per mail parent:

- Sub-server `webmail.<parent>` (Mail OFF)
- Full-copy install in the sub-server document root
- CLI is the source of truth; the GUI is a thin Webmin module

## Install

On the Virtualmin host:

```bash
cd /home/cunha/mleocunha-snappymail/virtualmin-snappymail
sudo bash bin/install-to-system.sh
```

This installs:

1. CLI → `/usr/sbin/virtualmin-snappymail`
2. Webmin module → `$WEBMIN_ROOT/virtualmin-snappymail`
3. Module config → `/etc/webmin/virtualmin-snappymail/config`
4. Registers the Virtualmin plugin (`plugins=` in virtual-server config)

CLI only: `SKIP_WEBMIN=1 sudo bash bin/install-to-system.sh`

## Where to click

| Place | Action |
|---|---|
| Left menu → **SnappyMail** | Global status / install / discover / audit |
| Manage Virtual Server → **Services → Manage SnappyMail** | Per-domain actions (when feature enabled) |
| System Settings → **Features and Plugins** | Enable “SnappyMail webmail” feature |
| Server Templates / Plans | Optional feature checkbox for new domains |

## Behaviour

- **Install** creates `webmail.<parent>` and deploys SnappyMail (same as CLI `install`)
- **Upgrade / repair / diagnose / remove** call the matching CLI commands
- **Discover / adopt** support migration after Virtualmin restore
- Removing the Virtualmin feature removes the **application** by default; set module config `remove_subserver_on_feature_delete=1` to also delete the sub-server

## ACL

Domain owners only see parents granted via Virtualmin (plugin `feature_webmin`) or the module ACL (all vs owned).

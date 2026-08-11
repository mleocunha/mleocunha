# Virtualmin Manage Web Apps (Scripts)

SnappyMail is available as a **Virtualmin webapp installer** (like Roundcube),
in addition to the Webmin module and CLI.

## Install

```bash
sudo bash bin/install-to-system.sh
```

Copies `scripts/snappymail.pl` → `/etc/webmin/virtual-server/scripts/snappymail.pl`.

## Where to click

**Manage Virtual Server → Manage Web Apps → Install Scripts → SnappyMail**
(category **Email**)

Also via CLI:

```bash
virtualmin install-script --domain votoeletronico.com.br --type snappymail --version latest
virtualmin list-scripts --domain votoeletronico.com.br
virtualmin delete-script --domain votoeletronico.com.br --type snappymail
```

## Hybrid modes (option C)

| Mode | When | Result |
|---|---|---|
| **Sub-server** (recommended) | Top-level domain with Mail | Creates `webmail.<domain>` (Mail OFF) + full-copy SnappyMail; login identity = parent |
| **Path** | Any domain with a website | Installs under `public_html/<path>` (default `webmail`), Roundcube-style |

The install form shows both options when the domain is a mail-enabled top-level server; otherwise only **Path** is offered.

## Relation to other UIs

| Surface | Role |
|---|---|
| Manage Web Apps (`snappymail.pl`) | **Canonical Virtualmin webapp** lifecycle |
| Webmin module (`virtualmin-snappymail`) | Extra fleet dashboard (kept; not removed) |
| CLI `virtualmin-snappymail` | Engine used by both |

## Uninstall

From Manage Web Apps → delete the SnappyMail script instance.

- Path mode: removes application files under the path
- Sub-server mode: removes application; optionally deletes `webmail.*` if that option was selected at install time

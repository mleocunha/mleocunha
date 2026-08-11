# Troubleshooting: command not found

## Symptom

```text
sudo ./bin/install-to-system.sh
sudo: ./bin/install-to-system.sh: command not found
```

even though `ls bin/install-to-system.sh` shows the file.

## Causes

1. **CRLF line endings** after ZIP/Windows transfer — shebang becomes `bash\r` → ENOENT → sudo says "command not found".
2. **Missing execute bit** (`chmod +x` lost on copy).
3. **Invoked with `sh`** (Debian `dash`) — `pipefail` / bashisms fail.
4. **`sudo` secure_path** missing `/usr/local/bin` after a manual symlink.

## Fix (copy-paste)

```bash
cd /home/cunha/virtualmin-snappymail

# strip CRLF if present
sed -i 's/\r$//' bin/install-to-system.sh bin/virtualmin-snappymail bin/run-tests.sh

# install via bash (no +x required)
sudo bash bin/install-to-system.sh

# verify
/usr/sbin/virtualmin-snappymail --version
sudo virtualmin-snappymail audit
sudo virtualmin-snappymail install votoeletronico.com.br
```

## One-shot without installer

```bash
cd /home/cunha/virtualmin-snappymail
sudo env PYTHONPATH="$PWD/src${PYTHONPATH:+:$PYTHONPATH}" python3 -m virtualmin_snappymail audit
```

## Verify diagnostics

```bash
ls -l bin/install-to-system.sh
file bin/install-to-system.sh
head -1 bin/install-to-system.sh | od -c | head
command -v bash
command -v python3
sudo bash -n bin/install-to-system.sh && echo syntax_ok
```

## Symptom: Nginx virtual host with the same name already exists

```text
sudo virtualmin-snappymail install votoeletronico.com.br
ERROR [VSM-VIRTUALMIN] ... create-domain ... failed:
An Nginx virtual host with the same name already exists
```

### Cause

Virtualmin’s **Redirect webmail and admin** option adds `webmail.<parent>` (and
`admin.<parent>`) as extra `server_name` entries on the **parent** Nginx/Apache
vhost. Creating a real Sub-server named `webmail.<parent>` then collides.

Leftover `/etc/nginx/sites-*/webmail.<parent>.conf` from a failed earlier create
can cause the same error.

### Automatic fix

Current `virtualmin-snappymail install` runs before `create-domain`:

1. `virtualmin modify-web --domain PARENT --no-webmail`
2. Removes orphan nginx confs for that hostname when Virtualmin does not own it

### Manual fix (if still failing)

```bash
PARENT=votoeletronico.com.br
WM=webmail.$PARENT

virtualmin modify-web --domain "$PARENT" --no-webmail

# confirm Virtualmin does NOT already own webmail.*
virtualmin list-domains --domain "$WM" --name-only

# remove orphan nginx files if present
ls -l /etc/nginx/sites-available/"$WM".conf /etc/nginx/sites-enabled/"$WM".conf 2>/dev/null
rm -f /etc/nginx/sites-available/"$WM".conf /etc/nginx/sites-enabled/"$WM".conf
nginx -t && systemctl reload nginx

# reinstall CLI then retry
cd /home/cunha/mleocunha-snappymail && git pull
cd virtualmin-snappymail && sudo bash bin/install-to-system.sh
sudo virtualmin-snappymail install "$PARENT"
```

## Symptom: uninitialized value in virtualmin-nginx-ssl

```text
ERROR ... create-domain ... --virtualmin-nginx --virtualmin-nginx-ssl failed:
Use of uninitialized value in string eq at
/usr/share/webmin/virtualmin-nginx-ssl/virtual_feature.pl line 130.
```

### Cause

The Nginx SSL plugin compares `$d->{'ip'}` while creating the Sub-server. If the
child has no IP yet, Perl warns once per existing domain and create exits 1.
Also, `virtualmin-nginx-ssl` is often **auto-chained** when `--virtualmin-nginx`
is enabled, so omitting the SSL flag does not avoid the bug.

### Automatic fix

`install` now:

1. Resolves parent IP via multiline + `list-domains --ip-only`
2. Heals Virtualmin Network Settings (`defip=` / `iface=`) when blank so the
   default shared IP can be resolved
3. Tries `create-shared-address` + `--shared-ip` only for *extra* shared IPs;
   the system default IP is inherited from `--parent` (never via `--shared-ip`)
4. Passes `--generate-ssl-cert` / `--link-ssl-cert` / `--acme` when available
5. On failure, creates the Sub-server **without website features**, then
   `enable-feature` for nginx/SSL after the domain already has an IP

## Symptom: New virtual server has no IP address / default IP

```text
Beginning server creation ..
New virtual server has no IP address! Perhaps Virtualmin could not work out
the system's default IP.
```

and often together:

```text
The --default-ip flag can only be used when the virtual server has a private address
The virtual server licenciamento.relatasoft.com.br is already using address 191.176.16.2
```

### Cause (ns1 / name-based Nginx)

`191.176.16.2` is the host’s real public IP, already assigned to existing
virtual servers. It is **not** a Virtualmin private/virt IP, so:

- `--default-ip` is refused
- `create-shared-address --ip 191.176.16.2` is refused (“already using address”)
- create without IP flags fails because Network Settings have no usable
  `iface` / `defip` (Virtualmin cannot compute a default IP)

### Automatic fix

`install` fills blank `defip=` / `iface=` in
`/etc/webmin/virtual-server/config` (Virtualmin’s `get_default_ip()` reads
`defip`, not `localip`), then creates `webmail.<parent>` inheriting that
default address. It does **not** pass `--shared-ip` for the default IP.

### Manual fix (ns1)

```bash
IP=191.176.16.2
PARENT=votoeletronico.com.br
WM=webmail.$PARENT

# 1) find NIC that owns the public IP
ip -4 -o addr show | grep "$IP"
# example: 2: eth0    inet 191.176.16.2/24 ...

# 2) set Virtualmin Network Settings (backup first)
cp -a /etc/webmin/virtual-server/config /etc/webmin/virtual-server/config.vsm-bak
# Webmin UI: System Settings → Virtualmin Configuration → Network Settings
#   → Default virtual server IPv4 address = $IP
#   → Network interface for virtual addresses = <nic from step 1>
#
# Or edit config directly:
grep -E '^(iface|defip)=' /etc/webmin/virtual-server/config || true
# if defip is missing/blank:
#   echo "defip=$IP" >> /etc/webmin/virtual-server/config
# if iface is missing/blank (replace eth0):
#   echo "iface=eth0" >> /etc/webmin/virtual-server/config

systemctl restart webmin || service webmin restart
virtualmin check-config || true

# 3) create webmail inheriting parent IP (no --ip / --shared-ip)
virtualmin list-domains --domain "$WM" --name-only >/dev/null 2>&1 \
  && virtualmin delete-domain --domain "$WM" || true

virtualmin modify-web --domain "$PARENT" --no-webmail || true

virtualmin create-domain --domain "$WM" --parent "$PARENT" \
  --desc "SnappyMail webmail (web-only)" \
  --dir --dns --logrotate --skip-warnings

virtualmin enable-feature --domain "$WM" --virtualmin-nginx --skip-warnings
virtualmin enable-feature --domain "$WM" --virtualmin-nginx-ssl --skip-warnings || true

# 4) install SnappyMail into the new subserver
cd /home/cunha/mleocunha-snappymail && git pull
cd virtualmin-snappymail && sudo bash bin/install-to-system.sh
sudo virtualmin-snappymail install "$PARENT"
```

### Note on `VSM-DOMAIN-INVALID: localhost`

`virtualmin-snappymail install` requires a real parent FQDN
(e.g. `votoeletronico.com.br`). A bare `localhost` (or a mangled paste that
drops the domain) is rejected. Use:

```bash
sudo virtualmin-snappymail list-parents
sudo virtualmin-snappymail install votoeletronico.com.br
```

## Login: “not whitelisted” / “Esta conta não é permitida”

### Where the White List is (not in the Webmin module list)

Whitelist is **inside SnappyMail’s own Admin Panel**, not under Webmin → Virtualmin → SnappyMail menu items.

1. Open `https://webmail.<domínio>/?Admin` (capital **A**; some installs use `/?admin`)
2. Sign in with user **`admin`** and the password from `admin_password.txt` (created on first visit to `/?Admin` — the manager does not invent this file at install time)
3. Menu **Domains** → click `relatasoft.com.br` (or your mail domain)
4. Open the tab **White List** (icon of people / last tab in the domain popup — label may be “Lista branca”)
5. **Clear the textarea completely** and save  
   Empty = allow every mailbox on that domain.  
   If you keep a list, each line must be a full address, a local part, or `@domínio` (with `@`). A bare `domínio.com` matches **nothing**.

Optional: **Packages** → if plugin **Whitelist** is enabled, open its config and clear `White List` or set `*@relatasoft.com.br`.

### One-shot fix on the server (SSH)

Older `virtualmin-snappymail` wrote `white_list = "domínio.com"` (invalid). Clear it.

Virtualmin **Sub-server** layout (not a top-level `/home/webmail.…` home). Homes may be under `/home/…` or a bind such as `/mnt/8GB/home/…`:

```text
{Home directory from Virtualmin}/domains/webmail.<domínio-pai>/public_html/
# often:
/home/<unix-user-do-pai>/domains/webmail.<domínio-pai>/public_html/
```

Example if the parent `relatasoft.com.br` is owned by Unix user `relatasoft` on ns1:

```text
/mnt/8GB/home/relatasoft/domains/webmail.relatasoft.com.br/public_html/
```

Discover + clear:

```bash
# Resolve HTML dir from Virtualmin (preferred — works with /mnt homes)
virtualmin list-domains --domain webmail.relatasoft.com.br --multiline \
  | sed -n 's/^[[:space:]]*HTML directory:[[:space:]]*//p'

HTML=$(virtualmin list-domains --domain webmail.relatasoft.com.br --multiline \
  | sed -n 's/^[[:space:]]*HTML directory:[[:space:]]*//p' | head -1)

# Clear invalid whitelist:
sudo sed -i 's/^white_list = .*/white_list = ""/' \
  "$HTML/data/_data_/_default_/domains/"*.ini
sudo grep white_list "$HTML/data/_data_/_default_/domains/"*.ini

# Or locate under common home roots:
sudo find /home /mnt/*/home -path '*/domains/webmail.*/public_html/data/_data_/_default_/domains/*.ini' \
  2>/dev/null -exec grep -l '^white_list' {} \; \
  -exec sed -i 's/^white_list = .*/white_list = ""/' {} \;
```

Admin password (after opening `/?Admin` once):

```bash
sudo virtualmin-snappymail admin-password relatasoft.com.br
# spelling must match Virtualmin exactly: relatAsoft.com.br
sudo cat "$HTML/data/_data_/_default_/admin_password.txt"
# login: user "admin" + that passphrase at https://webmail.relatasoft.com.br/?Admin
```

Then retry login / the Votador PoC. After upgrading the manager:

```bash
sudo virtualmin-snappymail list-parents          # exact names
sudo virtualmin-snappymail repair relatasoft.com.br
sudo virtualmin-snappymail diagnose relatasoft.com.br
```

`repair` rewrites the domain INI with an empty whitelist. `diagnose` fails `snappymail_white_list` if a bare domain is still present. Typos like `relatosoft` (o) vs `relatasoft` (a) make `status` show `MODE missing` — use `list-parents` or accept the “Did you mean …?” hint.

# Troubleshooting: command not found

## Symptom

```text
sudo virtualmin-snappymail audit
sudo: virtualmin-snappymail: command not found
```

## Cause

`sudo` uses `secure_path` from `/etc/sudoers`. On many hosts that path includes
`/usr/sbin` (where `virtualmin` lives) but a manual symlink under
`/usr/local/bin` may be missing, dangling, or ignored.

Also: a symlink created with `ln -sf` to a wrong source path becomes **dangling**;
running it often surfaces as `command not found` under sudo.

## Fix

From the package directory:

```bash
cd /path/to/virtualmin-snappymail
chmod +x bin/virtualmin-snappymail bin/install-to-system.sh
sudo ./bin/install-to-system.sh
sudo virtualmin-snappymail --version
sudo virtualmin-snappymail audit
```

Or run the checkout entrypoint directly (no PATH needed):

```bash
sudo ./bin/virtualmin-snappymail audit
```

## Verify

```bash
ls -l /usr/sbin/virtualmin-snappymail
head -5 /usr/sbin/virtualmin-snappymail
python3 -c 'import sys; print(sys.version)'
sudo /usr/sbin/virtualmin-snappymail --version
```

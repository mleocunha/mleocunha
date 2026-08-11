#!/bin/bash
# Install CLI + Webmin/Virtualmin GUI module onto a Virtualmin host (idempotent).
#
# Preferred invocation (does not require +x, tolerates odd transfers):
#   sudo bash bin/install-to-system.sh
#
# Default CLI target is /usr/sbin so `sudo virtualmin-snappymail` works even when
# sudoers secure_path omits /usr/local/bin (same location as the virtualmin CLI).
# Set SKIP_WEBMIN=1 to install only the CLI.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN_TARGET="${1:-/usr/sbin/virtualmin-snappymail}"
LIB_TARGET="${LIB_TARGET:-/usr/local/lib/virtualmin-snappymail}"
LOCAL_BIN_LINK="${LOCAL_BIN_LINK:-/usr/local/bin/virtualmin-snappymail}"
SKIP_WEBMIN="${SKIP_WEBMIN:-0}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root (or: sudo bash bin/install-to-system.sh)" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required but was not found in PATH." >&2
  exit 1
fi

if [ ! -d "$ROOT/src/virtualmin_snappymail" ]; then
  echo "Package source missing: $ROOT/src/virtualmin_snappymail" >&2
  exit 1
fi

# Normalize line endings on shipped scripts if a Windows/CRLF transfer corrupted them.
for f in "$ROOT/bin/virtualmin-snappymail" "$ROOT/bin/install-to-system.sh" "$ROOT/bin/run-tests.sh"; do
  if [ -f "$f" ] && grep -q $'\r' "$f" 2>/dev/null; then
    echo "Normalizing CRLF -> LF: $f"
    sed -i 's/\r$//' "$f"
  fi
done

install -d "$(dirname "$BIN_TARGET")"
install -d "$LIB_TARGET"
rm -rf "${LIB_TARGET}/virtualmin_snappymail"
cp -a "$ROOT/src/virtualmin_snappymail" "$LIB_TARGET/"
cp -a "$ROOT/VERSION" "$LIB_TARGET/VERSION"
cp -a "$ROOT/VERSION" "$(dirname "$LIB_TARGET")/VERSION"

# Wrapper with LF-only content written by this bash process.
cat >"$BIN_TARGET" <<'EOF'
#!/bin/bash
set -eu
LIB_TARGET="/usr/local/lib/virtualmin-snappymail"
export PYTHONPATH="${LIB_TARGET}${PYTHONPATH:+:$PYTHONPATH}"
exec python3 -m virtualmin_snappymail "$@"
EOF
# If LIB_TARGET was customized, rewrite the embedded path.
if [ "$LIB_TARGET" != "/usr/local/lib/virtualmin-snappymail" ]; then
  cat >"$BIN_TARGET" <<EOF
#!/bin/bash
set -eu
export PYTHONPATH="${LIB_TARGET}\${PYTHONPATH:+:\${PYTHONPATH}}"
exec python3 -m virtualmin_snappymail "\$@"
EOF
fi
chmod 0755 "$BIN_TARGET"

install -d "$(dirname "$LOCAL_BIN_LINK")"
ln -sfn "$BIN_TARGET" "$LOCAL_BIN_LINK"

# Also clear any stale bytecode
find "$LIB_TARGET" -type d -name '__pycache__' -exec rm -rf {} + 2>/dev/null || true
find "$LIB_TARGET" -type f -name '*.pyc' -delete 2>/dev/null || true

# Also ensure checkout launcher is executable for direct use.
chmod 0755 "$ROOT/bin/virtualmin-snappymail" "$ROOT/bin/install-to-system.sh" "$ROOT/bin/run-tests.sh" 2>/dev/null || true

echo "Installed CLI:"
echo "  $BIN_TARGET"
echo "  $LOCAL_BIN_LINK -> $BIN_TARGET"
echo "  library: $LIB_TARGET"
echo
"$BIN_TARGET" --version
echo

# ---------------------------------------------------------------------------
# Webmin / Virtualmin GUI module
# ---------------------------------------------------------------------------
install_webmin_module() {
  local webmin_root="" conf_dir="/etc/webmin/virtualmin-snappymail"
  local mod_src="$ROOT/webmin/virtualmin-snappymail"
  local mod_name="virtualmin-snappymail"

  if [ ! -d "$mod_src" ]; then
    echo "Webmin module source missing: $mod_src (skipping GUI)" >&2
    return 0
  fi

  if [ -f /etc/webmin/miniserv.conf ]; then
    webmin_root="$(grep -E '^root=' /etc/webmin/miniserv.conf | head -1 | cut -d= -f2- || true)"
  fi
  if [ -z "$webmin_root" ] || [ ! -d "$webmin_root" ]; then
    for candidate in /usr/share/webmin /usr/libexec/webmin /opt/webmin; do
      if [ -d "$candidate" ]; then
        webmin_root="$candidate"
        break
      fi
    done
  fi
  if [ -z "$webmin_root" ] || [ ! -d "$webmin_root" ]; then
    echo "Webmin root not found — CLI installed; GUI skipped."
    echo "  (Install Webmin/Virtualmin, then re-run this script.)"
    return 0
  fi

  echo "Installing Webmin module into $webmin_root/$mod_name …"
  rm -rf "$webmin_root/$mod_name"
  cp -a "$mod_src" "$webmin_root/$mod_name"
  # Ensure CGI scripts are executable
  find "$webmin_root/$mod_name" -type f \( -name '*.cgi' -o -name '*.pl' \) -exec chmod 755 {} +

  install -d "$conf_dir"
  if [ ! -f "$conf_dir/config" ]; then
    cp -a "$mod_src/config" "$conf_dir/config"
  else
    # Keep admin customizations; ensure cli_path points at installed binary.
    if grep -q '^cli_path=' "$conf_dir/config"; then
      sed -i "s|^cli_path=.*|cli_path=$BIN_TARGET|" "$conf_dir/config"
    else
      echo "cli_path=$BIN_TARGET" >>"$conf_dir/config"
    fi
  fi

  # Grant module to root via webmin.acl if present.
  if [ -f /etc/webmin/webmin.acl ]; then
    if grep -qE '^root:' /etc/webmin/webmin.acl; then
      if ! grep -E '^root:' /etc/webmin/webmin.acl | grep -qw "$mod_name"; then
        sed -i -E "s|^(root:.*)|\1 $mod_name|" /etc/webmin/webmin.acl
      fi
    else
      echo "root: $mod_name" >>/etc/webmin/webmin.acl
    fi
  fi

  # Register as a Virtualmin plugin (Features and Plugins).
  local vs_config="/etc/webmin/virtual-server/config"
  if [ -f "$vs_config" ]; then
    if grep -qE '^plugins=' "$vs_config"; then
      if ! grep -E '^plugins=' "$vs_config" | grep -qw "$mod_name"; then
        sed -i -E "s|^(plugins=.*)|\1 $mod_name|" "$vs_config"
        echo "  Registered Virtualmin plugin: $mod_name"
      else
        echo "  Virtualmin plugin already registered: $mod_name"
      fi
    else
      echo "plugins=$mod_name" >>"$vs_config"
      echo "  Registered Virtualmin plugin: $mod_name"
    fi
  else
    echo "  virtual-server config not found — enable the plugin manually under"
    echo "  Virtualmin → System Settings → Features and Plugins."
  fi

  # Refresh module info cache so the new module appears immediately.
  rm -f /etc/webmin/module.infos.cache \
        /etc/webmin/installed.cache \
        /var/webmin/module.infos.cache 2>/dev/null || true

  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet webmin 2>/dev/null; then
    systemctl restart webmin >/dev/null 2>&1 || true
  elif [ -x /etc/webmin/restart ]; then
    /etc/webmin/restart >/dev/null 2>&1 || true
  fi

  echo "Installed GUI:"
  echo "  module: $webmin_root/$mod_name"
  echo "  config: $conf_dir/config"
  echo "  Open: Virtualmin left menu → SnappyMail"
  echo "  Domain: Manage Virtual Server → Services → Manage SnappyMail"
  echo "  Feature: System Settings → Features and Plugins → SnappyMail webmail"
  echo "  Web app: Manage Web Apps → Install Scripts → SnappyMail"
}

install_virtualmin_script() {
  local script_src="$ROOT/scripts/snappymail.pl"
  local script_dir="/etc/webmin/virtual-server/scripts"
  if [ ! -f "$script_src" ]; then
    echo "Virtualmin script source missing: $script_src (skipping)" >&2
    return 0
  fi
  if [ ! -d /etc/webmin/virtual-server ]; then
    echo "Virtualmin not detected — Manage Web Apps script skipped."
    return 0
  fi
  install -d "$script_dir"
  cp -a "$script_src" "$script_dir/snappymail.pl"
  chmod 644 "$script_dir/snappymail.pl"
  # Clear script caches so SnappyMail appears under Install Scripts.
  rm -f /etc/webmin/virtual-server/script.cache \
        /etc/webmin/virtual-server/script_versions.cache \
        /var/webmin/virtual-server/script.cache 2>/dev/null || true
  echo "Installed Virtualmin webapp installer:"
  echo "  $script_dir/snappymail.pl"
  echo "  Open: Manage Virtual Server → Manage Web Apps → Install Scripts → SnappyMail"
}

if [ "$SKIP_WEBMIN" != "1" ]; then
  install_webmin_module
  install_virtualmin_script
fi

echo
echo "Try:"
echo "  virtualmin-snappymail audit"
echo "  $BIN_TARGET audit"
echo "  virtualmin-snappymail install exemplo.com.br"

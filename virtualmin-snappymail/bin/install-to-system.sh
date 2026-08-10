#!/usr/bin/env bash
# Install CLI onto a Virtualmin host (idempotent).
#
# Default target is /usr/sbin so `sudo virtualmin-snappymail` works even when
# sudoers secure_path omits /usr/local/bin (same location as the virtualmin CLI).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN_TARGET="${1:-/usr/sbin/virtualmin-snappymail}"
LIB_TARGET="${LIB_TARGET:-/usr/local/lib/virtualmin-snappymail}"
LOCAL_BIN_LINK="${LOCAL_BIN_LINK:-/usr/local/bin/virtualmin-snappymail}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root (or via sudo)." >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required but was not found in PATH." >&2
  exit 1
fi

if [[ ! -d "$ROOT/src/virtualmin_snappymail" ]]; then
  echo "Package source missing: $ROOT/src/virtualmin_snappymail" >&2
  exit 1
fi

install -d "$(dirname "$BIN_TARGET")"
install -d "$LIB_TARGET"
rm -rf "${LIB_TARGET}/virtualmin_snappymail"
cp -a "$ROOT/src/virtualmin_snappymail" "$LIB_TARGET/"
cp -a "$ROOT/VERSION" "$LIB_TARGET/VERSION"
# get_manager_version() walks parents of the package dir looking for VERSION
cp -a "$ROOT/VERSION" "$(dirname "$LIB_TARGET")/VERSION"

cat >"$BIN_TARGET" <<EOF
#!/usr/bin/env bash
set -euo pipefail
export PYTHONPATH="${LIB_TARGET}\${PYTHONPATH:+:\${PYTHONPATH}}"
exec python3 -m virtualmin_snappymail "\$@"
EOF
chmod 0755 "$BIN_TARGET"

# Convenience link for interactive shells (may be outside sudo secure_path).
install -d "$(dirname "$LOCAL_BIN_LINK")"
ln -sfn "$BIN_TARGET" "$LOCAL_BIN_LINK"

echo "Installed:"
echo "  $BIN_TARGET"
echo "  $LOCAL_BIN_LINK -> $BIN_TARGET"
echo "  library: $LIB_TARGET"
echo
"$BIN_TARGET" --version
echo
echo "Try: virtualmin-snappymail audit"
echo " Or: $BIN_TARGET audit"

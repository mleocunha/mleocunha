#!/bin/bash
# Install CLI onto a Virtualmin host (idempotent).
#
# Preferred invocation (does not require +x, tolerates odd transfers):
#   sudo bash bin/install-to-system.sh
#
# Default target is /usr/sbin so `sudo virtualmin-snappymail` works even when
# sudoers secure_path omits /usr/local/bin (same location as the virtualmin CLI).
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN_TARGET="${1:-/usr/sbin/virtualmin-snappymail}"
LIB_TARGET="${LIB_TARGET:-/usr/local/lib/virtualmin-snappymail}"
LOCAL_BIN_LINK="${LOCAL_BIN_LINK:-/usr/local/bin/virtualmin-snappymail}"

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

# Also ensure checkout launcher is executable for direct use.
chmod 0755 "$ROOT/bin/virtualmin-snappymail" "$ROOT/bin/install-to-system.sh" "$ROOT/bin/run-tests.sh" 2>/dev/null || true

echo "Installed:"
echo "  $BIN_TARGET"
echo "  $LOCAL_BIN_LINK -> $BIN_TARGET"
echo "  library: $LIB_TARGET"
echo
"$BIN_TARGET" --version
echo
echo "Try:"
echo "  virtualmin-snappymail audit"
echo "  $BIN_TARGET audit"

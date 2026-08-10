#!/usr/bin/env bash
# Install CLI onto a Virtualmin host (idempotent).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN_TARGET="${1:-/usr/local/bin/virtualmin-snappymail}"
LIB_TARGET="${LIB_TARGET:-/usr/local/lib/virtualmin-snappymail}"

install -d "$(dirname "$BIN_TARGET")"
install -d "$LIB_TARGET"
rm -rf "${LIB_TARGET}/virtualmin_snappymail"
cp -a "$ROOT/src/virtualmin_snappymail" "$LIB_TARGET/"
cp -a "$ROOT/VERSION" "$LIB_TARGET/VERSION"

cat >"$BIN_TARGET" <<EOF
#!/usr/bin/env bash
set -euo pipefail
export PYTHONPATH="${LIB_TARGET}:\${PYTHONPATH:-}"
# Prefer package VERSION next to lib for get_manager_version fallback via path walk
exec python3 -m virtualmin_snappymail "\$@"
EOF
chmod 0755 "$BIN_TARGET"

# Fix version file discovery: package looks at parents[2]/VERSION relative to __init__
# which under LIB_TARGET/virtualmin_snappymail is LIB_TARGET/../VERSION — place it correctly.
cp -a "$ROOT/VERSION" "$(dirname "$LIB_TARGET")/VERSION" 2>/dev/null || true

echo "Installed $BIN_TARGET"
"$BIN_TARGET" --version

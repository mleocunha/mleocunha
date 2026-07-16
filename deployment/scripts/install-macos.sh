#!/usr/bin/env bash
set -euo pipefail

PLIST_SRC=deployment/launchd/com.relatasoft.openclaw-bridge.plist
PLIST_DST="$HOME/Library/LaunchAgents/com.relatasoft.openclaw-bridge.plist"
LOG_DIR="$HOME/Library/Logs/relatasoft-openclaw"

mkdir -p "$HOME/Library/LaunchAgents" "$LOG_DIR"
# Replace SHARED placeholder with current user home paths before copying in production.
sed "s|/Users/SHARED|$HOME|g" "$PLIST_SRC" > "$PLIST_DST"
echo "Installed $PLIST_DST"
echo "Store DEVICE_PRIVATE_KEY and COMMAND_SIGNING_SECRET in Keychain before load."
echo "Then: launchctl load $PLIST_DST"

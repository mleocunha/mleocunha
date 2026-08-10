#!/usr/bin/env bash
# Virtualmin post-domain-change hook (conservative).
# Install by configuring Virtualmin:
#   System Settings → Virtualmin Configuration → Actions upon server and user creation
#   "Command to run after making changes to a server"
# to this script (or a wrapper that calls it).
#
# Does NOT auto-install SnappyMail. On RESTORE_DOMAIN it logs a hint to run adopt.

set -euo pipefail

ACTION="${VIRTUALSERVER_ACTION:-}"
DOM="${VIRTUALSERVER_DOM:-}"
PARENT="${VIRTUALSERVER_PARENT:-}"
HOME_DIR="${VIRTUALSERVER_HOME:-}"
LOG="${VIRTUALMIN_SNAPPYMAIL_LOG:-/var/log/virtualmin-snappymail-hooks.log}"
MANAGER="${VIRTUALMIN_SNAPPYMAIL_BIN:-virtualmin-snappymail}"

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }

log() {
  printf '%s action=%s domain=%s parent=%s msg=%s\n' "$(ts)" "${ACTION}" "${DOM}" "${PARENT}" "$*" >>"${LOG}" 2>/dev/null || true
}

case "${ACTION}" in
  RESTORE_DOMAIN)
    if [[ "${DOM}" == webmail.* ]] || [[ -f "${HOME_DIR}/.virtualmin-snappymail.json" ]]; then
      log "hint: run '${MANAGER} discover' and '${MANAGER} adopt <parent>' after restore"
    fi
    ;;
  DELETE_DOMAIN)
    if [[ -f "${HOME_DIR}/.virtualmin-snappymail.json" ]]; then
      log "managed snappymail domain deleted: ${DOM}"
    fi
    ;;
  SSL_DOMAIN)
    if [[ "${DOM}" == webmail.* ]]; then
      log "ssl changed for webmail host ${DOM}"
    fi
    ;;
  CREATE_DOMAIN|MODIFY_DOMAIN|DISABLE_DOMAIN|ENABLE_DOMAIN)
    # Intentional no-op unless policy file enables auto-provision.
    POLICY="/etc/virtualmin-snappymail/policy.conf"
    if [[ -f "${POLICY}" ]] && grep -q '^AUTO_INSTALL=1' "${POLICY}" 2>/dev/null; then
      if [[ -z "${PARENT}" && "${ACTION}" == "CREATE_DOMAIN" ]]; then
        # Top-level with mail — optional future auto webmail provision.
        log "AUTO_INSTALL policy present but auto-provision left disabled by default safety"
      fi
    fi
    ;;
  *)
    ;;
esac

exit 0

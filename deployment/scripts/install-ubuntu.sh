#!/usr/bin/env bash
set -euo pipefail

APP_USER=relatasoft-openclaw
APP_ROOT=/opt/relatasoft-openclaw
DATA_ROOT=/var/lib/relatasoft-openclaw
LOG_ROOT=/var/log/relatasoft-openclaw
ETC_ROOT=/etc/relatasoft-openclaw

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi

id -u "$APP_USER" >/dev/null 2>&1 || useradd --system --home "$DATA_ROOT" --shell /usr/sbin/nologin "$APP_USER"
mkdir -p "$APP_ROOT" "$DATA_ROOT" "$LOG_ROOT" "$ETC_ROOT"
chown -R "$APP_USER:$APP_USER" "$DATA_ROOT" "$LOG_ROOT"
cp deployment/systemd/relatasoft-openclaw-gateway.service /etc/systemd/system/
cp deployment/nginx/openclaw-api.relatasoft.com.br.conf /etc/nginx/sites-available/
systemctl daemon-reload
echo "Install app files into $APP_ROOT, configure $ETC_ROOT/gateway.env, enable site and service."

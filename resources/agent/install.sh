#!/usr/bin/env bash
# WatchRex Agent installer — Infrastructure & Uptime Monitoring by Fabapars
# Usage: curl -fsSL __WATCHREX_URL__/agent/install.sh | sudo bash -s -- <AGENT_TOKEN> [interval_seconds]
set -euo pipefail

URL="__WATCHREX_URL__"
TOKEN="${1:-}"
INTERVAL="${2:-60}"

if [ -z "$TOKEN" ]; then
  echo "Usage: install.sh <AGENT_TOKEN> [interval]" >&2
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root (needed to read mail logs, mail queue and panel data)." >&2
  exit 1
fi
command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 1; }

install -d -m 0755 /opt/watchrex
install -d -m 0700 /etc/watchrex /var/lib/watchrex

curl -fsSL "$URL/agent/watchrex-agent.sh" -o /opt/watchrex/watchrex-agent.sh.new
bash -n /opt/watchrex/watchrex-agent.sh.new
mv /opt/watchrex/watchrex-agent.sh.new /opt/watchrex/watchrex-agent.sh
chmod 0755 /opt/watchrex/watchrex-agent.sh

umask 077
cat > /etc/watchrex/agent.conf <<CONF
WATCHREX_URL="$URL"
WATCHREX_TOKEN="$TOKEN"
CONF
chmod 0600 /etc/watchrex/agent.conf

if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  cat > /etc/systemd/system/watchrex-agent.service <<UNIT
[Unit]
Description=WatchRex monitoring agent (one report)
After=network-online.target

[Service]
Type=oneshot
ExecStart=/opt/watchrex/watchrex-agent.sh
Nice=10
IOSchedulingClass=idle
UNIT
  cat > /etc/systemd/system/watchrex-agent.timer <<UNIT
[Unit]
Description=Run WatchRex agent every ${INTERVAL}s

[Timer]
OnBootSec=30
OnUnitActiveSec=${INTERVAL}
AccuracySec=5

[Install]
WantedBy=timers.target
UNIT
  systemctl daemon-reload
  systemctl enable --now watchrex-agent.timer
  echo "Installed systemd timer (every ${INTERVAL}s)."
else
  ( crontab -l 2>/dev/null | grep -v watchrex-agent.sh; echo "* * * * * /opt/watchrex/watchrex-agent.sh >/dev/null 2>&1" ) | crontab -
  echo "Installed cron job (every minute)."
fi

/opt/watchrex/watchrex-agent.sh && echo "🦖 WatchRex agent reported successfully." || echo "First report failed — check the token and that $URL is reachable."
echo "Uninstall: systemctl disable --now watchrex-agent.timer; rm -rf /opt/watchrex /etc/watchrex /var/lib/watchrex /etc/systemd/system/watchrex-agent.*"

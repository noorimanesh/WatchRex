#!/usr/bin/env bash
# WatchRex Agent 1.1 — Fabapars (https://fabapars.com)
# Dependency-free system, mail and hosting-panel collector. Reads /proc and logs
# incrementally (byte offsets), so each run costs a few milliseconds of CPU.
set -uo pipefail
export LC_ALL=C PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

VERSION="1.1.0"
CONF=/etc/watchrex/agent.conf
STATE=/var/lib/watchrex
[ -f "$CONF" ] && . "$CONF"
URL="${WATCHREX_URL:-__WATCHREX_URL__}"
TOKEN="${WATCHREX_TOKEN:-}"
[ -z "$TOKEN" ] && { echo "WATCHREX_TOKEN not set" >&2; exit 1; }
mkdir -p "$STATE"
exec 9>"$STATE/agent.lock"; flock -n 9 2>/dev/null || exit 0

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

esc() { local s=${1//\\/\\\\}; s=${s//\"/\\\"}; s=${s//$'\t'/ }; s=${s//$'\r'/}; s=${s//$'\n'/ }; printf '%s' "$s" | tr -d '\000-\037'; }
num() { [[ "${1:-}" =~ ^[0-9]+([.][0-9]+)?$ ]] && printf '%s' "$1" || printf 'null'; }

# ── CPU / iowait (1s sample) ──────────────────────────────────────────────
read -r _ u1 n1 s1 i1 w1 q1 sq1 st1 _ < /proc/stat
sleep 1
read -r _ u2 n2 s2 i2 w2 q2 sq2 st2 _ < /proc/stat
t1=$((u1+n1+s1+i1+w1+q1+sq1+st1)); t2=$((u2+n2+s2+i2+w2+q2+sq2+st2)); dt=$((t2-t1)); [ $dt -le 0 ] && dt=1
CPU=$(awk -v a=$(( (i2+w2)-(i1+w1) )) -v t=$dt 'BEGIN{printf "%.1f", 100-(a*100/t)}')
IOWAIT=$(awk -v a=$((w2-w1)) -v t=$dt 'BEGIN{printf "%.1f", a*100/t}')
CORES=$(grep -c ^processor /proc/cpuinfo)

# ── Memory ───────────────────────────────────────────────────────────────
eval "$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/{gsub(":","",$1); print $1"="$2}' /proc/meminfo)"
MEM_TOTAL=$((MemTotal*1024)); MEM_USED=$(((MemTotal-MemAvailable)*1024))
RAM=$(awk -v u=$((MemTotal-MemAvailable)) -v t=$MemTotal 'BEGIN{printf "%.1f", u*100/t}')
SWAP=$(awk -v f=${SwapFree:-0} -v t=${SwapTotal:-0} 'BEGIN{ if (t>0) printf "%.1f", (t-f)*100/t; else print 0 }')

read -r L1 L5 L15 PROCS_RAW _ < /proc/loadavg
PROCS=${PROCS_RAW#*/}
UPTIME=$(cut -d. -f1 /proc/uptime)

# ── Disks ────────────────────────────────────────────────────────────────
DISKS=""; ROOT_PCT=0
while read -r fs total used _ pct mount; do
  p=${pct%\%}
  ipct=$(df -Pi "$mount" 2>/dev/null | awk 'NR==2{gsub("%","",$5); print $5}')
  [ "$mount" = "/" ] && ROOT_PCT=$p
  DISKS+="{\"mount\":\"$(esc "$mount")\",\"fs\":\"$(esc "$fs")\",\"total\":$((total*1024)),\"used\":$((used*1024)),\"percent\":$(num "$p"),\"inodes\":$(num "$ipct")},"
done < <(df -P -k -x tmpfs -x devtmpfs -x overlay -x squashfs -x iso9660 2>/dev/null | awk 'NR>1 && $2>0')
DISKS="[${DISKS%,}]"

# ── Network rate (delta against previous run) ────────────────────────────
read -r RX TX < <(awk -F'[: ]+' 'NR>2 && $2!="lo" {rx+=$3; tx+=$11} END{print rx+0, tx+0}' /proc/net/dev)
NOW=$(date +%s); RX_RATE=null; TX_RATE=null
if [ -f "$STATE/net" ]; then
  read -r PRX PTX PT < "$STATE/net"
  el=$((NOW-PT)); if [ $el -gt 0 ] && [ "$RX" -ge "$PRX" ]; then RX_RATE=$(((RX-PRX)/el)); TX_RATE=$(((TX-PTX)/el)); fi
fi
echo "$RX $TX $NOW" > "$STATE/net"

TEMP=null
[ -r /sys/class/thermal/thermal_zone0/temp ] && TEMP=$(awk '{printf "%.1f", $1/1000}' /sys/class/thermal/thermal_zone0/temp)

OS=$( (. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME") || uname -s)
KERNEL=$(uname -r)
HOST=$(hostname -f 2>/dev/null || hostname)

# ── Hosting panel ────────────────────────────────────────────────────────
PANEL=""
[ -d /usr/local/cpanel ] && PANEL=cpanel
[ -z "$PANEL" ] && [ -d /usr/local/directadmin ] && PANEL=directadmin
[ -z "$PANEL" ] && [ -d /usr/local/psa ] && PANEL=plesk
[ -z "$PANEL" ] && [ -d /usr/local/CyberCP ] && PANEL=cyberpanel
[ -z "$PANEL" ] && [ -d /www/server/panel ] && PANEL=aapanel
[ -z "$PANEL" ] && [ -d /etc/webmin ] && PANEL=webmin

# ── Services ─────────────────────────────────────────────────────────────
SERVICES=""
svc_state() { local st; st=$(systemctl is-active "$1" 2>/dev/null); echo "${st:-unknown}"; }
if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  UNITS=$(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '$2!="disabled" && $2!="masked" && $2!="static"{print $1}')
  for s in nginx httpd apache2 lsws lshttpd openlitespeed mysqld mariadb mysql postgresql exim postfix dovecot named bind9 pdns pure-ftpd proftpd vsftpd redis redis-server memcached docker cpanel directadmin psa sw-cp-server sw-engine csf lfd imunify360 fail2ban sshd ssh crond cron clamd spamd spamassassin; do
    if grep -qx "$s.service" <<< "$UNITS"; then
      SERVICES+="\"$s\":\"$(svc_state "$s")\","
    fi
  done
  for u in $(grep -E '^(php[0-9.]*-fpm|ea-php[0-9]+-php-fpm|php-fpm[0-9]*)\.service$' <<< "$UNITS" | head -8); do
    n=${u%.service}; SERVICES+="\"$n\":\"$(svc_state "$n")\","
  done
fi
SERVICES="{${SERVICES%,}}"

# ── Docker ───────────────────────────────────────────────────────────────
CONTAINERS="[]"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}' > "$TMP/dstats" 2>/dev/null
  C=""
  while IFS='|' read -r name image state status restarts; do
    name=${name#/}
    cpu=$(awk -F'|' -v n="$name" '$1==n{gsub("%","",$2); print $2}' "$TMP/dstats")
    mem=$(awk -F'|' -v n="$name" '$1==n{split($3,a," / "); print a[1]}' "$TMP/dstats")
    health=""; [[ "$status" == *"(healthy)"* ]] && health=healthy; [[ "$status" == *"(unhealthy)"* ]] && health=unhealthy
    C+="{\"name\":\"$(esc "$name")\",\"image\":\"$(esc "$image")\",\"state\":\"$(esc "$state")\",\"status\":\"$(esc "$status")\",\"cpu\":$(num "$cpu"),\"mem\":\"$(esc "$mem")\",\"restarts\":$(num "$restarts"),\"health\":\"$health\"},"
  done < <(docker ps -aq | xargs -r docker inspect --format '{{.Name}}|{{.Config.Image}}|{{.State.Status}}|{{.State.Status}}{{if .State.Health}} ({{.State.Health.Status}}){{end}}|{{.RestartCount}}' 2>/dev/null | head -100)
  CONTAINERS="[${C%,}]"
fi

# ── Incremental log reader: prints only bytes appended since last run ────
newlog() { # $1=file $2=state-key
  local f="$1" k="$STATE/off.$2" ino size off pino
  [ -r "$f" ] || return 0
  ino=$(stat -c %i "$f"); size=$(stat -c %s "$f")
  if [ -f "$k" ]; then read -r pino off < "$k"; else echo "$ino $size" > "$k"; return 0; fi
  { [ "$ino" != "$pino" ] || [ "$size" -lt "$off" ]; } && off=0
  [ $((size-off)) -gt 52428800 ] && off=$((size-52428800))   # never read more than 50 MB per run
  tail -c +$((off+1)) "$f" 2>/dev/null | head -c $((size-off))
  echo "$ino $size" > "$k"
}
first() { for f in "$@"; do [ -r "$f" ] && { echo "$f"; return; }; done; }

# ── Mail (Exim / Postfix + Dovecot) ──────────────────────────────────────
MTA=""; QUEUE=null; SENT=0; RECV=0; BOUNCED=0; DEFERRED=0; REJECTED=0; LOGIN_OK=0; LOGIN_FAIL=0; BOUNCES=""
MAILLOG=$(first /var/log/maillog /var/log/mail.log)
if command -v exim >/dev/null 2>&1 || [ -x /usr/sbin/exim ]; then
  MTA=exim; QUEUE=$(exim -bpc 2>/dev/null || echo null)
  newlog "$(first /var/log/exim_mainlog /var/log/exim/mainlog /var/log/exim4/mainlog)" exim > "$TMP/mta"
  RECV=$(grep -c ' <= ' "$TMP/mta"); SENT=$(grep -cE ' (=>|->) ' "$TMP/mta")
  BOUNCED=$(grep -c ' \*\* ' "$TMP/mta"); DEFERRED=$(grep -c ' == ' "$TMP/mta"); REJECTED=$(grep -ci 'rejected' "$TMP/mta")
  grep -h 'authenticator failed' "$TMP/mta" | grep -oE '\[[0-9a-f.:]+\]' | tr -d '[]' > "$TMP/fail_ips"
  grep -h 'authenticator failed' "$TMP/mta" | grep -oE 'set_id=[^) ]+' | cut -d= -f2 > "$TMP/fail_users"
  BOUNCES=$(grep ' \*\* ' "$TMP/mta" | tail -10 | cut -c1-280)
elif command -v postqueue >/dev/null 2>&1; then
  MTA=postfix; QUEUE=$(postqueue -p 2>/dev/null | awk '/Requests/{print $5; f=1} END{if(!f) print 0}' | tr -dc '0-9'); QUEUE=${QUEUE:-0}
fi
newlog "$MAILLOG" maillog > "$TMP/maillog"
if [ "$MTA" = postfix ]; then
  SENT=$(grep -c 'status=sent' "$TMP/maillog"); BOUNCED=$(grep -c 'status=bounced' "$TMP/maillog")
  DEFERRED=$(grep -c 'status=deferred' "$TMP/maillog"); REJECTED=$(grep -c 'NOQUEUE: reject' "$TMP/maillog")
  RECV=$(grep -c 'qmgr.*from=<' "$TMP/maillog")
  grep 'SASL.*authentication failed' "$TMP/maillog" | grep -oE '\[[0-9a-f.:]+\]' | tr -d '[]' > "$TMP/fail_ips"
  grep 'SASL.*authentication failed' "$TMP/maillog" | grep -oE 'sasl_username=[^, ]+' | cut -d= -f2 > "$TMP/fail_users"
  BOUNCES=$(grep 'status=bounced' "$TMP/maillog" | tail -10 | cut -c1-280)
fi
# Dovecot IMAP/POP3 logins
LOGIN_OK=$(grep -cE '(imap|pop3)-login: Login: ' "$TMP/maillog")
grep -E '(imap|pop3)-login: .*(auth failed|Authentication failure|authentication failed)' "$TMP/maillog" > "$TMP/dfail"
LOGIN_FAIL=$(( $(wc -l < "$TMP/dfail") + $(cat "$TMP/fail_ips" 2>/dev/null | wc -l) ))
grep -oE 'rip=[0-9a-f.:]+' "$TMP/dfail" | cut -d= -f2 >> "$TMP/fail_ips"
grep -oE 'user=<[^>]*>' "$TMP/dfail" | sed 's/user=<//;s/>//' >> "$TMP/fail_users"
top_json() { # $1=file $2=key
  [ -s "$1" ] || { echo "[]"; return; }
  local out=""; while read -r c v; do [ -n "$v" ] && out+="{\"$2\":\"$(esc "$v")\",\"count\":$c},"; done < <(sort "$1" | uniq -c | sort -rn | head -10)
  echo "[${out%,}]"
}
FAILED_IPS=$(top_json "$TMP/fail_ips" ip); FAILED_USERS=$(top_json "$TMP/fail_users" user)
BJ=""; while IFS= read -r l; do [ -n "$l" ] && BJ+="\"$(esc "$l")\","; done <<< "$BOUNCES"; BJ="[${BJ%,}]"

# SSH brute-force indicator
SSH_FAILED=$(newlog "$(first /var/log/secure /var/log/auth.log)" auth | grep -c 'Failed password')

# ── Top processes ────────────────────────────────────────────────────────
TOP=""; while read -r user cpu mem cmd; do TOP+="{\"user\":\"$(esc "$user")\",\"cpu\":$(num "$cpu"),\"mem\":$(num "$mem"),\"cmd\":\"$(esc "$cmd")\"},"; done < <(ps -eo user:20,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | awk 'NR>1 && NR<=7')
TOP="[${TOP%,}]"

# ── Hosting accounts & disk usage (every 30 min — heavier) ───────────────
ACCOUNTS=""; SITES=""
LAST_ACC=$(cat "$STATE/accounts.ts" 2>/dev/null || echo 0)
if [ $((NOW-LAST_ACC)) -ge 1800 ] || [ "${WATCHREX_FORCE_INVENTORY:-0}" = 1 ]; then
  A=""
  case "$PANEL" in
    cpanel)
      while IFS='|' read -r u d used limit susp plan; do
        [ -z "$u" ] && continue
        used=${used%M}; limit=${limit%M}; [ "$limit" = unlimited ] && limit=null
        A+="{\"user\":\"$(esc "$u")\",\"domain\":\"$(esc "$d")\",\"disk_used_mb\":$(num "$used"),\"disk_limit_mb\":$(num "$limit"),\"suspended\":$([ "$susp" = 1 ] && echo true || echo false),\"plan\":\"$(esc "$plan")\"},"
      done < <(whmapi1 listaccts want=user,domain,diskused,disklimit,suspended,plan 2>/dev/null | awk '
        /^ *- *$/ || /^ +-$/ { if (u!="") print u"|"d"|"du"|"dl"|"s"|"p; u=d=du=dl=s=p=""; next }
        /^ +user:/ {u=$2} /^ +domain:/ {d=$2} /^ +diskused:/ {du=$2} /^ +disklimit:/ {dl=$2} /^ +suspended:/ {s=$2} /^ +plan:/ {$1=""; p=substr($0,2)}
        END { if (u!="") print u"|"d"|"du"|"dl"|"s"|"p }')
      ;;
    directadmin)
      for dir in /usr/local/directadmin/data/users/*/; do
        u=$(basename "$dir"); [ -f "$dir/user.conf" ] || continue
        d=$(grep -m1 '^domain=' "$dir/user.conf" | cut -d= -f2); limit=$(grep -m1 '^quota=' "$dir/user.conf" | cut -d= -f2)
        used=$(grep -m1 '^quota=' "$dir/user.usage" 2>/dev/null | cut -d= -f2); susp=$(grep -m1 '^suspended=' "$dir/user.conf" | cut -d= -f2)
        [ "$limit" = unlimited ] && limit=null
        A+="{\"user\":\"$(esc "$u")\",\"domain\":\"$(esc "$d")\",\"disk_used_mb\":$(num "$used"),\"disk_limit_mb\":$(num "$limit"),\"suspended\":$([ "$susp" = yes ] && echo true || echo false),\"plan\":\"$(esc "$(grep -m1 '^package=' "$dir/user.conf" | cut -d= -f2)")\"},"
      done
      ;;
    plesk)
      while IFS=$'\t' read -r u d size status; do
        A+="{\"user\":\"$(esc "$u")\",\"domain\":\"$(esc "$d")\",\"disk_used_mb\":$(awk -v s="${size:-0}" 'BEGIN{printf "%.1f", s/1048576}'),\"disk_limit_mb\":null,\"suspended\":$([ "${status:-0}" != 0 ] && echo true || echo false)},"
      done < <(plesk db -N -e "SELECT su.login, d.name, d.real_size, d.status FROM domains d JOIN hosting h ON h.dom_id=d.id JOIN sys_users su ON su.id=h.sys_user_id" 2>/dev/null)
      ;;
  esac
  [ -n "$PANEL" ] && ACCOUNTS=",\"accounts\":[${A%,}]"

  # Every hosted site (domain|account|kind) so the hub can offer one-click monitoring.
  : > "$TMP/sites"
  case "$PANEL" in
    cpanel)
      if [ -r /etc/userdatadomains ]; then
        # example.com: user==owner==main|addon|sub|parked==maindomain==docroot==...
        awk -F'==' '{ split($1, a, ": "); print a[1]"|"a[2]"|"$3 }' /etc/userdatadomains >> "$TMP/sites"
      else
        awk -F': ' '$1 != "*" {print $1"|"$2"|main"}' /etc/userdomains 2>/dev/null >> "$TMP/sites"
      fi
      ;;
    directadmin)
      for dir in /usr/local/directadmin/data/users/*/; do
        u=$(basename "$dir")
        while read -r d; do
          [ -z "$d" ] && continue
          echo "$d|$u|main" >> "$TMP/sites"
          [ -r "$dir/domains/$d.subdomains" ] && sed "s/\$/.$d|$u|sub/" "$dir/domains/$d.subdomains" >> "$TMP/sites"
          [ -r "$dir/domains/$d.pointers" ] && cut -d= -f1 "$dir/domains/$d.pointers" | sed "s/\$/|$u|alias/" >> "$TMP/sites"
        done < "$dir/domains.list" 2>/dev/null
      done
      ;;
    plesk)
      plesk db -N -e "SELECT d.name, IFNULL(su.login,''), IF(d.parentDomainId=0,'main','sub') FROM domains d LEFT JOIN hosting h ON h.dom_id=d.id LEFT JOIN sys_users su ON su.id=h.sys_user_id" 2>/dev/null | tr '\t' '|' >> "$TMP/sites"
      plesk db -N -e "SELECT name, '', 'alias' FROM domain_aliases" 2>/dev/null | tr '\t' '|' >> "$TMP/sites"
      ;;
    *)
      { command -v nginx >/dev/null 2>&1 && nginx -T 2>/dev/null | awk '/^[ \t]*server_name/ { for (i = 2; i <= NF; i++) { gsub(";", "", $i); print $i } }'
        for bin in apachectl apache2ctl httpd; do command -v "$bin" >/dev/null 2>&1 && { "$bin" -S 2>/dev/null | awk '/namevhost|alias/ { print $NF }'; break; }; done
      } | sed 's/|.*//' | awk '{print $1"||main"}' >> "$TMP/sites"
      ;;
  esac
  SJ=""
  while IFS='|' read -r d u k; do
    d=$(printf '%s' "$d" | tr 'A-Z' 'a-z')
    # Keep real hostnames only: no wildcards, IPs, localhost or panel service names.
    case "$d" in ''|_|'*'*|localhost*|*.localdomain|*.local) continue ;; esac
    [[ "$d" =~ ^([a-z0-9-]+\.)+[a-z]{2,}$ ]] || continue
    SJ+="{\"domain\":\"$(esc "$d")\",\"account\":\"$(esc "$u")\",\"kind\":\"$(esc "${k:-main}")\"},"
  done < <(sort -u -t'|' -k1,1 "$TMP/sites" | head -5000)
  SITES=",\"sites\":[${SJ%,}]"
  echo "$NOW" > "$STATE/accounts.ts"
fi

# ── Report ───────────────────────────────────────────────────────────────
cat > "$TMP/report.json" <<JSON
{"version":"$VERSION","hostname":"$(esc "$HOST")","os":"$(esc "$OS")","kernel":"$(esc "$KERNEL")","panel":"$PANEL",
"uptime":$(num "$UPTIME"),"cores":$(num "$CORES"),"cpu":$(num "$CPU"),"iowait":$(num "$IOWAIT"),"ram":$(num "$RAM"),
"mem_total":$MEM_TOTAL,"mem_used":$MEM_USED,"swap":$(num "$SWAP"),"disk":$(num "$ROOT_PCT"),"disks":$DISKS,
"load":[$(num "$L1"),$(num "$L5"),$(num "$L15")],"procs":$(num "$PROCS"),"temp":$TEMP,
"net":{"rx_rate":$RX_RATE,"tx_rate":$TX_RATE},"services":$SERVICES,"containers":$CONTAINERS,"top":$TOP,
"mail":{"mta":"$MTA","queue":$(num "$QUEUE"),"sent":$(num "$SENT"),"received":$(num "$RECV"),"bounced":$(num "$BOUNCED"),
"deferred":$(num "$DEFERRED"),"rejected":$(num "$REJECTED"),"login_ok":$(num "$LOGIN_OK"),"login_failed":$(num "$LOGIN_FAIL"),
"failed_ips":$FAILED_IPS,"failed_users":$FAILED_USERS,"recent_bounces":$BJ},
"ssh_failed":$(num "$SSH_FAILED")$ACCOUNTS$SITES}
JSON

curl -fsS -m 20 --retry 2 --retry-delay 3 \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "User-Agent: WatchRex-Agent/$VERSION" \
  --data-binary @"$TMP/report.json" "$URL/api/agent/report" >/dev/null

#!/usr/bin/env bash
# Server tuning for the single MoxDOP VPS (yakup 2026-10-14: "sisteme ek bir şeyler kurman gerekiyorsa yap, performans
# uçsun"). Run as root by deploy.sh after the app is back up; every step is idempotent, checked and undone on failure,
# and a failed step never fails the deploy. What it found and did is written to storage/app/server-tuning.json (MCP
# system-health › tuning).
#
# Measured on 2026-10-14 (system-health): PHP-FPM pm.max_children=5 (Ubuntu default: six requests at once already
# queue, a page waits behind Livewire polls and connector long-polls), OPcache 78 MB used / 8 MB free, PostgreSQL
# shared_buffers=128 MB, work_mem=4 MB, random_page_cost=4, jit=on for a 17 GB database on an 8 GB SSD server, no swap.
#
# Turn off: touch storage/app/server-tuning.off
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPORT="${ROOT}/storage/app/server-tuning.json"
LOG=()
note() { LOG+=("$1"); echo "tune-server: $1"; }

if [[ "$(id -u)" -ne 0 ]]; then
  echo "tune-server: not root; skipped" >&2
  exit 0
fi
if [[ -f "${ROOT}/storage/app/server-tuning.off" ]]; then
  echo "tune-server: storage/app/server-tuning.off present; skipped"
  exit 0
fi

MEM_MB="$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)"
clamp() { local v="$1" lo="$2" hi="$3"; (( v < lo )) && v="$lo"; (( v > hi )) && v="$hi"; echo "$v"; }

# ---------------------------------------------------------------------------------------------------------------
# 1. Swap: without it a memory spike gets PostgreSQL or a worker killed by the kernel (whole site down). A small
#    swap file with low swappiness only catches spikes.
# ---------------------------------------------------------------------------------------------------------------
if [[ -z "$(swapon --noheadings 2>/dev/null)" ]]; then
  FREE_GB="$(df -BG --output=avail / | tail -n1 | tr -dc '0-9')"
  if [[ ! -e /swapfile && "${FREE_GB:-0}" -ge 8 ]]; then
    if fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none; then
      chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile \
        && { grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab; } \
        && note "swap: 2 GB /swapfile açıldı" || { swapoff /swapfile 2>/dev/null; rm -f /swapfile; note "swap: açılamadı"; }
    fi
  else
    note "swap: yok (disk boş alanı ${FREE_GB:-?} GB ya da /swapfile zaten var)"
  fi
fi
if [[ -n "$(swapon --noheadings 2>/dev/null)" ]]; then
  printf 'vm.swappiness=10\n' > /etc/sysctl.d/90-moxdop.conf
  sysctl -q -p /etc/sysctl.d/90-moxdop.conf >/dev/null 2>&1 || true
fi

# A swap that filled up during a spike stays full after it (2026-10-21: 99 % used with 56 % of RAM free), so the next
# spike has nowhere to go. When the swapped pages fit in free memory twice over, they are moved back.
SWAP_USED_MB="$(free -m | awk '/^Swap:/ {print $3}')"
AVAIL_MB="$(free -m | awk '/^Mem:/ {print $7}')"
if [[ "${SWAP_USED_MB:-0}" -gt 256 && "${AVAIL_MB:-0}" -gt $(( ${SWAP_USED_MB:-0} * 2 + 1024 )) ]]; then
  if swapoff -a 2>/dev/null && swapon -a 2>/dev/null; then
    note "swap: ${SWAP_USED_MB} MB doluydu, belleğe geri alındı"
  else
    swapon -a 2>/dev/null || true
  fi
fi

# ---------------------------------------------------------------------------------------------------------------
# 2. PHP-FPM pool and OPcache. Children from memory: ~15 % of RAM at ~75 MB each, 8..24.
# ---------------------------------------------------------------------------------------------------------------
CHILDREN="$(clamp $(( MEM_MB * 15 / 100 / 75 )) 8 24)"
SPARE_MIN=2
SPARE_MAX="$(clamp $(( CHILDREN / 3 )) 4 8)"
START="$(clamp $(( CHILDREN / 4 )) 3 6)"
set_ini() { # file key value — replace an existing (possibly commented) line or append
  local file="$1" key="$2" value="$3" esc
  esc="$(printf '%s' "$key" | sed 's/[.[\*^$]/\\&/g')"
  if grep -qE "^[;[:space:]]*${esc}[[:space:]]*=" "$file"; then
    sed -i -E "s|^[;[:space:]]*${esc}[[:space:]]*=.*|${key} = ${value}|" "$file"
  else
    printf '%s = %s\n' "$key" "$value" >> "$file"
  fi
}
for POOL in /etc/php/*/fpm/pool.d/www.conf; do
  [[ -f "$POOL" ]] || continue
  VER="$(echo "$POOL" | cut -d/ -f4)"
  FPM="php-fpm${VER}"
  command -v "$FPM" >/dev/null 2>&1 || FPM="/usr/sbin/php-fpm${VER}"
  [[ -f "${POOL}.moxdop-orig" ]] || cp -p "$POOL" "${POOL}.moxdop-orig"
  BEFORE="$(mktemp)"; cp -p "$POOL" "$BEFORE"
  set_ini "$POOL" pm dynamic
  set_ini "$POOL" pm.max_children "$CHILDREN"
  set_ini "$POOL" pm.start_servers "$START"
  set_ini "$POOL" pm.min_spare_servers "$SPARE_MIN"
  set_ini "$POOL" pm.max_spare_servers "$SPARE_MAX"
  set_ini "$POOL" pm.max_requests 500
  OPC="/etc/php/${VER}/fpm/conf.d/99-moxdop-opcache.ini"
  OPC_BEFORE="$(cat "$OPC" 2>/dev/null || true)"
  printf '%s\n' '; MoxDOP (deploy/staging/tune-server.sh)' 'opcache.enable=1' 'opcache.memory_consumption=256' \
    'opcache.interned_strings_buffer=32' 'opcache.max_accelerated_files=32531' > "$OPC"
  if "$FPM" -t >/dev/null 2>&1; then
    if ! cmp -s "$BEFORE" "$POOL" || [[ "$OPC_BEFORE" != "$(cat "$OPC")" ]]; then
      systemctl reload "php${VER}-fpm" 2>/dev/null || service "php${VER}-fpm" reload 2>/dev/null || true
      note "php-fpm ${VER}: max_children ${CHILDREN} (start ${START}, spare ${SPARE_MIN}-${SPARE_MAX}), OPcache 256 MB; yeniden yüklendi"
    else
      note "php-fpm ${VER}: ayarlar zaten uygulanmış (max_children ${CHILDREN})"
    fi
  else
    cp -p "$BEFORE" "$POOL"
    if [[ -n "$OPC_BEFORE" ]]; then printf '%s\n' "$OPC_BEFORE" > "$OPC"; else rm -f "$OPC"; fi
    note "php-fpm ${VER}: yapılandırma testi geçmedi, eski hali geri kondu"
  fi
  rm -f "$BEFORE"
done

# ---------------------------------------------------------------------------------------------------------------
# 3. PostgreSQL memory and planner settings (ALTER SYSTEM → postgresql.auto.conf; RESET undoes one). shared_buffers
#    only takes effect after a restart, which happens only in the quiet hours (02:00–06:00 Istanbul).
# ---------------------------------------------------------------------------------------------------------------
psql_q() { runuser -u postgres -- psql -X -qAt -c "$1" 2>/dev/null; }
if command -v psql >/dev/null 2>&1 && id postgres >/dev/null 2>&1 && [[ "$(psql_q 'select 1')" == "1" ]]; then
  SB_MB="$(clamp $(( MEM_MB / 8 )) 256 2048)"
  ECS_MB="$(( MEM_MB / 2 ))"
  # ALTER SYSTEM on every run: it only rewrites postgresql.auto.conf; a reload applies what changed.
  APPLIED=1
  for kv in "shared_buffers=${SB_MB}MB" "effective_cache_size=${ECS_MB}MB" "work_mem=16MB" "maintenance_work_mem=512MB" \
    "random_page_cost=1.1" "effective_io_concurrency=200" "jit=off" "max_wal_size=4GB" "min_wal_size=512MB" \
    "autovacuum_vacuum_scale_factor=0.05" "autovacuum_analyze_scale_factor=0.02"; do
    runuser -u postgres -- psql -X -qAt -c "alter system set ${kv%%=*} = '${kv#*=}'" >/dev/null 2>&1 || APPLIED=0
  done
  psql_q 'select pg_reload_conf()' >/dev/null
  [[ "$APPLIED" -eq 1 ]] || note "postgresql: bazı ayarlar yazılamadı"
  sleep 1
  PENDING="$(psql_q "select string_agg(name, ',') from pg_settings where pending_restart")"
  HOUR="$(TZ=Europe/Istanbul date +%H)"
  if [[ -n "$PENDING" ]]; then
    if (( 10#$HOUR >= 2 && 10#$HOUR < 6 )); then
      SERVICE="$(systemctl list-units --type=service --no-legend 'postgresql@*' 2>/dev/null | awk '{print $1}' | head -n1)"
      SERVICE="${SERVICE:-postgresql}"
      if systemctl restart "$SERVICE" 2>/dev/null; then
        for _ in $(seq 1 30); do [[ "$(psql_q 'select 1')" == "1" ]] && break; sleep 1; done
        # Workers reconnect on their next job; Horizon is restarted so none keeps a dead connection.
        ( cd "$ROOT" && runuser -u www-data -- php artisan horizon:terminate --no-interaction >/dev/null 2>&1 ) || true
        note "postgresql: yeniden başlatıldı (${PENDING} etkin)"
      else
        note "postgresql: yeniden başlatılamadı; ${PENDING} bekliyor"
      fi
    else
      note "postgresql: ${PENDING} gece 02:00-06:00 arasındaki ilk deploy'da yeniden başlatmayla etkinleşecek"
    fi
  fi
  note "postgresql: shared_buffers $(psql_q 'show shared_buffers'), work_mem $(psql_q 'show work_mem'), jit $(psql_q 'show jit')"
else
  note "postgresql: psql ile bağlanılamadı; atlandı"
fi

# ---------------------------------------------------------------------------------------------------------------
# 4. System logs (/var/log was 2.6 GB on 2026-10-21 with the disk at 94 %): the systemd journal is capped at 300 MB
#    and rotated log files older than 14 days are deleted. Live log files are never touched.
# ---------------------------------------------------------------------------------------------------------------
LOG_BEFORE="$(du -sm /var/log 2>/dev/null | cut -f1)"
if command -v journalctl >/dev/null 2>&1; then
  JCONF=/etc/systemd/journald.conf.d/90-moxdop.conf
  mkdir -p "$(dirname "$JCONF")"
  JWANT="$(printf '%s\n' '[Journal]' 'SystemMaxUse=300M' 'MaxRetentionSec=14day')"
  if [[ "$(cat "$JCONF" 2>/dev/null)" != "$JWANT" ]]; then
    printf '%s\n' "$JWANT" > "$JCONF"
    systemctl restart systemd-journald 2>/dev/null || true
  fi
  journalctl --vacuum-size=300M --vacuum-time=14d >/dev/null 2>&1 || true
fi
find /var/log -type f \( -name '*.gz' -o -name '*.[0-9]' -o -name '*.[0-9].log' -o -name '*.old' \) -mtime +14 -delete 2>/dev/null || true
note "loglar: /var/log ${LOG_BEFORE:-?} MB → $(du -sm /var/log 2>/dev/null | cut -f1) MB (journal en çok 300 MB, 14 günden eski dönmüş loglar silindi)"

# ---------------------------------------------------------------------------------------------------------------
mkdir -p "$(dirname "$REPORT")"
{
  printf '{"at":"%s","memory_mb":%s,"lines":[' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${MEM_MB}"
  first=1
  for line in "${LOG[@]}"; do
    [[ $first -eq 1 ]] || printf ','
    first=0
    printf '"%s"' "$(printf '%s' "$line" | sed 's/\\/\\\\/g; s/"/\\"/g')"
  done
  printf ']}\n'
} > "$REPORT"
chown www-data:www-data "$REPORT" 2>/dev/null || true
exit 0

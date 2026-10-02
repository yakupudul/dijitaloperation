#!/usr/bin/env bash
# Issue Let's Encrypt for app.moximu.com and switch Laravel to the HTTPS canonical URL.
# Safe: does not regenerate APP_KEY, does not overwrite unrelated .env secrets,
# never migrate:fresh, does not rewrite the whole Nginx site from scratch.
set -euo pipefail

DOMAIN="${MOXDOP_STAGING_DOMAIN:-app.moximu.com}"
EXPECTED_IP="${MOXDOP_STAGING_IP:-178.105.36.235}"
ROOT="${MOXDOP_APP_ROOT:-/var/www/moxdop}"
ENV_FILE="$ROOT/.env"

die() { echo "enable-https: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "run as root"
[[ -f "$ENV_FILE" ]] || die "missing $ENV_FILE"
[[ -f "$ROOT/artisan" ]] || die "not a Laravel root: $ROOT"
command -v certbot >/dev/null || die "certbot not installed"
command -v dig >/dev/null || die "dig not installed"

A_RECORDS="$(dig +short "$DOMAIN" A | tr -d '\r' | grep -E '^[0-9.]+$' || true)"
echo "enable-https: $DOMAIN A records:" $A_RECORDS

echo "$A_RECORDS" | grep -qx "$EXPECTED_IP" || die "$DOMAIN does not resolve to $EXPECTED_IP (Let's Encrypt would fail). Current: ${A_RECORDS:-none}"
EXTRA="$(echo "$A_RECORDS" | grep -vx "$EXPECTED_IP" || true)"
if [[ -n "$EXTRA" ]]; then
  echo "enable-https: warning: extra A records present: $EXTRA" >&2
fi

if grep -qE '^APP_KEY=$' "$ENV_FILE" || ! grep -qE '^APP_KEY=base64:' "$ENV_FILE"; then
  die "APP_KEY missing — refusing to continue"
fi
KEY_BEFORE="$(grep -E '^APP_KEY=' "$ENV_FILE")"

echo "enable-https: requesting certificate"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect

# Certbot must not resurrect the Livewire static-404 bug.
if grep -q 'try_files $uri =404' /etc/nginx/sites-available/moxdop /etc/nginx/sites-enabled/* 2>/dev/null; then
  die "nginx again contains try_files =404 — Livewire hashed JS would 404. Fix nginx before continuing."
fi
nginx -t
systemctl reload nginx

python3 - "$ENV_FILE" "$DOMAIN" <<'PY'
import sys
from pathlib import Path
path = Path(sys.argv[1])
domain = sys.argv[2]
updates = {
    "APP_URL": f"https://{domain}",
    "APP_FORCE_HTTPS": "true",
    "SESSION_SECURE_COOKIE": "true",
}
text = path.read_text()
lines = text.splitlines(True)
seen = set()
out = []
for line in lines:
    raw = line.strip()
    if raw and not raw.startswith("#") and "=" in raw:
        key = raw.split("=", 1)[0]
        if key in updates:
            out.append(f"{key}={updates[key]}\n")
            seen.add(key)
            continue
    out.append(line)
missing = [f"{k}={v}\n" for k, v in updates.items() if k not in seen]
if missing:
    if out and not out[-1].endswith("\n"):
        out.append("\n")
    out.extend(missing)
path.write_text("".join(out))
PY

KEY_AFTER="$(grep -E '^APP_KEY=' "$ENV_FILE")"
[[ "$KEY_BEFORE" == "$KEY_AFTER" ]] || die "APP_KEY changed — aborting"

chmod 600 "$ENV_FILE"
chown www-data:www-data "$ENV_FILE"

cd "$ROOT"
sudo -u www-data php artisan optimize:clear --no-interaction
sudo -u www-data php artisan config:cache --no-interaction
sudo -u www-data php artisan route:cache --no-interaction
sudo -u www-data php artisan view:cache --no-interaction

echo "enable-https: done — APP_URL=https://${DOMAIN} APP_KEY unchanged"
sudo -u www-data php artisan about --only=environment

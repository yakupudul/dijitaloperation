#!/usr/bin/env bash
# Durable Cursor Cloud install — system toolchain (if missing) + dependencies.
# Idempotent. Must terminate. Do NOT start servers/queues here.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ensure_php_toolchain() {
  if command -v php >/dev/null 2>&1 && php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    return 0
  fi

  echo "cloud-agent-install: PHP 8.3+ missing — installing system packages"
  if command -v sudo >/dev/null 2>&1; then
    SUDO=(sudo)
  else
    SUDO=()
  fi

  export DEBIAN_FRONTEND=noninteractive
  "${SUDO[@]}" apt-get update
  "${SUDO[@]}" apt-get install -y --no-install-recommends \
    ca-certificates curl git unzip zip \
    php8.3-cli php8.3-bcmath php8.3-curl php8.3-intl \
    php8.3-mbstring php8.3-sqlite3 php8.3-xml php8.3-zip php8.3-mysql

  if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer \
      | php -- --install-dir=/tmp --filename=composer
    "${SUDO[@]}" mv /tmp/composer /usr/local/bin/composer
  fi

  if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | "${SUDO[@]}" bash -
    "${SUDO[@]}" apt-get install -y --no-install-recommends nodejs
  fi
}

ensure_php_toolchain

echo "cloud-agent-install: PHP $(php -r 'echo PHP_VERSION;')"
echo "cloud-agent-install: Composer $(composer --version --no-ansi 2>/dev/null | head -1)"
echo "cloud-agent-install: Node $(node -v) / npm $(npm -v)"

composer install --no-interaction --prefer-dist
npm ci
npm run build

echo "cloud-agent-install: complete"

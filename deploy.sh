#!/usr/bin/env bash
#
# Deploys the latest code on the server. Run from the application directory over SSH:
#
#   bash deploy.sh
#
# Safe to run repeatedly. The first run needs a configured .env (see docs/06-kurulum-hostinger.md).
# Set PHP_BIN if the default `php` is not the version the web server uses, for example:
#
#   PHP_BIN=/usr/bin/php8.3 bash deploy.sh

set -euo pipefail

cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
BRANCH="${DEPLOY_BRANCH:-main}"

if [ ! -f .env ]; then
    echo "HATA: .env dosyası yok. Önce docs/06-kurulum-hostinger.md adımlarını izleyin." >&2
    exit 1
fi

echo "==> Kod güncelleniyor ($BRANCH)"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "==> Bağımlılıklar kuruluyor"
"$PHP_BIN" "$(command -v "$COMPOSER_BIN")" install --no-dev --optimize-autoloader --no-interaction

echo "==> Veritabanı güncelleniyor"
"$PHP_BIN" artisan migrate --force

echo "==> Filament dosyaları yayınlanıyor"
"$PHP_BIN" artisan filament:assets

echo "==> Önbellekler yenileniyor"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan icons:cache 2>/dev/null || true

echo "==> Zamanlanmış işçiyi yeniden başlatma sinyali"
"$PHP_BIN" artisan queue:restart

echo "Tamamlandı."

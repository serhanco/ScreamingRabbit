#!/usr/bin/env bash
#
# Builds the ZIP for hosting without a terminal (docs/06): production vendor/ and Filament assets included,
# split into the two folders the shared-hosting layout needs.
#
#   bash tools/build-release.sh [output-dir]      (default output: ./release)
#
# Result: <output-dir>/screamingrabbit-release.zip containing screamingrabbit/ and public_html/.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
OUT="${1:-release}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
APP="$STAGE/screamingrabbit"
PUB="$STAGE/public_html"
mkdir -p "$APP/public" "$PUB"

echo "==> Uygulama dosyaları kopyalanıyor"
git ls-files -z --cached --others --exclude-standard \
    | grep -zv -E '^(tests/|docs/|tools/|\.github/|public/|README\.md|deploy\.sh|phpunit\.xml|package\.json|vite\.config\.js|\.editorconfig|\.gitattributes|\.npmrc)' \
    | xargs -0 cp --parents -t "$APP"

echo "==> Üretim bağımlılıkları kuruluyor"
(cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

echo "==> Gereksiz dosyalar temizleniyor"
# Composer may fall back to git clones, which carry whole repository histories; none of this is needed at runtime.
find "$APP/vendor" -name .git -prune -exec rm -rf {} +
rm -rf "$APP"/vendor/*/*/{tests,Tests,test,docs,.github}

echo "==> Filament dosyaları hazırlanıyor"
(cd "$APP" && php artisan filament:assets)

echo "==> public_html hazırlanıyor"
cp -a "$APP/public/." "$PUB/"
rm -rf "$APP/public"
for file in index.php install.php .htaccess robots.txt favicon.ico; do
    cp "$ROOT/public/$file" "$PUB/$file"
done

mkdir -p "$APP/storage/app/public" "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" \
    "$APP/storage/framework/views" "$APP/storage/logs" "$APP/bootstrap/cache"
rm -f "$APP/.env" "$APP/database/database.sqlite"

openssl rand -hex 8 > "$APP/install.key"

rm -f "$OUT/screamingrabbit-release.zip"
(cd "$STAGE" && zip -qr "$OUT/screamingrabbit-release.zip" screamingrabbit public_html)

echo "==> Hazır: $OUT/screamingrabbit-release.zip ($(du -h "$OUT/screamingrabbit-release.zip" | cut -f1))"

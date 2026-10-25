#!/usr/bin/env bash
#
# Builds the tree that is published to the `deploy` branch (docs/06): production vendor/ and the Filament
# assets included, laid out so the repository root can be a hosting web root.
#
#   <out>/index.php, install.php, .htaccess, css/, js/, fonts/    web-facing files
#   <out>/app/                                                    the application (blocked from the web)
#
#   bash tools/build-deploy.sh <output-dir>

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
OUT="${1:?usage: bash tools/build-deploy.sh <output-dir>}"

rm -rf "$OUT"
mkdir -p "$OUT/app/public"
OUT="$(cd "$OUT" && pwd)"
APP="$OUT/app"

echo "==> Uygulama dosyaları kopyalanıyor"
git ls-files -z --cached --others --exclude-standard \
    | grep -zv -E '^(tests/|docs/|tools/|\.github/|public/)|^(README\.md|deploy\.sh|phpunit\.xml|package\.json|vite\.config\.js|\.editorconfig|\.gitattributes|\.npmrc|\.gitignore)$' \
    | xargs -0 cp --parents -t "$APP"

echo "==> Üretim bağımlılıkları kuruluyor"
(cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

echo "==> Gereksiz dosyalar temizleniyor"
# Composer may fall back to git clones, which carry whole repository histories; none of this is needed at runtime.
find "$APP/vendor" -name .git -prune -exec rm -rf {} +
# Nested ignore files would make `git add` silently skip vendor files in the deploy branch.
find "$APP/vendor" -type f \( -name .gitignore -o -name .gitattributes \) -delete
rm -rf "$APP"/vendor/*/*/{tests,Tests,test,docs,.github} "$APP/vendor/laravel/framework/bin"
find "$APP/vendor" -type d -name test_files -prune -exec rm -rf {} +
find "$APP/vendor" -type f \( -iname 'README*.md' -o -iname 'CHANGELOG*.md' -o -iname 'UPGRADE*.md' -o -iname 'CONTRIBUTING*.md' \) -delete

echo "==> Filament dosyaları hazırlanıyor"
(cd "$APP" && php artisan filament:assets)

echo "==> Web klasörü hazırlanıyor"
cp -a "$APP/public/." "$OUT/"
rm -rf "$APP/public"
# Every tracked file under public/ (entry points, favicons, logo images) goes to the web root.
(cd "$ROOT/public" && git ls-files -z --cached --others --exclude-standard . | xargs -0 cp --parents -t "$OUT")

{
    echo '# Never serve repository metadata or environment files.'
    echo 'RedirectMatch 404 /\.git'
    echo '<FilesMatch "^\.env">'
    echo '    <IfModule mod_authz_core.c>'
    echo '        Require all denied'
    echo '    </IfModule>'
    echo '    <IfModule !mod_authz_core.c>'
    echo '        Order deny,allow'
    echo '        Deny from all'
    echo '    </IfModule>'
    echo '</FilesMatch>'
    echo
    cat "$ROOT/public/.htaccess"
} > "$OUT/.htaccess"

# The application folder sits inside the web root on this layout, so it must never be served.
cat > "$APP/.htaccess" <<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS

# Empty folders do not survive git, so keep them with placeholder files.
for dir in storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$APP/$dir"
    printf '*\n!.gitignore\n' > "$APP/$dir/.gitignore"
done
rm -f "$APP/.env" "$APP/database/database.sqlite"

echo "==> Hazır: $OUT ($(find "$OUT" -type f | wc -l) dosya, $(du -sh "$OUT" | cut -f1))"

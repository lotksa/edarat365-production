#!/bin/bash
# One-time setup script - configures .env, imports DB, runs migrations
# Args: $1 = DB_DATABASE, $2 = DB_USERNAME, $3 = DB_PASSWORD, $4 = APP_DOMAIN

set +e
DB_DATABASE="${1:-edarat_db}"
DB_USERNAME="${2:-edarat_app}"
DB_PASSWORD="${3}"
APP_DOMAIN="${4:-edarat365.lotksa.com}"
LARAVEL_APP="$HOME/laravel-app"
PUBLIC_HTML="$HOME/public_html"
REPO_DIR="$(pwd)"

echo "=== [1/8] Setting up .env file ==="
cp -f "$REPO_DIR/laravel-app/.env" "$LARAVEL_APP/.env"
# CRITICAL: Remove .env.production - Laravel 11 prefers it over .env when APP_ENV=production
rm -f "$LARAVEL_APP/.env.production" "$LARAVEL_APP/.env.production.example"

# Generate APP_KEY
APP_KEY="base64:$(openssl rand -base64 32)"

# Replace placeholders in .env
sed -i "s|APP_KEY=|APP_KEY=${APP_KEY}|g" "$LARAVEL_APP/.env"
sed -i "s|APP_URL=https://YOUR_DOMAIN.com|APP_URL=https://${APP_DOMAIN}|g" "$LARAVEL_APP/.env"
sed -i "s|FRONTEND_URL=https://YOUR_DOMAIN.com|FRONTEND_URL=https://${APP_DOMAIN}|g" "$LARAVEL_APP/.env"
sed -i "s|DB_DATABASE=CPANEL_USER_edarat365|DB_DATABASE=${DB_DATABASE}|g" "$LARAVEL_APP/.env"
sed -i "s|DB_USERNAME=CPANEL_USER_edarat|DB_USERNAME=${DB_USERNAME}|g" "$LARAVEL_APP/.env"
sed -i "s|DB_PASSWORD=YOUR_DB_PASSWORD|DB_PASSWORD=${DB_PASSWORD}|g" "$LARAVEL_APP/.env"
sed -i "s|MAIL_HOST=mail.YOUR_DOMAIN.com|MAIL_HOST=mail.${APP_DOMAIN}|g" "$LARAVEL_APP/.env"
sed -i "s|MAIL_USERNAME=noreply@YOUR_DOMAIN.com|MAIL_USERNAME=noreply@${APP_DOMAIN}|g" "$LARAVEL_APP/.env"
sed -i "s|MAIL_FROM_ADDRESS=\"noreply@YOUR_DOMAIN.com\"|MAIL_FROM_ADDRESS=\"noreply@${APP_DOMAIN}\"|g" "$LARAVEL_APP/.env"
sed -i "s|SESSION_DOMAIN=null|SESSION_DOMAIN=.${APP_DOMAIN}|g" "$LARAVEL_APP/.env"

chmod 600 "$LARAVEL_APP/.env"
echo "[OK] .env configured (APP_KEY generated, DB credentials set)"

echo "=== [2/8] Importing database SQL ==="
SQL_FILE="$REPO_DIR/database/edarat365.sql"
if [ -f "$SQL_FILE" ]; then
    # Strip BOM if present
    sed -i '1s/^\xEF\xBB\xBF//' "$SQL_FILE"
    mysql --binary-mode -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$SQL_FILE" 2>&1 | head -20
    if [ $? -eq 0 ]; then
        echo "[OK] Database imported"
    else
        echo "[WARN] SQL import had issues - will run migrations to set up DB"
    fi
else
    echo "[SKIP] SQL file not found at $SQL_FILE"
fi

echo "=== [3/8] Creating storage symlink ==="
cd "$LARAVEL_APP"
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
rm -rf "$PUBLIC_HTML/storage"
ln -sf "$LARAVEL_APP/storage/app/public" "$PUBLIC_HTML/storage"
echo "[OK] Storage symlinked"

echo "=== [4/8] Creating .htaccess for public_html (Vue SPA) ==="
cat > "$PUBLIC_HTML/.htaccess" << 'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Send /api/* to api/index.php (Laravel)
    RewriteCond %{REQUEST_URI} ^/api/
    RewriteRule ^api/(.*)$ api/index.php [L,QSA]

    # Vue SPA routing - everything else goes to index.html
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.html [L]
</IfModule>

# Security headers
<IfModule mod_headers.c>
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-Content-Type-Options "nosniff"
    Header set Referrer-Policy "no-referrer"
    Header set X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex"
</IfModule>

# Disable directory listing
Options -Indexes
HTACCESS
echo "[OK] public_html .htaccess written"

echo "=== [5/8] Creating api/.htaccess and api/index.php ==="
mkdir -p "$PUBLIC_HTML/api"

cat > "$PUBLIC_HTML/api/.htaccess" << 'APIHT'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
    Header set X-Robots-Tag "noindex, nofollow, noarchive, nosnippet"
    Header set Referrer-Policy "no-referrer"
</IfModule>

Options -Indexes
APIHT

cat > "$PUBLIC_HTML/api/index.php" << 'APIPHP'
<?php
define('LARAVEL_START', microtime(true));

// Fix REQUEST_URI for Laravel routing
// Laravel calculates path relative to SCRIPT_NAME, but we want it to see full URI
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

if (file_exists($maintenance = __DIR__.'/../../laravel-app/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../../laravel-app/vendor/autoload.php';

$app = require_once __DIR__.'/../../laravel-app/bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
APIPHP
echo "[OK] api entry created"

echo "=== [6/8] Optimizing Laravel ==="
cd "$LARAVEL_APP"
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "[OK] Laravel cached"

echo "=== [7/8] Running pending migrations and seed (if empty DB) ==="
cd "$LARAVEL_APP"
TABLES_COUNT=$(mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE'" 2>/dev/null)
echo "Existing tables: $TABLES_COUNT"
if [ "$TABLES_COUNT" -lt 5 ]; then
    echo "DB looks empty - running fresh migration with seed"
    php artisan migrate:fresh --force --seed --no-interaction || echo "[WARN] Migration error"
else
    php artisan migrate --force --no-interaction || echo "[WARN] Migration warning - DB may already be up to date"
fi
echo "[OK] Migrations done"

echo "=== [8/8] Final permissions ==="
chmod -R 755 "$LARAVEL_APP"
chmod -R 777 "$LARAVEL_APP/storage"
chmod -R 777 "$LARAVEL_APP/bootstrap/cache"
chmod 600 "$LARAVEL_APP/.env"
echo "[OK] Permissions set"

echo ""
echo "=================================="
echo "DEPLOYMENT COMPLETE!"
echo "URL: https://${APP_DOMAIN}"
echo "=================================="

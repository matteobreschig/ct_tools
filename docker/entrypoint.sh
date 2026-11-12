#!/bin/sh

# ========================
# Entrypoint Laravel + Apache
# ========================

echo "Waiting for database..."

TRIES=0
MAX_TRIES=30   # circa 1 minuto se sleep=2
SLEEP=2

until php -r "
try {
    new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
    );
} catch (Exception \$e) {
    exit(1);
}"
do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "Database not ready after $((MAX_TRIES * SLEEP)) seconds. Starting anyway..."
        break
    fi
    echo "Database not ready yet, retrying ($TRIES/$MAX_TRIES)..."
    sleep $SLEEP
done

echo "Running Laravel commands..."

# Esegui migrate + cleanup / cache
php artisan migrate --force || echo "Migration failed, will retry later"
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan filament:clear-cached-components
php artisan filament:optimize-clear
php artisan optimize
php artisan filament:optimize

php artisan horizon &
php artisan schedule:work &

echo "Starting Apache..."
exec apache2-foreground

#!/bin/sh

echo "Waiting for database..."

until php -r "
try {
    new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
    );
    echo 'Database ready';
} catch (Exception \$e) {
    exit(1);
}"
do
  sleep 2
done

echo "Running Laravel commands..."

php artisan migrate --force
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan filament:clear-cached-components
php artisan filament:optimize-clear
php artisan optimize
php artisan filament:optimize

echo "Starting Apache..."

exec apache2-foreground

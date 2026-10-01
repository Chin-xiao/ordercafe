#!/usr/bin/env bash
set -o errexit

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Clear and cache configurations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations (since your Aiven database is already configured!)
php artisan migrate --force

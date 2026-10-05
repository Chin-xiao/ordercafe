#!/usr/bin/env bash
echo "Running deployment scripts..."

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Running migrations..."
php artisan migrate --force

FROM richarvey/nginx-php-fpm:3.1.6

COPY . /var/www/html

ENV WEBROOT /var/www/html/public
ENV APP_ENV production
ENV REPO_LAYOUT true

RUN composer install --no-dev --optimize-autoloader

# Ensure scripts have execution permissions
RUN chmod +x /var/www/html/scripts/00-laravel-deploy.sh

RUN composer install --no-dev --optimize-autoloader
RUN php artisan config:clear
RUN php artisan route:clear

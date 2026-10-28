FROM php:8.3-apache
RUN apt-get update && apt-get install -y git unzip libssl-dev \
 && pecl install mongodb && docker-php-ext-enable mongodb \
 && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN printf '<Directory /var/www/html>\n AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/override.conf \
 && a2enconf override
CMD sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground
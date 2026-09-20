FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl default-mysql-client libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring simplexml \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/fpdp-entrypoint
COPY . /var/www/html

RUN chmod +x /usr/local/bin/fpdp-entrypoint \
    && mkdir -p /var/www/html/storage/cv /var/www/html/storage/media /var/www/html/storage/logs /var/www/html/storage/tmp \
    && chown -R www-data:www-data /var/www/html/storage

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["fpdp-entrypoint"]
CMD ["apache2-foreground"]

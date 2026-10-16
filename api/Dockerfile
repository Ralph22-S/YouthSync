FROM php:8.2-apache-bookworm

RUN set -eux; \
    for i in 1 2 3 4 5; do apt-get update && break || sleep 8; done; \
    apt-get install -y --no-install-recommends libonig-dev libzip-dev unzip \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/youthsync.conf \
    && a2enconf youthsync

COPY . /var/www/html
RUN sed -i 's|RewriteBase /YouthSync/api/public/|RewriteBase /|' /var/www/html/public/.htaccess \
    && mkdir -p /var/www/html/storage /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/logs

COPY docker-entrypoint.sh /usr/local/bin/youthsync-entrypoint.sh
RUN chmod +x /usr/local/bin/youthsync-entrypoint.sh

CMD ["youthsync-entrypoint.sh"]

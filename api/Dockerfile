FROM php:8.2-apache-bookworm

RUN set -eux; \
    for i in 1 2 3 4 5; do apt-get update && break || sleep 8; done; \
    apt-get install -y --no-install-recommends libonig-dev libzip-dev unzip \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/youthsync.conf \
    && a2enconf youthsync \
    && a2dismod -f mpm_event mpm_worker || true \
    && a2enmod mpm_prefork

COPY . /var/www/html
RUN sed -i 's|RewriteBase /YouthSync/api/public/|RewriteBase /|' /var/www/html/public/.htaccess \
    && mkdir -p /var/www/html/storage /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/logs

COPY docker-entrypoint.sh /usr/local/bin/youthsync-entrypoint.sh
RUN chmod +x /usr/local/bin/youthsync-entrypoint.sh

CMD ["youthsync-entrypoint.sh"]

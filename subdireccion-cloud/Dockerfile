# Sistema de Subdirección — imagen para producción (PHP 8.3 + Apache + PostgreSQL)
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

# Solo la carpeta public/ queda expuesta en la web; app/ y database/ quedan fuera.
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
 && printf '<IfModule mpm_prefork_module>\n  StartServers 2\n  MinSpareServers 1\n  MaxSpareServers 3\n  MaxRequestWorkers 12\n</IfModule>\nDirectoryIndex index.php\nLimitRequestBody 220000000\n' > /etc/apache2/conf-enabled/zz-subdireccion.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-subdireccion.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY app/ /var/www/app/
COPY database/ /var/www/database/
COPY public/ /var/www/public/

RUN chmod +x /usr/local/bin/entrypoint.sh && chown -R root:root /var/www && chmod -R a+rX /var/www

ENV PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=60s --timeout=10s --start-period=60s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/health.php")==="ok"?0:1);'
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

FROM php:8.4-apache-bookworm AS base

# PHP extensions. install-php-extensions pulls in the matching -dev packages itself.
ADD --chmod=755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apt-get update -y && \
    apt-get install -y --no-install-recommends curl zip unzip git mariadb-client && \
    apt-get clean && \
    install-php-extensions ldap intl zip pdo_mysql

RUN a2enmod rewrite && \
    a2enmod expires && \
    a2enmod headers && \
    a2enmod http2 && \
    sed -e '/<Directory \/var\/www\/>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' -i /etc/apache2/apache2.conf

# Composer 2 (same path the old image used, plus a normal `composer` on PATH)
COPY --from=composer:2 /usr/bin/composer /opt/composer/composer.phar
RUN ln -s /opt/composer/composer.phar /usr/local/bin/composer

FROM base AS develop

COPY .docker/environment.conf /etc/apache2/conf-enabled/
COPY .docker/logformat.conf /etc/apache2/conf-enabled/

RUN install-php-extensions xdebug && \
    echo "TLS_REQCERT never" >> /etc/ldap/ldap.conf

FROM base AS production

WORKDIR /var/www

# Dependencies first so the layer is reused when only application code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN mkdir -p logs tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions && \
    chown -R www-data:www-data logs tmp && \
    chmod -R ug+rwX logs tmp && \
    cp ./.docker/environment.conf /etc/apache2/conf-enabled/ && \
    cp ./.docker/logformat.conf /etc/apache2/conf-enabled/ && \
    cp ./config/app.default.php ./config/app.php && \
    rmdir /var/www/html && ln -s /var/www/webroot /var/www/html && \
    cp ./.docker/prod/php-production.ini /usr/local/etc/php/php.ini && \
    composer dump-autoload --no-dev --optimize --no-scripts --no-interaction
EXPOSE 80

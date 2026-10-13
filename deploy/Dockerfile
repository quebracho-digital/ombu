# Ombú in production: Drupal on PHP 8.3 + Apache, the code mounted from the
# repos next to this one (compose.yaml). Same PHP as DDEV, plus what
# production needs and DDEV brings: gmp (wallet signatures), apcu, opcache.
FROM php:8.3-apache

COPY --from=mlocati/php-extension-installer:2.7.38 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions gd pdo_mysql opcache zip gmp intl bcmath apcu \
 && apt-get update && apt-get install -y --no-install-recommends git unzip mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/web#' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/web>\n  AllowOverride All\n  Require all granted\n</Directory>\n# The model sellers answer in minutes: Apache must wait for them.\nTimeout 900\n' > /etc/apache2/conf-enabled/ombu.conf \
 && printf 'memory_limit=512M\nmax_execution_time=900\nupload_max_filesize=32M\npost_max_size=32M\nopcache.memory_consumption=192\nopcache.max_accelerated_files=20000\n' > /usr/local/etc/php/conf.d/ombu.ini

ENV PATH=/var/www/html/vendor/bin:$PATH
WORKDIR /var/www/html

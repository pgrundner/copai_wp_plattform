# CoPAI Platform
# https://copai.community
#
# Developed by Murbit GmbH as part of the Erasmus+ project:
#
# Community of Practice AI
# Project No.: KA210-VET-4603C73C
#
# Funded by the European Union. Views and opinions expressed are however
# those of the author(s) only and do not necessarily reflect those of the
# European Union or the European Education and Culture Executive Agency (EACEA).
# Neither the European Union nor EACEA can be held responsible for them.
#
# Copyright (c) 2025 Murbit GmbH
#
# Licensed under the MIT License.
# See LICENSE file for details.

# PHP
FROM php:8.2-fpm

ARG MOODLE_GIT_BRANCH=MOODLE_403_STABLE


RUN apt-get update && apt-get install -y \
    git ca-certificates curl \
    unzip libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libxml2-dev locales \
    && rm -rf /var/lib/apt/lists/*

# Trust the Let's Encrypt Staging Root CA so back-channel OAuth2 calls succeed
# when the stack uses TRAEFIK_TLS_RESOLVER=letsencrypt-staging in dev/firewall
# setups. Harmless for production (just an additional trust anchor).
RUN curl -fsSL https://letsencrypt.org/certs/staging/letsencrypt-stg-root-x1.pem \
        -o /usr/local/share/ca-certificates/letsencrypt-staging-root-x1.crt \
    && update-ca-certificates

# Spracheinstellung
RUN echo "de_DE.UTF-8 UTF-8" >> /etc/locale.gen \
    && echo "en_US.UTF-8 UTF-8" >> /etc/locale.gen \
    && locale-gen

# Graphic Lib
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    gd \
    intl \
    zip \
    soap \
    mysqli \
    pdo \
    pdo_mysql \
    opcache \
    exif

# Moodle Settings
RUN echo "file_uploads = On\n" \
         "memory_limit = 512M\n" \
         "upload_max_filesize = 500M\n" \
         "post_max_size = 500M\n" \
         "max_execution_time = 600\n" \
         "max_input_vars = 5000\n" \
         "date.timezone = Europe/Berlin\n" \
         > /usr/local/etc/php/conf.d/moodle-custom.ini

# Skript in den Container kopieren & ausführbar machen
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh


# clone Moodle von Git ----------------
WORKDIR /var/www/html

RUN rm -rf /var/www/html/*

RUN git clone -b ${MOODLE_GIT_BRANCH} https://github.com/moodle/moodle.git .

#---------------------------------------------------------

# Berechtigungen setzen (ganz am Ende, einmal für alles)
RUN chown -R www-data:www-data /var/www/html

# ENTRYPOINT setzen
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

# CMD
CMD ["php-fpm"]
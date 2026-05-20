ARG WP_VERSION=6.9
ARG WP_PHP_VERSION=8.2

FROM wordpress:${WP_VERSION}-php${WP_PHP_VERSION}-fpm

ARG BUDDYPRESS_VERSION
ARG JITSI_SPARRING_REF
ARG MEETING_REGISTRATION_REF

RUN apt-get update && apt-get install -y --no-install-recommends \
        git less mariadb-client unzip curl \
        ca-certificates rsync \
    && rm -rf /var/lib/apt/lists/*

# wp-cli
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Install BuddyPress + the two custom plugins INTO the image source dir.
# /usr/src/wordpress is what the upstream entrypoint copies into /var/www/html.
WORKDIR /usr/src/wordpress/wp-content/plugins

RUN curl -fsSL "https://downloads.wordpress.org/plugin/buddypress.${BUDDYPRESS_VERSION}.zip" \
        -o /tmp/bp.zip \
    && unzip -q /tmp/bp.zip -d . \
    && rm /tmp/bp.zip

RUN git clone --depth=1 --branch="${JITSI_SPARRING_REF}" \
        https://github.com/pgrundner/copai-bp-jitsi-sparring.git \
    && rm -rf copai-bp-jitsi-sparring/.git

RUN git clone --depth=1 --branch="${MEETING_REGISTRATION_REF}" \
        https://github.com/pgrundner/copai-meeting-registration.git \
    && rm -rf copai-meeting-registration/.git

# Stack-local plugins (e.g. the Meetup block plugin)
COPY plugins/ /usr/src/wordpress/wp-content/plugins/

# mu-plugins (SMTP, FS-direct)
COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/

# Custom entrypoint
COPY docker-entrypoint.sh /usr/local/bin/copai-entrypoint.sh
RUN chmod +x /usr/local/bin/copai-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/copai-entrypoint.sh"]
CMD ["php-fpm"]

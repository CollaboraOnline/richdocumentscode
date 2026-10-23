# OSS Scanner build for richdocumentscode (https://github.com/CollaboraOnline/richdocumentscode).
#
# /src                   the richdocumentscode checkout
# /var/www/html          a Nextcloud server, SQLite database, served by Apache on http://localhost/
# /var/www/html/custom_apps/richdocumentscode
#                        this app, copied from /src as build-nextcloud-app.sh lays it out, with the
#                        released Collabora Online AppImage in collabora/
#
# rdc-start starts Apache. rdc-install copies /src into the app directory again after a change.
FROM php:8.4-apache-trixie

ENV DEBIAN_FRONTEND=noninteractive

# The PHP extensions Nextcloud needs, and fontconfig for the AppImage.
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl bzip2 sudo procps psmisc git \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev libgmp-dev \
      fontconfig fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip intl gmp bcmath exif pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers env dir mime \
    && echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/nextcloud.ini

RUN curl -fsSL https://download.nextcloud.com/server/releases/latest.tar.bz2 \
      | tar -xj --strip-components=1 -C /var/www/html \
    && mkdir -p /var/www/html/custom_apps \
    && chown -R www-data:www-data /var/www/html

RUN printf '%s\n' '<Directory /var/www/html>' '  AllowOverride All' '</Directory>' \
      > /etc/apache2/conf-enabled/nextcloud.conf

# The released AppImage, as the app tarball on the Nextcloud app store bundles it.
RUN mkdir -p /opt/appimage \
    && curl -fsSL -o /opt/appimage/Collabora_Online.AppImage \
         https://www.collaboraoffice.com/downloads/Collabora-Office-AppImage-Release/collabora-online-release-LATEST.AppImage \
    && chmod a+x /opt/appimage/Collabora_Online.AppImage

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

RUN printf '%s\n' \
      '#!/bin/sh' \
      'set -e' \
      'app=/var/www/html/custom_apps/richdocumentscode' \
      'mkdir -p $app/collabora' \
      'cp -a /src/appinfo /src/l10n /src/lib /src/templates /src/img /src/images $app/' \
      'cp -a /src/proxy.php $app/proxy.php' \
      'ln -sf /opt/appimage/Collabora_Online.AppImage $app/collabora/Collabora_Online.AppImage' \
      'chown -R www-data:www-data $app' \
      > /usr/local/bin/rdc-install \
    && printf '%s\n' \
      '#!/bin/sh' \
      'apache2ctl start' \
      'for i in $(seq 30); do curl -fs -o /dev/null http://localhost/status.php && exit 0; sleep 1; done' \
      'echo "Apache did not answer" >&2; exit 1' \
      > /usr/local/bin/rdc-start \
    && chmod a+x /usr/local/bin/rdc-install /usr/local/bin/rdc-start

# Nextcloud with two users, admin and alice (passwords admin-password and alice-password), the
# Nextcloud Office app from the app store, and this app enabled as its office server.
RUN rdc-install \
    && cd /var/www/html \
    && sudo -u www-data php occ maintenance:install --database sqlite \
         --admin-user admin --admin-pass admin-password \
    && sudo -u www-data php occ config:system:set trusted_domains 0 --value=localhost \
    && sudo -u www-data php occ config:system:set trusted_domains 1 --value=127.0.0.1 \
    && OC_PASS=alice-password sudo -E -u www-data php occ user:add --password-from-env alice \
    && sudo -u www-data php occ app:install richdocuments \
    && sudo -u www-data php occ app:enable richdocumentscode \
    && sudo -u www-data php occ config:app:set richdocuments wopi_url \
         --value='http://localhost/custom_apps/richdocumentscode/proxy.php?req='

RUN find /src -name '*.php' -not -path '*/l10n/*' -print0 | xargs -0 -n1 php -l

# Start the server once, so the image is known to work, but do not fail the build on it.
RUN rdc-start \
    && cd /var/www/html \
    && (sudo -u www-data php occ richdocuments:activate-config \
        && for i in $(seq 300); do \
             s=$(curl -fs 'http://localhost/custom_apps/richdocumentscode/proxy.php?status'); \
             echo "$s" | grep -q '"OK"' && break; sleep 1; done \
        && echo "status: $s" \
        && curl -fs 'http://localhost/custom_apps/richdocumentscode/proxy.php?req=/hosting/discovery' \
             | grep -q urlsrc \
        && echo "discovery answered" \
        || echo "WARNING: the built-in server did not answer") \
    ; killall -q coolwsd apache2 || true

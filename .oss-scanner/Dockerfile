# OSS Scanner build for richdocumentscode (https://github.com/CollaboraOnline/richdocumentscode).
#
# /src                   the richdocumentscode checkout
# /var/www/html          a Nextcloud server, SQLite database, served by Apache on http://localhost/
# /var/lib/nextcloud/data
#                        its data directory, outside the web root
# /var/www/html/custom_apps/richdocumentscode
#                        this app, copied from /src as build-nextcloud-app.sh lays it out, with the
#                        released Collabora Online AppImage in collabora/
#
# rdc-start starts Apache. rdc-install copies /src into the app directory again after a change.
# nextcloud-reset stops the office server and puts the data, config.php and the app's settings back
# as they were after the build.
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
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'memory_limit=1G' > "$PHP_INI_DIR/conf.d/nextcloud.ini"

RUN curl -fsSL https://download.nextcloud.com/server/releases/latest.tar.bz2 \
      | tar -xj --strip-components=1 -C /var/www/html \
    && mkdir -p /var/www/html/custom_apps \
    && chown -R www-data:www-data /var/www/html \
    && install -d -o www-data -g www-data /var/lib/nextcloud/data

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
      'for i in $(seq 30); do curl -fs -o /dev/null http://localhost/status.php && break; sleep 1; done' \
      '# A page view writes the settings the office server starts with' \
      'curl -fs -o /dev/null http://localhost/index.php/login && exit 0' \
      'echo "Apache did not answer" >&2; exit 1' \
      > /usr/local/bin/rdc-start \
    && chmod a+x /usr/local/bin/rdc-install /usr/local/bin/rdc-start

# Nextcloud with two users, admin and alice (passwords admin-password and alice-password), the
# Nextcloud Office app from the app store, and this app enabled as its office server. The app store
# is switched off once Nextcloud Office is in.
RUN rdc-install \
    && cd /var/www/html \
    && sudo -u www-data php occ maintenance:install --database sqlite \
         --data-dir /var/lib/nextcloud/data --admin-user admin --admin-pass admin-password \
    && sudo -u www-data php occ config:system:set trusted_domains 0 --value=localhost \
    && sudo -u www-data php occ config:system:set trusted_domains 1 --value=127.0.0.1 \
    && OC_PASS=alice-password sudo -E -u www-data php occ user:add --password-from-env alice \
    && sudo -u www-data php occ app:install richdocuments \
    && sudo -u www-data php occ app:enable richdocumentscode \
    && sudo -u www-data php occ config:app:set richdocuments wopi_url \
         --value='http://localhost/custom_apps/richdocumentscode/proxy.php?req=' \
    && sudo -u www-data php occ config:system:set appstoreenabled --type=boolean --value=false

RUN find /src -name '*.php' -not -path '*/l10n/*' -print0 | xargs -0 -n1 php -l

# Start the server once, so the image is known to work, but do not fail the build on it. Then keep
# copies of the data and config.php for nextcloud-reset. The app's settings directory is
# /tmp/richdocumentscode-<uid of www-data>, and the next page view writes it again.
RUN test "$(id -u www-data)" = 33 \
    && rdc-start \
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
    ; killall -q -w coolwsd; apache2ctl stop; sleep 2 \
    ; rm -rf /tmp/richdocumentscode-33 \
    && cp -a /var/lib/nextcloud/data /var/lib/nextcloud/data.pristine \
    && cp -a /var/www/html/config/config.php /var/lib/nextcloud/config.php.pristine \
    && printf '%s\n' \
      '#!/bin/sh' \
      'set -e' \
      'killall -q -w coolwsd || true' \
      'rm -rf /tmp/richdocumentscode-33 /var/lib/nextcloud/data' \
      'cp -a /var/lib/nextcloud/data.pristine /var/lib/nextcloud/data' \
      'cp -a /var/lib/nextcloud/config.php.pristine /var/www/html/config/config.php' \
      'curl -fs -o /dev/null http://localhost/index.php/login || true' \
      > /usr/local/bin/nextcloud-reset \
    && chmod a+x /usr/local/bin/nextcloud-reset

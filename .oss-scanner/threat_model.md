# Threat model

## What this project does and where untrusted input enters

richdocumentscode is a Nextcloud app that bundles a Collabora Online server as an AppImage.
proxy.php starts that server on the loopback interface and forwards HTTP requests to it. The
classes in lib/ work out the server's settings from Nextcloud's configuration.

Every HTTP request to proxy.php is untrusted. Nextcloud's configuration and its administrators are
trusted.

## Components that matter most and least

- Matters most: proxy.php and lib/.
- Also in scope: the server in the AppImage, as reached through proxy.php. Its source is Collabora
  Online's, not in this repository, so a report there should say so and need not carry a patch.
- Lower: templates/ and lib/Settings, which only an administrator sees.
- Out of scope: l10n/, which is translations.

## How to exercise it

- rdc-start starts Apache. Nextcloud answers on http://localhost/ with the users admin
  (admin-password) and alice (alice-password).
- The app is at /var/www/html/custom_apps/richdocumentscode. rdc-install copies /src there again.
- proxy.php?status starts the bundled server, which takes a minute or two the first time.

## How we rate severity

We rate severity as Collabora Online's SECURITY.md does. Anything that needs a Nextcloud
administrator or a shell on the server is out of scope, as is denial of service on its own.

## How reports and patches should look

A reproducer as a sequence of HTTP requests is the most useful part. A patch should be the
smallest change that fixes the problem, in the style of the surrounding code.

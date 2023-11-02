# richdocumentscode
**Collabora Online - Built-in CODE Server (for Nextcloud)**

Created by Collabora Productivity Ltd.

## Features

* This app provides a built-in server with all of the document editing features of Collabora Online.
* Easy to install, for personal use or for small teams.
* A bit slower than a standalone server and without the advanced scalability features.

Collabora Online is a powerful LibreOffice-based online office suite with collaborative editing, which supports all major documents, spreadsheet and presentation file formats and works together with all modern browsers.

## Implementation

The included CODE Server app is provided as an AppImage. When running under Docker/LXC the AppImage
will be unpacked and run, else the host is required to be able to run AppImages which adds a
requirement on FUSE.

Notes:
* If the app is built with an ARM64 AppImage, it will be named `richdocumentscode_arm64`.
* On slower systems, the first time the CODE Server is started there may be a noticeable delay (also applies on subsequent runs if `/tmp` whenever cleared)

## System requirements
- Linux x86-64 or ARM64 (aarch64) platform
- 2 CPU cores
- 1 GB RAM + 100 MB RAM / user
- 100 kbit/s network bandwidth / user
- 350 MB space on disk
- Nextcloud 19 with [Collabora Online app](https://apps.nextcloud.com/apps/richdocuments) 3.7.0 or higher
- glibc ([AppImage does not support musl libc](https://github.com/AppImage/AppImageKit/issues/1015))
- Fontconfig (libfontconfig.so.1 - required by Collabora_Online.AppImage)
### Optional
- Kernel supporting the FUSE (Filesystem in Userspace)
- FUSE 2 (libfuse.so.2)

**Note:** If FUSE support is not present, the AppImage will be started with `--appimage-extract-and-run` parameter.

## Installation

The download is rather big (~300 MB) so it is possible you will experience a time-out if using the web interface to install from the AppStore. Using the OCC command line tool is suggested for reliability:
```
sudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode
```
Where `wwwrun` is the user of your web server. This is ```www-data``` on Debian, Ubuntu and derivatives, `wwwrun` on SUSE based distributions, `apache` on Red Hat/Fedora and `http` on Arch linux and derivatives.
Updates can be done like this:
```
sudo -u wwwrun php -d memory_limit=512M ./occ app:update --all
```
Of course, alternatively you could increase memory usage and PHP time-outs by default, see the [Nextcloud documentation.](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html?highlight=php%20timeout#configuring-your-web-server)

## Troubleshooting

* Make sure you have enough disk space
* Check the logs:
  - `nextcloud.log`
  - container logs (if applicable)
  - `/tmp/coolwsd.*/coolwsd.log` (on your host or in the container's `/tmp` if applicable)

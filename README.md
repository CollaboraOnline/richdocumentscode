# richdocumentscode

**Collabora Online - Built-in CODE Server (for Nextcloud)**

Created by Collabora Productivity Ltd.

## Features

- This app provides a built-in server with all of the document editing features of Collabora Online.
- Easy to install, for personal use or for small teams.
- A bit slower than a standalone server and without the advanced scalability features.

Collabora Online is a powerful LibreOffice-based online office suite with collaborative editing, which supports all major documents, spreadsheet and presentation file formats and works together with all modern browsers.

## Implementation

The included CODE Server app is provided as an AppImage. When running under Docker/LXC the AppImage
will be unpacked and run, else the host is required to be able to run AppImages which adds a
requirement on FUSE.

Notes:

- On slower systems, the first time the CODE Server is started there may be a noticeable delay (also applies on subsequent runs if `/tmp` whenever cleared)

## System requirements

- Linux x86-64 or ARM64 (aarch64) platform
- 2 CPU cores
- 1 GB RAM + 100 MB RAM / user
- 100 kbit/s network bandwidth / user
- 300 MB space on disk (800 MB in `/tmp` if not using FUSE)
- Nextcloud 19 with the [Nextcloud Office app](https://apps.nextcloud.com/apps/richdocuments) 3.7.0 or higher
- A glibc based distribution/container ([AppImage does not support musl libc](https://github.com/AppImage/AppImageKit/issues/1015))
- Fontconfig (libfontconfig.so.1 - required by Collabora_Online.AppImage)

### Optional

- Kernel supporting the FUSE (Filesystem in Userspace)
- FUSE 2 (libfuse.so.2)

**Note:** If FUSE support is not present, a warning (`dlopen(): error loading libfuse.so.2`) will be generated, but then the AppImage will be started with the `--appimage-extract-and-run` parameter automatically as a fallback.

## Installation

The download is rather big (~300 MB) so it is possible you will experience a time-out if using the web interface to install from the AppStore. Using the OCC command line tool is suggested for reliability:

```
sudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode
```

Where `wwwrun` is the user of your web server. This is `www-data` on Debian, Ubuntu and derivatives, `wwwrun` on SUSE based distributions, `apache` on Red Hat/Fedora and `http` on Arch linux and derivatives.

`richdocumentscode` is only for architecture x86_64. If you want to install the app for ARM64, install `richdocumentscode_arm64`:

```
sudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode_arm64
```

Updates can be done like this:

```
sudo -u wwwrun php -d memory_limit=512M ./occ app:update --all
```

Of course, alternatively you could increase memory usage and PHP time-outs by default, see the [Nextcloud documentation.](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html?highlight=php%20timeout#configuring-your-web-server)

## Troubleshooting

- Make sure you have enough disk space
- Check the logs:
  - `nextcloud.log`
  - container logs (if applicable)
  - `/tmp/coolwsd.*/coolwsd.log` (on your host or in the container's `/tmp` if applicable)

## Testing with a local Collabora Online build

These instructions explain how to test this app with a local development build of Collabora Online. This is useful for testing patches and new features in Collabora Online before they are released.

### Prerequisites

- A local Nextcloud development instance running on `http`. Using `http` simplifies the setup by avoiding the need for SSL certificates.
- A local clone of the [Collabora Online repository](https://github.com/CollaboraOnline/online).

### 1. Build Collabora Online

First, you need to build Collabora Online from source.

1. Navigate to your local Collabora Online repository:

   ```bash
   cd /path/to/your/collabora/online/repo
   ```

2. Build Collabora Online. Using the `-j` flag with the number of your CPU cores will speed up the process significantly.

   ```bash
   make -j$(nproc)
   ```

### 2. Run local Collabora Online server

Once the build is complete, run the following command from the root of your Collabora Online repository to start the development server:

```bash
./coolwsd --o:sys_template_path="$PWD/systemplate" \
          --o:child_root_path="$PWD/master/jails" \
          --o:cache_files.path="$PWD/cache" \
          --o:admin_console.username=admin \
          --o:admin_console.password=admin \
          --o:ssl.enable=false \
          --port=9983 \
          --o:net.proxy_prefix=true
```

This command starts the Collabora Online server (`coolwsd`) without SSL, listening on port 9983.

### 3. Configure `richdocumentscode` app

To make the `richdocumentscode` app work with your local Collabora Online server, you need to make a few modifications.

1. **Clone the app:** If you haven't already, clone the `richdocumentscode` repository into your Nextcloud `apps` directory.

2. **Modify `proxy.php`:** You need to edit `proxy.php` in the `richdocumentscode` app directory.

   Open `proxy.php` and apply the following changes:

   - **Update Server IP Address:** If you are running [nextcloud-docker-dev](https://github.com/juliusknorr/nextcloud-docker-dev), you must replace the `localhost` with your host machine's local network IP address. Find all occurrences of `localhost` and replace them with your IP.

   - **Bypass Server Checks:** To connect to your local `coolwsd` instance, you need to bypass the standard server checks.

     - In the `isCoolwsdRunning()` function, add `return true;` at the beginning.

       ```php
       function isCoolwsdRunning()
       {
           return true;
           // ... original code
       }
       ```

     - In the `checkCoolwsdSetup()` function, add `return '';` at the beginning.

       ```php
       function checkCoolwsdSetup()
       {
           return '';
           // ... original code
       }
       ```

   > **Note:** These changes are for development purposes only and should not be used in a production environment.

3. **Configure Nextcloud Office:** In your Nextcloud instance, go to **Settings > Office**.
   - Select **Use the built-in CODE**.

### Important Considerations

- **Bypassing Localhost Restrictions in Collabora Online**: For some testing scenarios, you might need to modify the Collabora Online source code to remove restrictions on local connections. Search for and comment out any lines containing `socket->isLocal()` in the Collabora Online source code if you encounter connection issues.

# richdocumentscode
Built-in CODE Server app

Created by Collabora Productivity Ltd.

The included CODE Server app is provided as an AppImage. When running under Docker/LXC the AppImage
will be unpacked and run, else the host is required to be able to run AppImages which adds a
requirement on FUSE.

## System requirements
- Linux x86-64 platform
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

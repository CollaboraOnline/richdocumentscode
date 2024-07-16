OC.L10N.register(
    "richdocumentscode",
    {
    "Built-in CODE Server" : "Sirvidor CODE integráu",
    "Collabora Online - Built-in CODE Server" : "Collabora Online - Sirvidor CODE integráu",
    "Built-in Collabora Online Development Edition (CODE) server for local testing and non-production use" : "Sirvidor integráu de la edición de desendolcu de Collabora Online (CODE) pa pruebes en llocal y pa usar nun entornu que nun ye de producción",
    "**This app has to be installed and used together with the [Nextcloud Office](https://apps.nextcloud.com/apps/richdocuments) integration app.**\n\nCollabora Online is a powerful LibreOffice-based online office suite with collaborative editing, which supports all major documents, spreadsheet and presentation file formats and works together with all modern browsers.\n\n* This app provides a built-in server with all of the document editing features of Collabora Online.\n* Easy to install, for personal use or for small teams.\n* A bit slower than a standalone server and without the advanced scalability features.\n\n**System Requirements:**\n- Linux running on ```x86-64``` or ```arm64/aarch64```\n- A ```glibc``` based distribution/container (```musl libc``` is **not** supported)\n- Fontconfig (```libfontconfig.so.1```)\n- Additional requirements can be found in [here](https://github.com/CollaboraOnline/richdocumentscode#richdocumentscode)\n\n*The download is rather big so it is possible you will experience a time-out when installing via the web interface.* You can use the OCC command install the built-in server from the command-line instead:\n\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode\n```\nWhere `wwwrun` is the user of your web server. This is ```www-data``` on Debian, Ubuntu and derivatives, `wwwrun` on SUSE based distributions, `apache` on Red Hat/Fedora and `http` on Arch linux and derivatives.\n\nUpdates can be done like this:\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:update --all\n```\n\nOf course, alternatively you could increase memory usage and PHP time-outs by default, see the [Nextcloud documentation.](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html?highlight=php%20timeout#configuring-your-web-server)\n\nAdditional troubleshooting tips can be found [here](https://github.com/CollaboraOnline/richdocumentscode?tab=readme-ov-file#troubleshooting)." : "**Tienes d'instalar y usar esta aplicación xunto cola aplicación d'integración [Nextcloud Office](https://apps.nextcloud.com/apps/richdocuments).**\n\nCollabora Online ye una suite potente basada en LibreOffice con edición collaborativa que ye compatible cola mayoría de documentos, fueyes de cálculu y presentación y funciona en tolos restoladores modernos.\n\n* Esta aplicación forne un sirvidor integráu con toles funciones d'edición integraes de Collabora Online.\n* Ye fácil d'instalar pa usu personal o equipos pequeños.\n* Un poco más lentu qu'un sirvidor independiente y ensin les funciones avanzaes d'escalabilidá.\n\n**Requirimientos del sistema:**\n- Linux que s'execute en ```x86-64``` o ```arm64/aarch64```\n- Una distribución/contenedor basáu en ```glibc``` (```musl libc``` **nun** ye compatible)\n- Fontconfig (```libfontconfig.so.1```)\n- Pues atopar más requirimientos [equí](https://github.com/CollaboraOnline/richdocumentscode#richdocumentscode)\n\n*La descarga ye grande polo que ye posible que sufras escoses de tiempu al facer la instalación pela interfaz web.* Pues usar el comandu OCC pa insalar el sirvidor integráu dende la llina de comandos:\n\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode\n```\nOnde `wwwrun` ye l'usuariu del sirvidor web. Esti usuariu ye ```www-data``` en distribuciones basaes en Debian, `wwwrun` en distribuciones basaes en SUSE, `apache` en Red Hat/Fedora y `http` en distribuciones basaes n'Arch Linux.\n\nLos anovamientos puen aplicase asina:\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:update --all\n```\n\nPer otra parte, pues aumentar l'usu de la memoria d'usu y les tiempos d'espera de PHP por defeutu, mira la [documentación de Nextcloud.](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html?highlight=php%20timeout#configuring-your-web-server)\n\nPues atopar más conseyos pa la igua de problemes [equí](https://github.com/CollaboraOnline/richdocumentscode?tab=readme-ov-file#troubleshooting).",
    "Your system is ARM64, but you have installed the x86_64 version of the app. Please remove this and" : "El sistema ye ARM64 mas tienes instalada la versión x86_64 de l'aplicación. Quítala ya",
    "install the correct version from the Nextcloud App Store." : "instala la versión correuta de la tienda d'aplicaciones de Nextcloud.",
    "Your system is x86_64, but you have installed the ARM64 version of the app. Please remove this and" : "El sistema ye x86_64 mas tienes instalada la versión ARM64 de l'aplicación. Quítala y",
    "You have the Collabora Online app enabled. For further information and configuration, please check:" : "Tienes l'aplicación Collabora Online activada. Pa consiguir más información y configuración, comprueba:",
    "Administration settings > Nextcloud Office" : "Configuración de l'alministración > Nextcloud Office",
    "The Built-in CODE Server is designed to work with the Nextcloud Office app." : "El sirvidor CODE integráu ta diseñáu pa funcionar cola aplicación Nextcloud Office.",
    "Install it from the Nextcloud App Store." : "instálala dende la tienda d'aplicaciones de Nextcloud.",
    "An error occurred while trying to check your Collabora Online app installation. You may report this error\n                with the tag: <em>richdocuments-EnabledCheckFailed</em>" : "Prodúxose un error mentanto se comprobaba la instalación de l'aplicación Collabora Online.\n                Pues informar d'esti error cola etiqueta: <em>richdocuments-EnabledCheckFailed</em>"
},
"nplurals=2; plural=(n != 1);");

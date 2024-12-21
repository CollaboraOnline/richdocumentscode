OC.L10N.register(
    "richdocumentscode",
    {
    "Built-in CODE Server" : "قاچىلانغان CODE مۇلازىمېتىرى",
    "Collabora Online - Built-in CODE Server" : "Collabora Online - ئىچىگە ئورۇنلاشتۇرۇلغان CODE مۇلازىمىتىرى",
    "Built-in Collabora Online Development Edition (CODE) server for local testing and non-production use" : "يەرلىك سىناق ۋە ئىشلەپچىقىرىشتىن باشقا ئىشلىتىش ئۈچۈن قاچىلانغان كوللابورا تور تەرەققىيات نەشرى (CODE) مۇلازىمېتىرى",
    "**This app has to be installed and used together with the [Nextcloud Office](https://apps.nextcloud.com/apps/richdocuments) integration app.**\n\nCollabora Online is a powerful LibreOffice-based online office suite with collaborative editing, which supports all major documents, spreadsheet and presentation file formats and works together with all modern browsers.\n\n* This app provides a built-in server with all of the document editing features of Collabora Online.\n* Easy to install, for personal use or for small teams.\n* A bit slower than a standalone server and without the advanced scalability features.\n\n**System Requirements:**\n- Linux running on ```x86-64``` or ```arm64/aarch64```\n- A ```glibc``` based distribution/container (```musl libc``` is **not** supported)\n- Fontconfig (```libfontconfig.so.1```)\n- Additional requirements can be found in [here](https://github.com/CollaboraOnline/richdocumentscode#richdocumentscode)\n\n*The download is rather big so it is possible you will experience a time-out when installing via the web interface.* You can use the OCC command install the built-in server from the command-line instead:\n\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:install richdocumentscode\n```\nWhere `wwwrun` is the user of your web server. This is ```www-data``` on Debian, Ubuntu and derivatives, `wwwrun` on SUSE based distributions, `apache` on Red Hat/Fedora and `http` on Arch linux and derivatives.\n\nUpdates can be done like this:\n```\nsudo -u wwwrun php -d memory_limit=512M ./occ app:update --all\n```\n\nOf course, alternatively you could increase memory usage and PHP time-outs by default, see the [Nextcloud documentation.](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html?highlight=php%20timeout#configuring-your-web-server)\n\nAdditional troubleshooting tips can be found [here](https://github.com/CollaboraOnline/richdocumentscode?tab=readme-ov-file#troubleshooting)." : "** بۇ ئەپنى [Nextcloud Office] (https://apps.nextcloud.com/apps/richdocuments) بىرلەشتۈرۈش دېتالى بىلەن قاچىلاش ۋە ئىشلىتىش كېرەك. **\n\nCollabora Online بىرلىشىپ تەھرىرلەيدىغان كۈچلۈك LibreOffice نى ئاساس قىلغان تور ئىشخانا يۈرۈشلۈك دېتالى بولۇپ ، ئۇ بارلىق ئاساسلىق ھۆججەتلەر ، ئېلېكترونلۇق جەدۋەل ۋە تونۇشتۇرۇش ھۆججەت فورماتىنى قوللايدۇ ھەمدە بارلىق زامانىۋى توركۆرگۈچلەر بىلەن ھەمكارلىشىدۇ.\n\n* بۇ ئەپ ئىچىگە Collabora Online نىڭ بارلىق ھۆججەت تەھرىرلەش ئىقتىدارلىرى قاچىلانغان مۇلازىمېتىر بىلەن تەمىنلەيدۇ.\n* قاچىلاش ئاسان ، شەخسىي ئىشلىتىش ياكى كىچىك گۇرۇپپىلار ئۈچۈن.\n* مۇستەقىل مۇلازىمېتىرغا قارىغاندا سەل ئاستا ، ئىلغار كېڭەيتىش ئىقتىدارى يوق.\n\n** سىستېما تەلىپى: **\n- Linux \"x86-64\" ياكى \"arm64 / aarch64\" دا ئىجرا بولۇۋاتىدۇ\n- `` glibc`` ئاساسىدىكى تارقىتىش / قاچا (`` musl libc``` ** ** قوللىمايدۇ)\n- Fontconfig (`` libfontconfig.so.1``)\n- قوشۇمچە تەلەپلەرنى [بۇ يەردىن] تاپقىلى بولىدۇ (https://github.com/CollaboraOnline/richdocumentscode#richdocumentscode)\n\n* چۈشۈرۈش بىر قەدەر چوڭ ، شۇڭا تور كۆرۈنمە يۈزى ئارقىلىق قاچىلىغاندا ۋاقىتنى باشتىن كەچۈرۈشىڭىز مۇمكىن. * OCC بۇيرۇقنى قاچىلانغان مۇلازىمېتىرنى بۇيرۇق قۇرىدىن ئورنىتىپ ئىشلەتسىڭىز بولىدۇ:\n\n`` `\nsudo -u wwwrun php -d memory_limit = 512M ./occ دېتالى: richdocumentscode نى قاچىلاڭ\n`` `\nقەيەردە «wwwrun» تور مۇلازىمېتىرىڭىزنىڭ ئىشلەتكۈچى. بۇ دېبىئان ، ئۇبۇنتۇ ۋە تۇغۇندى مەھسۇلاتلاردىكى «www-data» ، SUSE نى ئاساس قىلغان تارقىتىشتىكى «wwwrun» ، Red Hat / Fedora دىكى apache ۋە Arch linux ۋە تۇغۇندى مەھسۇلاتلار.\n\nيېڭىلاشنى مۇنداق قىلىشقا بولىدۇ:\n`` `\nsudo -u wwwrun php -d memory_limit = 512M ./occ دېتالى: يېڭىلاش - بارلىق\n`` `\n\nئەلۋەتتە ، سىز كۆڭۈلدىكى ئەھۋالدا ئىچكى ساقلىغۇچ ۋە PHP ۋاقتىنى ئۇزارتالايسىز ، [Nextcloud ھۆججىتىنى كۆرۈڭ]. %20timeout # config-your-web-server)\n\nقوشۇمچە مەسىلىلەرنى ھەل قىلىش ئۇسۇللىرىنى [بۇ يەردىن] تاپقىلى بولىدۇ (https://github.com/CollaboraOnline/richdocumentscode?tab=readme-ov-file#troubleshooting).",
    "Your system is ARM64, but you have installed the x86_64 version of the app. Please remove this and" : "سىستېمىڭىز ARM64 ، ئەمما سىز بۇ دېتالنىڭ x86_64 نەشرىنى قاچىلىدىڭىز. بۇنى ئۆچۈرۈڭ",
    "install the correct version from the Nextcloud App Store." : "Nextcloud ئەپ دۇكىنىدىن توغرا نەشرىنى قاچىلاڭ.",
    "Your system is x86_64, but you have installed the ARM64 version of the app. Please remove this and" : "سىستېمىڭىز x86_64 ، ئەمما سىز بۇ دېتالنىڭ ARM64 نەشرىنى قاچىلىدىڭىز. بۇنى ئۆچۈرۈڭ",
    "You have the Collabora Online app enabled. For further information and configuration, please check:" : "سىزدە Collabora تور دېتالى قوزغىتىلغان. تېخىمۇ كۆپ ئۇچۇر ۋە سەپلىمىلەرنى تەكشۈرۈڭ:",
    "Administration settings > Nextcloud Office" : "باشقۇرۇش تەڭشەكلىرى> Nextcloud ئىشخانىسى",
    "The Built-in CODE Server is designed to work with the Nextcloud Office app." : "ئىچىگە ئورۇنلاشتۇرۇلغان CODE مۇلازىمېتىرى Nextcloud Office دېتالى بىلەن ئىشلەش ئۈچۈن لايىھەلەنگەن.",
    "Install it from the Nextcloud App Store." : "Nextcloud ئەپ دۇكىنىدىن قاچىلاڭ.",
    "An error occurred while trying to check your Collabora Online app installation. You may report this error\n                with the tag: <em>richdocuments-EnabledCheckFailed</em>" : "Collabora Online ئەپ قاچىلاشنى تەكشۈرمەكچى بولغاندا خاتالىق كۆرۈلدى. بۇ خاتالىقنى دوكلات قىلىشىڭىز مۇمكىن\n                خەتكۈچ بىلەن: <em> richdocuments-EnabledCheckFailed </em>"
},
"nplurals=2; plural=(n != 1);");
#! /bin/bash
#
# Usage: ./build-nextcloud-app.sh [-f] [URL-to-AppImage-to-download]
#

# the safest is to build from a clean 'build', warn if it exists

occ=$HOME/bin/occ
cert_dir=$HOME/.nextcloud/certificates
app_name=richdocumentscode

test -d "build" -a "$1" != "-f" && {
    cat << EOF
The 'build' subdir already exists.  Please move it away, or run:"

  `basename $0` -f

if you know what you are doing
EOF
    exit 1
}

# remove the -f if present
test "$1" == "-f" && shift

# use the latest, unless URL specified on the command line
APPIMAGE_URL=${1:-https://www.collaboraoffice.com/Collabora-Office-AppImage-Snapshot/collabora-online-snapshot-LATEST.AppImage}

echo "Using the AppImage from: $APPIMAGE_URL"
echo

# build in the "build" dir
test -d "build" || mkdir -p "build"
cd "build"

test -d "${app_name}" || mkdir -p "${app_name}"

cp -ra ../appinfo ../l10n ../lib ../templates ${app_name}/
cp -ra ../img ${app_name}/
cp -a ../CHANGELOG.md ../LICENSE ../NOTICES ${app_name}/

# get the appimage
test -d "${app_name}/collabora" || mkdir -p "${app_name}/collabora"
test -f "${app_name}/collabora/Collabora_Online.AppImage" || curl "$APPIMAGE_URL" -o "${app_name}/collabora/Collabora_Online.AppImage"
chmod a+x "${app_name}/collabora/Collabora_Online.AppImage"

# Create proxy.php and get the version hash from loolwsd into it
HASH=`./${app_name}/collabora/Collabora_Online.AppImage --version-hash`
echo "HASH: $HASH"
sed "s/%LOOLWSD_VERSION_HASH%/$HASH/g" ../proxy.php > ${app_name}/proxy.php
cp ../proxy.php > ${app_name}/proxy.php

# check if we are building for arm64
if [[ "$APPIMAGE_URL" =~ "arm64" ]]; then
    echo "Building for arm64"
    echo

    mv ${app_name} richdocumentscode_arm64
    app_name=richdocumentscode_arm64

    sed -i.bak "s/x86_64/aarch64/g" ${app_name}/proxy.php
    sed -i.bak -e 's/<id>richdocumentscode/<id>richdocumentscode_arm64/g' -e 's/Built-in CODE Server/Built-in CODE Server (ARM64)/g' ${app_name}/appinfo/info.xml
    sed -i.bak "s/richdocumentscode/richdocumentscode_arm64/g" ${app_name}/lib/Settings/Admin.php
    sed -i.bak "s/richdocumentscode/richdocumentscode_arm64/g" ${app_name}/lib/Settings/Section.php

    for f in ${app_name}/l10n/*.js; do
    sed -i.bak "s/richdocumentscode/richdocumentscode_arm64/g" "$f"
    done

    # clean-up
    rm ${app_name}/proxy.php.bak
    rm ${app_name}/appinfo/info.xml.bak
    rm ${app_name}/l10n/*.bak
    rm ${app_name}/lib/Settings/*.bak
fi

echo "Signing…"
$occ integrity:sign-app --privateKey=${cert_dir}/${app_name}.key --certificate=${cert_dir}/${app_name}.crt --path=$(pwd)/${app_name}
tar czf ${app_name}.tar.gz ${app_name}
openssl dgst -sha512 -sign ${cert_dir}/${app_name}.key ${app_name}.tar.gz | openssl base64


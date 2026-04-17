#!/bin/bash
set -euo pipefail

# variables containing directory portions of paths
FVRP_DIR=/var/www/html/fvrp
FVRP_PUBLIC_DIR=$FVRP_DIR/public
TOOLBAR_ICON_DIR=$FVRP_PUBLIC_DIR/farmville/assets/hashed/assets/decorations
PLUGIN_DIR=/usr/lib64/mozilla/plugins
BASILISK_DIR=/opt/basilisk

# variables containing resource links with no filename
FLASH_LINK_BASE=https://github.com/darktohka/clean-flash-builds/releases/download/v1.7
BASILISK_LINK_BASE=https://dl.basilisk-browser.org
ASSET_LINK_BASE=https://mirror.farmplay.win
SUPPLEMENTS_LINK_BASE=https://mirror.farmplay.win
DEHASHER_LINK_BASE=https://github.com/PuccamiteTech/FVDehasher/releases/download/1.02-SNAPSHOT

# variables containing filenames used with resource links
BASILISK_LINK_FILE=basilisk-20260122233106.linux-x86_64-gtk3.tar.xz
FLASH_LINK_FILE=flash_player_patched_npapi_linux.x86_64.tar.gz
ASSET_LINK_FILE1=urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00000.warc.gz
ASSET_LINK_FILE2=urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00001.warc.gz
ASSET_LINK_FILE3=urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00002.warc.gz
ASSET_LINK_FILE4=urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00003.warc.gz
SUPPLEMENTS_LINK_FILE=supplements.zip
DEHASHER_LINK_FILE=ubuntu-build.zip

# variables containing general filenames
DEHASHER_FILE=FVDehasher-1.02-SNAPSHOT
ITEMS_SQL_FILE=farmvilledb_trimmed.sql
TOOLBAR_ICON_FILE=toolbar32x32.png

# variables containing full file paths
BASILISK_SHORTCUT_PATH=/usr/share/applications/basilisk.desktop
AMFPHP_CRED_PATH=$FVRP_PUBLIC_DIR/farmville/flashservices/amfphp/Helpers/config.php
SITE_CONF_PATH=/etc/apache2/sites-available/000-default.conf

# variables containing database credentials
DB_NAME=fvdb
DB_USER=farmer
DB_PASS=examplePassword

apt-get update
apt install -y apache2 mariadb-server php php-xml php-dom npm composer php-mysql libgtk2.0-0 libdbus-glib-1-2
git clone git@github.com:cavinpabua/fv-replowed-eloquent.git $FVRP_DIR
chown -R "$SUDO_USER":www-data $FVRP_DIR
cd $FVRP_DIR
sudo -u "$SUDO_USER" composer update --ignore-platform-reqs
sudo -u "$SUDO_USER" npm install --include-workspace-root
sudo -u "$SUDO_USER" npm run build

# secure the MariaDB installation
mariadb -u root <<-EOF
ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket;
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_PASS');
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'%';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
EOF

wget $SUPPLEMENTS_LINK_BASE/$SUPPLEMENTS_LINK_FILE
unzip $SUPPLEMENTS_LINK_FILE $ITEMS_SQL_FILE
rm $SUPPLEMENTS_LINK_FILE
mariadb -u root "$DB_NAME" < $ITEMS_SQL_FILE

mv .env.example .env

sed -i.bak \
-e "s|^DB_DATABASE=.*|DB_DATABASE='${DB_NAME}'|" \
-e "s|^DB_USERNAME=.*|DB_USERNAME='${DB_USER}'|" \
-e "s|^DB_PASSWORD=.*|DB_PASSWORD='${DB_PASS}'|" \
.env

php artisan key:generate
php artisan migrate --seed

sed -i.bak \
-e "s|^define('DB_NAME', '.*');|define('DB_NAME', '${DB_NAME}');|" \
-e "s|^define('DB_USERNAME', '.*');|define('DB_USERNAME', '${DB_USER}');|" \
-e "s|^define('DB_PASSWORD', '.*');|define('DB_PASSWORD', '${DB_PASS}');|" \
"$AMFPHP_CRED_PATH"


a2enmod rewrite
sed -i "s|^\s*DocumentRoot\s\+.*|DocumentRoot $FVRP_PUBLIC_DIR|" $SITE_CONF_PATH

if ! grep -q "<Directory $FVRP_PUBLIC_DIR>" $SITE_CONF_PATH; then
sed -i "/<\/VirtualHost>/i \ \n<Directory $FVRP_PUBLIC_DIR>\nAllowOverride All\nRequire all granted\n</Directory>\n" $SITE_CONF_PATH
fi

chmod -R 775 $FVRP_DIR
systemctl daemon-reload
systemctl restart apache2

wget $BASILISK_LINK_BASE/$BASILISK_LINK_FILE
mkdir -p $BASILISK_DIR
tar -xvf $BASILISK_LINK_FILE -C $BASILISK_DIR --strip-components=1
rm $BASILISK_LINK_FILE
ln -s $BASILISK_DIR/basilisk /usr/bin/basilisk
ln -s $BASILISK_DIR/browser/chrome/icons/default/default16.png /usr/share/icons/hicolor/16x16/apps/basilisk.png
ln -s $BASILISK_DIR/browser/chrome/icons/default/default32.png /usr/share/icons/hicolor/32x32/apps/basilisk.png
ln -s $BASILISK_DIR/browser/chrome/icons/default/default48.png /usr/share/icons/hicolor/48x48/apps/basilisk.png
ln -s $BASILISK_DIR/browser/icons/mozicon128.png /usr/share/icons/hicolor/128x128/apps/basilisk.png
update-alternatives --install /usr/bin/gnome-www-browser gnome-www-browser /usr/bin/basilisk 100
update-alternatives --install /usr/bin/x-www-browser x-www-browser /usr/bin/basilisk 100

cat <<-EOF > $BASILISK_SHORTCUT_PATH
[Desktop Entry]
Version=1.0
Name=Basilisk
Comment=Browse the World Wide Web
Keywords=Internet;WWW;Browser;Web;Explorer
Exec=env MOZ_PLUGIN_PATH=$PLUGIN_DIR basilisk %u
Terminal=false
X-MultipleArgs=false
Type=Application
Icon=basilisk
Categories=Network;WebBrowser;Internet
MimeType=video/webm;text/html;image/png;image/jpeg;image/gif;application/xml;application/xml;application/xhtml+xml;application/x-xpinstall;application/rss+xml;application/rdf+xml;
StartupNotify=true
EOF

gtk-update-icon-cache -f /usr/share/icons/hicolor

wget $FLASH_LINK_BASE/$FLASH_LINK_FILE
mkdir -p $PLUGIN_DIR
tar -xvf $FLASH_LINK_FILE -C $PLUGIN_DIR libflashplayer.so
rm $FLASH_LINK_FILE

wget $DEHASHER_LINK_BASE/$DEHASHER_LINK_FILE
unzip $DEHASHER_LINK_FILE
rm $DEHASHER_LINK_FILE

wget $ASSET_LINK_BASE/$ASSET_LINK_FILE1 $ASSET_LINK_BASE/$ASSET_LINK_FILE2 $ASSET_LINK_BASE/$ASSET_LINK_FILE3 $ASSET_LINK_BASE/$ASSET_LINK_FILE4
sudo -u "$SUDO_USER" ./$DEHASHER_FILE
rm $DEHASHER_FILE $ASSET_LINK_FILE1 $ASSET_LINK_FILE2 $ASSET_LINK_FILE3 $ASSET_LINK_FILE4 entries.txt

mv $TOOLBAR_ICON_DIR/$TOOLBAR_ICON_FILE .
rm -rf $FVRP_PUBLIC_DIR/farmville/assets
mv -f farmville/assets $FVRP_PUBLIC_DIR/farmville
mv $TOOLBAR_ICON_FILE $TOOLBAR_ICON_DIR
rm -rf farmville

a2enmod deflate

echo All done!

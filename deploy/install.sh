#!/usr/bin/env bash
# One-shot install of Kotak on a fresh Amazon Linux 2023 (arm64) instance:
# steps 2-10 of docs/deploy-aws-ec2.md in one go. Run as ec2-user:
#
#   curl -fsSL https://raw.githubusercontent.com/karchung0930/kotak-parcel-backend/main/deploy/install.sh \
#     | DOMAIN=kotak.example.com bash
#
# DOMAIN  the address visitors use (CloudFront)        required
# ORIGIN  the instance's own name for CloudFront       default origin.$DOMAIN
# SEED    1 = load the demo accounts and orders        default 1
#
# Safe to run again: finished parts are skipped. Everything runs inside main(),
# so bash has read the whole script before anything changes on disk.
set -Eeuo pipefail

main() {
    : "${DOMAIN:?Set DOMAIN, e.g. DOMAIN=kotak.example.com}"
    ORIGIN="${ORIGIN:-origin.$DOMAIN}"
    SEED="${SEED:-1}"
    APP_ROOT=/var/www/kotak
    BACKEND=$APP_ROOT/kotak-parcel-backend
    FRONTEND=$APP_ROOT/kotak-parcel-frontend
    umask 0002
    trap 'echo "!! install.sh failed on line $LINENO. Fix the error and run it again; finished steps are skipped." >&2' ERR

    step "Save paths and host names in ~/.kotak-env"
    cat > ~/.kotak-env <<EOF
APP_ROOT=$APP_ROOT
BACKEND=$BACKEND
FRONTEND=$FRONTEND
DOMAIN=$DOMAIN
ORIGIN=$ORIGIN
EOF
    grep -q 'kotak-env' ~/.bashrc || echo '. ~/.kotak-env' >> ~/.bashrc
    grep -q '^umask 0002' ~/.bashrc || echo 'umask 0002' >> ~/.bashrc

    step "Install PHP 8.4, nginx, Node 22 and Composer"
    sudo dnf -y -q upgrade --releasever=latest
    sudo dnf -y -q install \
        php8.4-cli php8.4-fpm php8.4-common php8.4-opcache \
        php8.4-mbstring php8.4-xml php8.4-intl php8.4-bcmath php8.4-zip \
        php8.4-gmp php8.4-sodium php8.4-process php8.4-mysqlnd php8.4-pdo \
        nginx git unzip nodejs22 nodejs22-npm
    sudo dnf -y -q install composer

    step "Install MySQL 8.4 LTS from Oracle's repository"
    if ! rpm -q mysql-community-server >/dev/null; then
        curl -fsSL --retry 5 --retry-all-errors -o /tmp/mysql-repo.rpm https://dev.mysql.com/get/mysql84-community-release-el9-4.noarch.rpm
        sudo dnf -y -q install /tmp/mysql-repo.rpm
        # The repository targets EL9 and turns on 9.7 by default: pin both.
        sudo sed -i 's/\$releasever/9/g' /etc/yum.repos.d/mysql-community*.repo
        sudo awk -i inplace '/^\[/{s=$0} /^enabled=/{ if (s ~ /9\.7-lts/) $0="enabled=0"; else if (s ~ /^\[mysql-(tools-)?8\.4-lts-community\]/) $0="enabled=1" } 1' /etc/yum.repos.d/mysql-community.repo
        sudo dnf -y -q install mysql-community-server
    fi
    mysqld --version | grep -q ' 8\.4\.' || { echo "Expected MySQL 8.4, got: $(mysqld --version)" >&2; exit 1; }

    step "PHP settings and the shared apache group"
    sudo usermod -aG apache ec2-user
    sudo mkdir -p $APP_ROOT
    sudo chown ec2-user:apache $APP_ROOT
    sudo sed -i -e 's/^upload_max_filesize = .*/upload_max_filesize = 8M/' \
                -e 's/^post_max_size = .*/post_max_size = 8M/' /etc/php.ini
    sudo sed -i -e 's/^pm.max_children = .*/pm.max_children = 10/' \
                -e 's/^pm.start_servers = .*/pm.start_servers = 2/' \
                -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
                -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 5/' /etc/php-fpm.d/www.conf
    sudo mkdir -p /etc/systemd/system/php-fpm.service.d
    printf '[Service]\nUMask=0002\n' | sudo tee /etc/systemd/system/php-fpm.service.d/umask.conf >/dev/null
    sudo php-fpm -t

    step "Get the code"
    cd $APP_ROOT
    [ -d $BACKEND ] || git clone https://github.com/karchung0930/kotak-parcel-backend.git
    [ -d $FRONTEND ] || git clone https://github.com/karchung0930/kotak-parcel-frontend.git
    cd $BACKEND
    # Dev packages included: the demo seeder needs Faker.
    composer install --optimize-autoloader --no-interaction --quiet
    if [ ! -f .env ]; then
        cp .env.example .env
        php artisan key:generate --no-interaction
        sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" \
               -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
               -e "s|^APP_URL=.*|APP_URL=http://$DOMAIN|" \
               -e "s|^LOG_STACK=.*|LOG_STACK=daily|" \
               -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" .env
    fi
    chgrp apache .env && chmod 640 .env

    step "MySQL: localhost only, random passwords, database and user"
    grep -q '^bind-address=127.0.0.1' /etc/my.cnf || printf 'bind-address=127.0.0.1\nmysqlx=OFF\n' | sudo tee -a /etc/my.cnf >/dev/null
    sudo systemctl enable --now mysqld
    if ! sudo test -f /root/.my.cnf; then
        local tmp root_pass
        tmp=$(sudo grep -o 'temporary password.*: .*' /var/log/mysqld.log | tail -1 | sed 's/.*: //')
        root_pass="$(php -r 'echo bin2hex(random_bytes(16));')Aa1_"
        sudo mysql -uroot -p"$tmp" --connect-expired-password -e "ALTER USER root@localhost IDENTIFIED BY '$root_pass';" 2>/dev/null
        printf '[client]\nuser=root\npassword=%s\n' "$root_pass" | sudo tee /root/.my.cnf >/dev/null
        sudo chmod 600 /root/.my.cnf
    fi
    # A new .env still has the local development password from .env.example.
    if grep -q '^DB_PASSWORD=secret$' .env; then
        local db_pass
        db_pass="$(php -r 'echo bin2hex(random_bytes(16));')Aa1_"
        sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS kotak CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'kotak'@'localhost' IDENTIFIED BY '${db_pass}';
ALTER USER 'kotak'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON kotak.* TO 'kotak'@'localhost';
SQL
        sed -i -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" \
               -e "s|^#\? \?DB_HOST=.*|DB_HOST=127.0.0.1|" \
               -e "s|^#\? \?DB_PORT=.*|DB_PORT=3306|" \
               -e "s|^#\? \?DB_DATABASE=.*|DB_DATABASE=kotak|" \
               -e "s|^#\? \?DB_USERNAME=.*|DB_USERNAME=kotak|" \
               -e "s|^#\? \?DB_PASSWORD=.*|DB_PASSWORD=${db_pass}|" .env
    fi
    php artisan config:clear --quiet
    php artisan migrate --force

    step "Build the frontend"
    cd $FRONTEND
    npm ci --no-audit --no-fund --loglevel=error
    npm run build
    test -f $BACKEND/public/build/manifest.json

    step "Storage permissions"
    cd $BACKEND
    sudo chgrp -R apache storage bootstrap/cache
    sudo chmod -R ug+rwX storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
    sudo chmod o-rwx storage bootstrap/cache

    if [ "$SEED" = 1 ] && [ "$(php artisan tinker --execute='echo App\Models\Order::count();' 2>/dev/null | tail -1)" = 0 ]; then
        step "Demo data (every password is \"password\")"
        sudo -u apache bash -c 'umask 0002 && php artisan db:seed --env=staging --force'
        # The worker is not running yet: drop the seeded emails so they are never sent.
        php artisan queue:clear --force
    fi
    php artisan optimize

    step "nginx and PHP-FPM"
    sudo cp $BACKEND/deploy/nginx.conf /etc/nginx/conf.d/kotak.conf
    sudo sed -i "s/server_name kotak.example.com;/server_name $DOMAIN $ORIGIN;/" /etc/nginx/conf.d/kotak.conf
    sudo nginx -t
    sudo systemctl daemon-reload
    sudo systemctl enable --now php-fpm nginx
    sudo systemctl restart php-fpm nginx

    step "Queue worker and scheduler"
    sudo cp $BACKEND/deploy/systemd/kotak-* /etc/systemd/system/
    sudo systemctl daemon-reload
    sudo systemctl enable --now kotak-worker kotak-scheduler.timer

    step "Check"
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" http://localhost/up)
    sudo systemctl is-active mysqld php-fpm nginx kotak-worker kotak-scheduler.timer | paste -sd ' '
    [ "$code" = 200 ] || { echo "http://localhost/up returned $code" >&2; exit 1; }
    echo
    echo "Kotak is running on this instance (http://localhost/up = 200)."
    echo "Next: HTTPS for $ORIGIN, then CloudFront and SES (docs/deploy-aws-ec2.md steps 11-13)."
    echo "Sign out and in again so the apache group and umask apply to your shell."
}

step() { printf '\n==> %s\n' "$*"; }

main "$@"
exit

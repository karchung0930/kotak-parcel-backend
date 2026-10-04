# Deploying Kotak on AWS EC2

One EC2 instance (Amazon Linux 2023, arm64) runs nginx, PHP-FPM 8.4, MySQL 8.4,
the queue worker and the scheduler. CloudFront sits in front of it, and SES
sends the email. Run every block as `ec2-user`, in order.

```
Browser ──HTTPS──> CloudFront (kotak.example.com) ──HTTPS──> EC2 nginx (origin.kotak.example.com)
                                                              └─> Laravel ──SMTP──> SES (Singapore)
```

The examples use `kotak.example.com`; replace it with your own domain
everywhere.

## 0. AWS console

| Setting        | Value                                                                 |
| -------------- | --------------------------------------------------------------------- |
| Region         | Asia Pacific (Malaysia) `ap-southeast-5`                              |
| AMI            | Amazon Linux 2023 (kernel-6.18), **64-bit (Arm)**                     |
| Instance type  | `m8g.medium` or `t4g.small` (2 GiB or more)                           |
| Key pair       | Create one (`.pem`) and keep the file                                 |
| Storage        | gp3, 12 GiB                                                           |
| Security group | SSH 22 from My IP; HTTP 80 and HTTPS 443 from anywhere (443 is narrowed to CloudFront in step 12) |
| Elastic IP     | Allocate one and associate it with the instance                       |

Route 53 → **Hosted zones** → your domain → **Create record**:

| Record name | Type | Value          |
| ----------- | ---- | -------------- |
| `origin`    | A    | the Elastic IP |

The main name `kotak.example.com` points at CloudFront later (step 12).

## 1. Sign in and save the variables

Connect to the instance:

```sh
ssh -i kotak-key.pem ec2-user@<ELASTIC_IP>
```

Save the paths and the two host names (`DOMAIN` for visitors, `ORIGIN` for
the EC2 instance):

```sh
cat > ~/.kotak-env <<'EOF'
APP_ROOT=/var/www/kotak
BACKEND=$APP_ROOT/kotak-parcel-backend
FRONTEND=$APP_ROOT/kotak-parcel-frontend
DOMAIN=kotak.example.com
ORIGIN=origin.kotak.example.com
EOF
echo '. ~/.kotak-env' >> ~/.bashrc
echo 'umask 0002' >> ~/.bashrc
. ~/.kotak-env
```

## 2. Install the software

Update the system and reboot:

```sh
sudo dnf -y upgrade --releasever=latest
sudo reboot
```

Sign in again, then install PHP 8.4, nginx, Node 22 and Composer (Composer
after PHP, or dnf picks PHP 8.5):

```sh
sudo dnf -y install \
    php8.4-cli php8.4-fpm php8.4-common php8.4-opcache \
    php8.4-mbstring php8.4-xml php8.4-intl php8.4-bcmath php8.4-zip \
    php8.4-gmp php8.4-sodium php8.4-process php8.4-mysqlnd php8.4-pdo \
    nginx git unzip nodejs22 nodejs22-npm
sudo dnf -y install composer
```

Amazon Linux has no MySQL server package, so add Oracle's MySQL repository.
It is built for EL9, so pin its version to 9:

```sh
curl -fsSLo /tmp/mysql-repo.rpm https://dev.mysql.com/get/mysql84-community-release-el9-4.noarch.rpm
sudo dnf -y install /tmp/mysql-repo.rpm
sudo sed -i 's/\$releasever/9/g' /etc/yum.repos.d/mysql-community*.repo
```

The repository turns on MySQL 9.7 by default. Switch it to 8.4 LTS, so a later
`dnf upgrade` never moves to 9.7, then install:

```sh
sudo awk -i inplace '/^\[/{s=$0} /^enabled=/{ if (s ~ /9\.7-lts/) $0="enabled=0"; else if (s ~ /^\[mysql-(tools-)?8\.4-lts-community\]/) $0="enabled=1" } 1' /etc/yum.repos.d/mysql-community.repo
sudo dnf -y install mysql-community-server
mysqld --version
```

`mysqld --version` must say **8.4**.

## 3. PHP settings and folder permissions

Let `ec2-user` share files with PHP-FPM (which runs as `apache`) and create
the app folder:

```sh
sudo usermod -aG apache ec2-user
sudo mkdir -p $APP_ROOT
sudo chown ec2-user:apache $APP_ROOT
```

Raise the upload limit to 8 MB (delivery photos and rate spreadsheets, both
at most 5 MB), shrink the PHP-FPM pool, and make PHP-FPM create
group-writable files:

```sh
sudo sed -i -e 's/^upload_max_filesize = .*/upload_max_filesize = 8M/' \
            -e 's/^post_max_size = .*/post_max_size = 8M/' /etc/php.ini
sudo sed -i -e 's/^pm.max_children = .*/pm.max_children = 10/' \
            -e 's/^pm.start_servers = .*/pm.start_servers = 2/' \
            -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
            -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 5/' /etc/php-fpm.d/www.conf
sudo mkdir -p /etc/systemd/system/php-fpm.service.d
printf '[Service]\nUMask=0002\n' | sudo tee /etc/systemd/system/php-fpm.service.d/umask.conf
sudo php-fpm -t
```

**Sign out and sign in again** so the new group applies.

## 4. Get the code

Clone both repositories side by side:

```sh
cd $APP_ROOT
git clone https://github.com/karchung0930/kotak-parcel-backend.git
git clone https://github.com/karchung0930/kotak-parcel-frontend.git
```

Install the PHP packages (with dev packages: the demo seeder needs Faker) and
create `.env`:

```sh
cd $BACKEND
composer install --optimize-autoloader --no-interaction
cp .env.example .env
php artisan key:generate
sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" \
       -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
       -e "s|^APP_URL=.*|APP_URL=http://$DOMAIN|" \
       -e "s|^LOG_STACK=.*|LOG_STACK=daily|" \
       -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" .env
chgrp apache .env && chmod 640 .env
```

## 5. Database (MySQL 8.4)

Listen on localhost only (and turn off the unused X protocol), then start
MySQL:

```sh
printf 'bind-address=127.0.0.1\nmysqlx=OFF\n' | sudo tee -a /etc/my.cnf
sudo systemctl enable --now mysqld
```

MySQL starts with a temporary root password in its log. Replace it with a
random one and save that in `/root/.my.cnf`, so `sudo mysql` signs in by
itself:

```sh
TMP=$(sudo grep -o 'temporary password.*: .*' /var/log/mysqld.log | tail -1 | sed 's/.*: //')
ROOT_PASS="$(php -r 'echo bin2hex(random_bytes(16));')Aa1_"
sudo mysql -uroot -p"$TMP" --connect-expired-password -e "ALTER USER root@localhost IDENTIFIED BY '$ROOT_PASS';"
printf '[client]\nuser=root\npassword=%s\n' "$ROOT_PASS" | sudo tee /root/.my.cnf >/dev/null
sudo chmod 600 /root/.my.cnf
unset TMP ROOT_PASS
```

Create the database and a user with a random password (the `Aa1_` ending meets
MySQL's password rules), and write them into `.env` in place of the local
development values from `.env.example`:

```sh
DB_PASS="$(php -r 'echo bin2hex(random_bytes(16));')Aa1_"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS kotak CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'kotak'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'kotak'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON kotak.* TO 'kotak'@'localhost';
SQL
cd $BACKEND
sed -i -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" \
       -e "s|^#\? \?DB_HOST=.*|DB_HOST=127.0.0.1|" \
       -e "s|^#\? \?DB_PORT=.*|DB_PORT=3306|" \
       -e "s|^#\? \?DB_DATABASE=.*|DB_DATABASE=kotak|" \
       -e "s|^#\? \?DB_USERNAME=.*|DB_USERNAME=kotak|" \
       -e "s|^#\? \?DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
unset DB_PASS
```

Create the tables and check the connection (expect MySQL 8.4.x):

```sh
cd $BACKEND
php artisan migrate --force
php artisan db:show | head -n 4
```

## 6. Build the frontend

Install the packages and build into `$BACKEND/public/build`:

```sh
cd $FRONTEND
npm ci
npm run build
ls $BACKEND/public/build/manifest.json
```

## 7. Storage permissions

Let PHP-FPM write `storage` and `bootstrap/cache`:

```sh
cd $BACKEND
sudo chgrp -R apache storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo chmod o-rwx storage bootstrap/cache
```


## 8. Demo data

Seed the demo accounts and orders (see [DEMO_ACCOUNTS](../README.md#demo_accounts);
every password is `password`). The seeder refuses `production`, so it runs
with `--env=staging`:

```sh
cd $BACKEND
sudo -u apache bash -c 'umask 0002 && php artisan db:seed --env=staging --force'
```

Drop the ~115 emails the seeder queued (status updates and drivers' new
jobs), so they are never sent:

```sh
cd $BACKEND
php artisan queue:clear --force
```

Cache the configuration and routes:

```sh
cd $BACKEND
php artisan optimize
```

## 9. nginx

Install the site config for both host names, then start PHP-FPM and nginx:

```sh
sudo cp $BACKEND/deploy/nginx.conf /etc/nginx/conf.d/kotak.conf
sudo sed -i "s/server_name kotak.example.com;/server_name $DOMAIN $ORIGIN;/" /etc/nginx/conf.d/kotak.conf
sudo nginx -t
sudo systemctl daemon-reload
sudo systemctl enable --now php-fpm nginx
```

Check the app answers (expect `200`):

```sh
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/up
```

## 10. Queue worker and scheduler

Install and start the worker (status, reminder and driver emails, and
reading and checking rate imports) and the scheduler timer. The timer runs
`php artisan schedule:run` every minute, which emails drivers their run sheets
at 7:00, sends the drop-off reminders at 9:00, cancels unclaimed orders at
midnight and deletes uploaded rate spreadsheets older than a week at 3:00,
Malaysia time:

```sh
sudo cp $BACKEND/deploy/systemd/kotak-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kotak-worker kotak-scheduler.timer
systemctl is-active kotak-worker kotak-scheduler.timer
```

## 11. HTTPS on the instance

CloudFront talks to the instance over HTTPS, so the origin needs its own
certificate. Check `origin` points at the Elastic IP:

```sh
dig +short A $ORIGIN
```

Get a free Let's Encrypt certificate for it and turn on auto-renewal:

```sh
sudo dnf -y install certbot python3-certbot-nginx
sudo certbot --nginx -d $ORIGIN --redirect
sudo systemctl enable --now certbot-renew.timer
```

Check it (expect `200`):

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://$ORIGIN/up
```

## 12. CloudFront

**Certificate** (ACM must be in **US East (N. Virginia) `us-east-1`** for
CloudFront): Request → Public certificate → domain `kotak.example.com` → DNS
validation → **Create records in Route 53**. Wait until it shows *Issued*.

**Distribution** (CloudFront → Create distribution):

| Setting                  | Value                                                        |
| ------------------------ | ------------------------------------------------------------ |
| Origin domain            | `origin.kotak.example.com`                                   |
| Origin protocol          | HTTPS only                                                   |
| Viewer protocol policy   | Redirect HTTP to HTTPS                                       |
| Allowed HTTP methods     | GET, HEAD, OPTIONS, PUT, POST, PATCH, DELETE                 |
| Cache policy             | `CachingDisabled` (every page is per user)                   |
| Origin request policy    | `AllViewer` (passes the Host header, cookies and query)      |
| Alternate domain name    | `kotak.example.com`                                          |
| Custom SSL certificate   | the ACM certificate above                                    |
| WAF                      | Do not enable                                                |

After it is created, **Behaviors → Create behavior** for the built assets:

| Setting                | Value                     |
| ---------------------- | ------------------------- |
| Path pattern           | `/build/*`                |
| Origin                 | the same origin           |
| Viewer protocol policy | Redirect HTTP to HTTPS    |
| Cache policy           | `CachingOptimized`        |

**DNS**: Route 53 → Create record → name `kotak`, type A, **Alias** → *Alias to
CloudFront distribution* → pick the distribution.

**Lock the origin**: EC2 → Security groups → Edit inbound rules → delete the
two HTTPS 443 rules from anywhere and add HTTPS 443 with source prefix list
`com.amazonaws.global.cloudfront.origin-facing`. Keep port 80 open for
certificate renewal.

Point the app at the CloudFront address, require secure cookies, and trust
CloudFront's `X-Forwarded-For` (rate limits then see the visitor's IP):

```sh
cd $BACKEND
sed -i "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" .env
echo 'SESSION_SECURE_COOKIE=true' >> .env
echo 'TRUSTED_PROXIES=*' >> .env
php artisan optimize && php artisan queue:restart
```

Open `https://kotak.example.com/track?number=KT-7Q4M92XD`, then sign in as
`admin@kotak.test`.

## 13. Email (Amazon SES)

SES console in **Asia Pacific (Singapore) `ap-southeast-1`** (Malaysia has
no SES SMTP endpoint):

1. **Identities → Create identity → Domain** `kotak.example.com`, Easy DKIM,
   tick **Publish DNS records to Route 53**. Wait for *Verified*.
2. **Identities → Create identity → Email address**: your own inbox, then
   click the link in the email AWS sends.
3. **SMTP settings → Create SMTP credentials**, and save the user name and
   password.

The account starts in the SES sandbox, which only delivers to verified
addresses. For the demo that is enough: `MAIL_TO_ADDRESS` sends **every**
email (sign-up verification, staff invitations, status updates) to your own
inbox instead of the `@kotak.test` addresses.

Fill in your values and write them into `.env`:

```sh
SES_USER='AKIA...'
SES_PASS='...'
MY_EMAIL='you@example.com'
cd $BACKEND
sed -i -e "s|^MAIL_MAILER=.*|MAIL_MAILER=smtp|" \
       -e "s|^MAIL_HOST=.*|MAIL_HOST=email-smtp.ap-southeast-1.amazonaws.com|" \
       -e "s|^MAIL_PORT=.*|MAIL_PORT=587|" \
       -e "s|^MAIL_USERNAME=.*|MAIL_USERNAME=$SES_USER|" \
       -e "s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=$SES_PASS|" \
       -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=no-reply@$DOMAIN|" .env
echo "MAIL_TO_ADDRESS=$MY_EMAIL" >> .env
unset SES_USER SES_PASS
php artisan optimize && php artisan queue:restart
```

Send a test email (it should arrive in your inbox):

```sh
cd $BACKEND
php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Kotak SES test', fn (\$m) => \$m->to('test@kotak.test')->subject('Kotak SES test'));"
```

For real customers later: request SES production access and remove
`MAIL_TO_ADDRESS`.

## After changing `.env`

Re-cache the config and restart the worker:

```sh
cd $BACKEND && php artisan optimize && php artisan queue:restart
```

## Updating

Pull both repositories, install, build, migrate and restart:

```sh
$BACKEND/deploy/deploy.sh
```

The first update with site settings lowers the default drop-off limit from
14 days to 7. Orders already waiting for drop-off keep the 14 days they were
placed under: the migration stores each one's deadline, so the next midnight
run cancels nothing early. Orders placed after the update get the limit set
on **Site settings**.

The first update with rate cards publishes the prices that were in
`config/kotak.php` (RM 8.00 for the first kg, RM 2.00 for each further kg,
volumetric divisor 5000) as the **Standard rates**, in effect from before the
earliest order, and links every existing order to it. Prices stay the same
until an admin publishes new rates on **Rates**. Nothing new runs on the
scheduler: a scheduled card takes effect at its time when prices are next
worked out.

The first update with rate imports adds the `rate_imports` table and the
OpenSpout package, which needs the `zip` and `xml` extensions installed in
step 2. Imports are read and checked by the queue worker that is already
running, and the existing scheduler timer deletes uploaded files after a week
(`rates:prune-imports`, 3:00), so there is nothing new to install or start.

The first update with driver emails needs no migration. The emails about new,
moved and removed jobs go through the queue worker that is already running,
and the existing scheduler timer sends the morning run sheets
(`drivers:send-run-sheets`, 7:00), so again there is nothing new to install
or start.

## Logs

Look here when the site shows 502, 500 or a blank page:

```sh
sudo tail -n 50 /var/log/nginx/error.log
sudo tail -n 50 /var/log/php-fpm/www-error.log
tail -n 50 $BACKEND/storage/logs/laravel-*.log
sudo journalctl -u kotak-worker -n 50 --no-pager
```

## Reset the demo data

Empty the database and seed again. The worker is stopped meanwhile so the
seeded emails are dropped instead of sent, and `deploy.sh` removes the
dev packages, so they are reinstalled first:

```sh
cd $BACKEND
sudo systemctl stop kotak-worker
composer install --optimize-autoloader --no-interaction
php artisan optimize:clear
sudo -u apache bash -c 'umask 0002 && php artisan migrate:fresh --seed --env=staging --force'
php artisan queue:clear --force
php artisan optimize
sudo systemctl start kotak-worker
```

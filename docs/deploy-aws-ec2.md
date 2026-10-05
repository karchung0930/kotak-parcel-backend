# Deploying Kotak on AWS EC2

One EC2 instance (Amazon Linux 2023, arm64) runs nginx, PHP-FPM 8.4, MySQL 8.4,
the queue worker, the scheduler and Laravel Reverb (the WebSocket server for
live delivery progress). CloudFront sits in front of it, and SES sends the
email. Run every block as `ec2-user`, in order.

```
Browser ──HTTPS──> CloudFront (kotak.example.com) ──HTTPS──> EC2 nginx (origin.kotak.example.com)
                                                              ├─> Laravel ──SMTP──> SES (Singapore)
                                                              └─> /app: Reverb on 127.0.0.1:8080 (WebSockets)
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

Give Reverb random credentials in place of the local ones from
`.env.example`, which anyone can read: Reverb is reachable from the
internet, and its secret signs the customers' private channels, so
`deploy.sh` and `env.sh` refuse to run while the example ones are there.
Laravel sends it the messages on `127.0.0.1:8080`, where it listens;
browsers reach it through nginx (step 9):

```sh
cd $BACKEND
sed -i -e "s|^BROADCAST_CONNECTION=.*|BROADCAST_CONNECTION=reverb|" \
       -e "s|^REVERB_APP_ID=.*|REVERB_APP_ID=$(php -r 'echo random_int(100000, 999999);')|" \
       -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$(php -r 'echo bin2hex(random_bytes(10));')|" \
       -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$(php -r 'echo bin2hex(random_bytes(20));')|" \
       -e "s|^REVERB_HOST=.*|REVERB_HOST=127.0.0.1|" \
       -e "s|^REVERB_PORT=.*|REVERB_PORT=8080|" \
       -e "s|^REVERB_SCHEME=.*|REVERB_SCHEME=http|" \
       -e "s|^REVERB_SERVER_HOST=.*|REVERB_SERVER_HOST=127.0.0.1|" \
       -e "s|^REVERB_SERVER_PORT=.*|REVERB_SERVER_PORT=8080|" .env
```

Reverb takes at most 5,000 open pages at once, below the 10,000 file
descriptors its service may use, and closes a connection that sends more
than 30 messages a minute (a page sends one or two). Change them with
`REVERB_APP_MAX_CONNECTIONS` and `REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS` if
ever needed.

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

The build puts the address of Reverb in the pages: the site's own address
over HTTPS, and the key from the backend's `.env`. Write it into the
frontend's `.env`:

```sh
cd $FRONTEND
cat >> .env <<EOF
VITE_REVERB_APP_KEY=$(grep '^REVERB_APP_KEY=' $BACKEND/.env | cut -d= -f2-)
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
EOF
```

Install the packages and build into `$BACKEND/public/build`:

```sh
cd $FRONTEND
npm ci
npm run build
ls $BACKEND/public/build/manifest.json
```

The build also holds the camera scanner's WebAssembly runtimes, its OCR
worker and the OCR models, about 44 MB in `public/build/assets`, and a gzip
copy (`.gz`) of every built file over 1 KB, about 16 MB more. They are
served from this server like every other built file, and browsers load the
scanner's files only when someone opens the scanner.

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

Drop the ~135 emails the seeder queued (status updates, receivers' delivery
updates and drivers' new jobs), so they are never sent, and its live
progress messages (queue `live`):

```sh
cd $BACKEND
php artisan queue:clear --force
php artisan queue:clear --queue=live --force
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

The site config sends the build's `.gz` copies with `gzip_static`, which
Amazon Linux's nginx is built with (expect `with-http_gzip_static_module`):

```sh
nginx -V 2>&1 | grep -o with-http_gzip_static_module
```

Check a built WebAssembly file goes out as `application/wasm` (nginx's own
`mime.types` maps `.wasm`; browsers need the type to compile it while it
downloads), compressed and cached for a year, and that the compressed bytes
are the build's `.gz` copy (the same length), not compressed on the fly:

```sh
ASSETS=$BACKEND/public/build/assets
for FILE in $(ls $ASSETS | grep -m1 'zxing_reader.*\.wasm$') $(ls $ASSETS | grep -m1 'tiny_rec.*\.tar$'); do
  curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' http://localhost/build/assets/$FILE | grep -iE 'content-type|content-encoding|content-length|cache-control'
  stat -c '%s bytes in %n' $ASSETS/$FILE.gz
done
```

Expect `application/wasm` for the `.wasm` (`application/octet-stream` for
the model `.tar`), `gzip`, a `Content-Length` equal to the `.gz` file's
size, and `max-age=31536000, immutable`.

The site config also passes `/app` on to Reverb with the WebSocket
`Upgrade` headers, so browsers reach it at the site's own address and over
its HTTPS. Only that path goes through: Laravel sends Reverb its messages on
`127.0.0.1:8080` directly. Reverb starts in the next step.

## 10. Queue worker, scheduler and Reverb

Install and start the worker (status, reminder, receiver and driver emails,
reading and checking rate imports, and working out the stops of live
delivery progress), the scheduler timer and Reverb. The timer runs
`php artisan schedule:run` every minute, which emails drivers their run sheets
at 7:00, sends the drop-off reminders at 9:00, cancels unclaimed orders at
midnight, sends the open tracking and order pages their new stop counts at
00:01, once the jobs left open the day before have joined the new day's
lists, and deletes uploaded rate spreadsheets older than a week at 3:00,
Malaysia time. The worker takes the live stop counts (queue `live`) before
the emails and imports (`default`) and checks an empty queue every second,
so a new count goes out at once even while a batch of emails is waiting.
Reverb (`php artisan reverb:start`) keeps the WebSocket connections of open
tracking and order pages, on `127.0.0.1:8080` only:

```sh
sudo cp $BACKEND/deploy/systemd/kotak-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kotak-worker kotak-scheduler.timer kotak-reverb
systemctl is-active kotak-worker kotak-scheduler.timer kotak-reverb
```

Check that nginx hands a WebSocket over to Reverb (expect
`HTTP/1.1 101 Switching Protocols`):

```sh
KEY=$(grep '^REVERB_APP_KEY=' $BACKEND/.env | cut -d= -f2-)
curl -si -N --max-time 3 -H "Host: $DOMAIN" -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
     -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
     -H "Origin: http://$DOMAIN" "http://localhost/app/$KEY?protocol=7" | head -n 1
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

Live delivery progress needs nothing more: the default behaviour passes
WebSocket connections (`/app/...`) on to the origin, as `AllViewer` forwards
the `Sec-WebSocket-*` headers, and `CachingDisabled` keeps them uncached.
The browsers' pings every 30 seconds keep a connection from being closed
for being idle.

`CachingOptimized` passes `Accept-Encoding` to the origin and keeps the
compressed copy nginx sends. CloudFront only compresses files up to 10 MB
itself, so the scanner's 25 MB WebAssembly runtime and 11 MB OCR worker
rely on the build's `.gz` copies, which nginx also sends to CloudFront
(`gzip_proxied any` in the site config, as CloudFront's requests carry a
`Via` header).

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

Keep `MAIL_TO_ADDRESS` set for as long as the demo accounts are listed in
the README. Customers may give a receiver's email address, which nobody
confirms, and the receiver is emailed once staff take the parcel and an
admin dispatches it. With the staff and admin passwords public, anyone could
do all three and have the site email any address. To keep real addresses
but send no receiver emails at all, add `RECEIVER_EMAILS=false` to `.env`.

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

For real customers later: remove or change the demo accounts' passwords
first, then request SES production access and remove `MAIL_TO_ADDRESS`.

## After changing `.env`

Re-cache the config and restart the worker and Reverb:

```sh
cd $BACKEND && php artisan optimize && php artisan queue:restart && php artisan reverb:restart
```

A new `REVERB_APP_KEY` also goes into the frontend's `.env` as
`VITE_REVERB_APP_KEY`, followed by a new build (`deploy/deploy.sh` builds).

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

The first update with receiver emails adds one optional column,
`orders.receiver_email`, which `deploy.sh` migrates; orders placed before it
have none, so their receivers get nothing. The receivers' delivery updates go
through the queue worker that is already running, so there is nothing new to
install or start either. Check that `MAIL_TO_ADDRESS` is still set while the
demo accounts are public (step 13), or add `RECEIVER_EMAILS=false`.

The first update with live delivery progress adds `orders.route_position`
(the order of each driver's stops) and `orders.route_date` (the day of the
run each stop is on), which `deploy.sh` migrates, numbering the open runs
as My jobs listed them, by postcode, each on its scheduled day. The
existing scheduler timer sends the new counts after midnight
(`deliveries:refresh-progress`, 00:01). It also adds Laravel Reverb, set up
once with this first update:

1. Give the backend's `.env` Reverb settings with random credentials, in
   one command (`env.sh` replaces a line that is there and adds one that is
   not):

   ```sh
   $BACKEND/deploy/env.sh BROADCAST_CONNECTION=reverb \
       REVERB_APP_ID=$(php -r 'echo random_int(100000, 999999);') \
       REVERB_APP_KEY=$(php -r 'echo bin2hex(random_bytes(10));') \
       REVERB_APP_SECRET=$(php -r 'echo bin2hex(random_bytes(20));') \
       REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
       REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8080
   ```

2. Write the frontend's `.env` as in step 6.
3. Add the `location /app/` block of `deploy/nginx.conf` to
   `/etc/nginx/conf.d/kotak.conf` (Certbot changed that file, so do not copy
   the new one over it), then `sudo nginx -t && sudo systemctl reload nginx`.
4. Run `deploy.sh` as usual.
5. Install the new and changed services as in step 10 (the worker now takes
   the `live` queue first), then restart the worker so it uses its new
   command: `sudo systemctl restart kotak-worker`.

From then on `deploy.sh` restarts Reverb with each deploy
(`php artisan reverb:restart`).

## Logs

Look here when the site shows 502, 500 or a blank page:

```sh
sudo tail -n 50 /var/log/nginx/error.log
sudo tail -n 50 /var/log/php-fpm/www-error.log
tail -n 50 $BACKEND/storage/logs/laravel-*.log
sudo journalctl -u kotak-worker -n 50 --no-pager
sudo journalctl -u kotak-reverb -n 50 --no-pager
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
php artisan queue:clear --queue=live --force
php artisan optimize
sudo systemctl start kotak-worker
```

# Running Kotak locally

The site runs on PHP and Node on your own machine. MySQL 8.4, the version
production runs, comes from Docker, for development and for the tests alike.
The demo data has the accounts and orders listed in the
[README](../README.md#demo_accounts).

## Setup

You need PHP 8.3+ (with the `pdo_mysql`, `fileinfo`, `zip` and `xml`
extensions; the last two read and write Excel files), Composer 2, Node 22.18+
(or 24.11+) and Docker Desktop (on Linux, Docker Engine with the Compose
plugin is enough). Clone both repositories into the same folder:

```bash
git clone https://github.com/karchung0930/kotak-parcel-backend.git
git clone https://github.com/karchung0930/kotak-parcel-frontend.git

cd kotak-parcel-backend
cp .env.example .env                     # port 3306 taken? set DB_PORT and FORWARD_DB_PORT in .env now
docker compose up -d --wait              # MySQL 8.4 on 127.0.0.1:3306
composer setup                           # composer install, app key, migrations
php artisan db:seed --class=DemoSeeder   # the demo accounts and orders listed in the README

cd ../kotak-parcel-frontend
npm ci                                   # the exact versions in package-lock.json
npm run build                            # while editing the pages, run npm run dev in a second terminal instead

cd ../kotak-parcel-backend
composer run dev                         # http://localhost:8000 and a queue listener
```

- **Database.** [`compose.yaml`](../compose.yaml) runs one MySQL 8.4
  container and keeps its data in a Docker volume. It reads `DB_DATABASE`,
  `DB_USERNAME` and `DB_PASSWORD` from `.env`, which is why `.env` comes
  first. On its first start it creates that database and user, and the
  `kotak_testing` database the tests use. It only listens on 127.0.0.1.
  Start it before `composer setup`, which runs the migrations. If port 3306
  is taken, set `DB_PORT` and `FORWARD_DB_PORT` to the same free port in
  `.env` before the first `docker compose up`.
- **Queue.** `composer run dev` already runs `php artisan queue:listen`.
  Without it, run `php artisan queue:work` yourself, or status emails stay in
  the `jobs` table and rate imports wait at Uploaded. The first
  `composer run dev` fetches its process runner through npx (`concurrently`
  on Windows, `@laravel/multiplex` on macOS and Linux), so it needs network
  access, and npx may ask you to confirm.
- **Email.** Locally, emails are written to `storage/logs/laravel.log`
  (`MAIL_MAILER=log`). Follow it with `tail -f storage/logs/laravel.log`
  (`Get-Content storage/logs/laravel.log -Wait` in PowerShell). Where PHP
  has the `pcntl` extension, usually on macOS and Linux, `composer run dev`
  shows the log too, through Pail. Mail addressed only to `.test`
  addresses, which includes every demo account, is skipped because those
  domains cannot exist. To read the demo emails, set
  `MAIL_TO_ADDRESS=you@example.com` in `.env`: every email then goes to that
  address and appears in the log. Or register your own account.
- **Scheduler.** It is only needed for the 9:00 drop-off reminders, the
  nightly clean-up of unclaimed orders and the deletion of uploaded rate
  spreadsheets after a week. Run `php artisan schedule:work` in another
  terminal, or call the jobs directly with
  `php artisan orders:remind-unclaimed`, `php artisan orders:expire-unclaimed`
  and `php artisan rates:prune-imports`.
- **Wayfinder.** The Vite plugin regenerates the route helpers. If they are
  missing, run `npm run build` in the frontend, or from the frontend folder:
  `php ../kotak-parcel-backend/artisan wayfinder:generate --with-form --path=resources/js`.
- **Photos and uploaded rate spreadsheets** go to the private disk, so
  `storage:link` is not needed.
- **Upload size.** Rate spreadsheets may be up to 5 MB, but PHP accepts 2 MB
  by default. Set `upload_max_filesize = 8M` and `post_max_size = 8M` in the
  `php.ini` that `php --ini` names, as the server does in
  [the deployment guide](deploy-aws-ec2.md). Otherwise a file over 2 MB is
  refused as not uploaded.

A clone set up when the project still used SQLite has `DB_CONNECTION=sqlite`
in its `.env`. Replace its `DB_*` lines with the ones in `.env.example`, then
run `docker compose up -d --wait` and
`php artisan migrate --seed --seeder=DemoSeeder`. The old
`database/database.sqlite` is no longer used.

### Stopping and resetting the database

```bash
docker compose stop                      # stop MySQL; the data stays in the volume
docker compose up -d --wait              # start it again

# A MySQL prompt as the app user (the password is DB_PASSWORD in .env)
docker compose exec mysql mysql -ukotak -p kotak

# Fresh tables and demo data, in the same volume
php artisan migrate:fresh --seed --seeder=DemoSeeder

# Start from nothing: remove the container and the volume with all its data,
# then create them again with fresh tables and demo data
docker compose down -v
docker compose up -d --wait
php artisan migrate --seed --seeder=DemoSeeder
```

The database name, user and password are set when the volume is created
(root uses the same password). After changing `DB_DATABASE`, `DB_USERNAME`
or `DB_PASSWORD` in `.env`, `docker compose up -d --wait` reports the
container as unhealthy until you start from nothing as above. A new
`FORWARD_DB_PORT` only needs `docker compose up -d --wait`, which recreates
the container and keeps the volume.

If the tests say `kotak_testing` does not exist, the volume was created
without it. Start from nothing, or keep the data and run the set-up script
again:

```bash
docker compose exec mysql sh -c 'bash /docker-entrypoint-initdb.d/10-create-testing-database.sh'
```

## Tests

```bash
php artisan test                               # PHPUnit feature and unit tests
vendor/bin/pint --test                         # PHP code style
vendor/bin/phpstan analyse --memory-limit=1G   # PHP static analysis

composer test                                  # config:clear, Pint, PHPStan, then the PHP tests
```

The tests run on MySQL 8.4 too, in the `kotak_testing` database
(`phpunit.xml`), so start the container first. They empty and migrate that
database themselves and never touch `kotak`: PHPUnit stops before the first
test if the database name does not end in `_testing`. It also stops if the
config is cached, because the cached values would replace `phpunit.xml`. Run
`php artisan config:clear` first; `composer test` does this for you.

The host, port, user and password come from `.env`. Environment variables
override them, which is how the CI workflow points the tests at its MySQL
service. The database name stays `kotak_testing` whatever the environment
says. For example, to run the tests on another MySQL that listens on port
3307, in bash:

```bash
DB_PORT=3307 php artisan test
```

In PowerShell, run `$env:DB_PORT=3307` first; it stays set until you close
that window.

If MySQL is not running or refuses the login, PHPUnit stops before the first
test and says what to check. The suite takes about a minute and a half with
Docker Desktop on Windows or macOS, where every query crosses into Docker's
virtual machine, and less on Linux.

The PHP tests cover:

- each role's access to its routes and records
- every status transition, including the invalid ones
- pricing: weight bands, routes between zones and volumetric weight, the
  shared price cases the frontend checks too (read from the frontend's
  `tests/js/fixtures`), and the first rate card matching the old formula
- rate cards: drafts, publishing (with every problem listed), scheduling,
  withdrawing, the cache, and which card prices each order
- rate imports and downloads, with workbooks and CSV files written in the
  tests: finding the layout and units, every kind of problem and the cap at
  200, the draft made in one transaction, downloads read back as the same
  card, formula escaping, the jobs' steps and the daily file clean-up
- payments
- proof-of-delivery privacy
- the queued notifications
- the site settings, the drop-off timing figures and the reminder job
- rate limits and security headers
- the indexes behind the busiest pages (MySQL's `EXPLAIN` on a month of
  orders)
- the demo seeder

The feature tests assert the Inertia page and props each screen receives,
and fail if the page component is missing from the frontend repository. The
frontend has its own checks (types, lint, format, helpers and the build); see
its README. GitHub Actions runs both on every push, each with the other
repository checked out beside it; the backend's tests run against a MySQL 8.4
service container.

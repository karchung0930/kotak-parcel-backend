# Running Kotak locally

Two ways to run the site on your own machine: straight on PHP and Node
(quickest for editing), or in a Docker container that mirrors the EC2 server.
Both load the demo accounts and orders listed in the
[README](../README.md#demo_accounts).

## Option A: PHP and Node on your machine

You need PHP 8.3+ (with the `pdo_sqlite` and `fileinfo` extensions), Composer
2 and Node 22.12+. Clone both repositories into the same folder:

```bash
git clone https://github.com/karchung0930/kotak-parcel-backend.git
git clone https://github.com/karchung0930/kotak-parcel-frontend.git

cd kotak-parcel-backend
composer setup                   # composer install, .env, app key, SQLite migrations
php artisan db:seed              # the demo accounts and orders listed in the README

cd ../kotak-parcel-frontend
npm install
npm run build                    # or npm run dev while editing the pages

cd ../kotak-parcel-backend
composer run dev                 # http://localhost:8000, a queue listener and the logs
```

- **Queue.** `composer run dev` already runs `php artisan queue:listen`.
  Without it, run `php artisan queue:work` yourself, or status emails stay in
  the `jobs` table. Locally, emails are written to `storage/logs/laravel.log`
  (`MAIL_MAILER=log`).
- **Scheduler.** It is only needed for the 9:00 drop-off reminders and the
  nightly clean-up of unclaimed orders. Run `php artisan schedule:work` in
  another terminal, or call the jobs directly with
  `php artisan orders:remind-unclaimed` and `php artisan orders:expire-unclaimed`.
- **Wayfinder.** The Vite plugin regenerates the route helpers. If they are
  missing, run `php artisan wayfinder:generate --with-form`.
- **Photos** go to the private disk, so `storage:link` is not needed.


## Tests

```bash
php artisan test                               # PHPUnit feature and unit tests (SQLite in memory)
vendor/bin/pint --test                         # PHP code style
vendor/bin/phpstan analyse --memory-limit=1G   # PHP static analysis

composer test                                  # config:clear, Pint, PHPStan, then the PHP tests
```

Production runs MySQL 8.4, so run the tests on it too. Create an empty
database; the tests migrate it themselves:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=kotak_testing \
  DB_USERNAME=root DB_PASSWORD=secret php artisan test
```

The three query-plan tests read SQLite's query planner, so they skip
themselves on MySQL.

The PHP tests cover:

- each role's access to its routes and records
- every status transition, including the invalid ones
- pricing: weight bands, routes between zones and volumetric weight, the
  shared price cases the frontend checks too (read from the frontend's
  `tests/js/fixtures`), and the first rate card matching the old formula
- rate cards: drafts, publishing (with every problem listed), scheduling,
  withdrawing, the cache, and which card prices each order
- payments
- proof-of-delivery privacy
- the queued notifications
- the site settings, the drop-off timing figures and the reminder job
- rate limits and security headers
- the demo seeder

The feature tests assert the Inertia page and props each screen receives,
and fail if the page component is missing from the frontend repository. The
frontend has its own checks (types, lint, format, helpers and the build); see
its README. GitHub Actions runs both on every push, each with the other
repository checked out beside it.

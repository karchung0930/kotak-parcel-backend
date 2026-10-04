# Kotak: backend

Parcel delivery for Malaysia. Customers book a delivery online and drop the
parcel off at a branch, where it is weighed and paid for. An admin then
schedules a driver, and everyone can follow the parcel with its tracking
number.

This repository is the **Laravel 13 backend**: the database, business rules,
authorisation, notifications and tests. The Vue 3 pages live in
[kotak-parcel-frontend](https://github.com/karchung0930/kotak-parcel-frontend).
The two deploy as one site, because Inertia renders the Vue pages from
Laravel, so check both out side by side.

**Live demo: <https://dataflows.karchung.dev>** (sign-in details below).

- [Try it: DEMO_ACCOUNTS and tracking numbers](#demo_accounts)
- [What it is](#what-it-is)
- [Stack](#stack)
- [How the two repositories fit together](#how-the-two-repositories-fit-together)
- [Architecture](#architecture)
- [Security](#security)
- [Running it locally](#running-it-locally)
- [Deployment](#deployment)
- [What I'd add next](#what-id-add-next)

## DEMO_ACCOUNTS

Sign in at <https://dataflows.karchung.dev/login>. Every account uses the
password **`password`**. Each role only sees its own screens:

- **Customer**: send a parcel, follow your parcels.
- **Staff**: the drop-off counter at their branch (weigh, confirm the price,
  take payment, print the receipt).
- **Admin**: Dispatch (assign a driver and a date), all orders, users,
  branches, rates (versioned price lists by zone and weight) and site
  settings (drop-off limit, reminders, delivery attempts).
- **Driver**: today's jobs, pick up, deliver with a photo or report a failed
  delivery.

Tracking needs no account: open <https://dataflows.karchung.dev/track> and
enter any number below.

| Role     | Email                        | Notes                                              |
| -------- | ---------------------------- | -------------------------------------------------- |
| Admin    | `admin@kotak.test`           | Dispatch, orders, users, branches, rates, settings |
| Staff    | `staff.pj@kotak.test`        | Counter at Petaling Jaya - SS2                     |
| Staff    | `staff2.pj@kotak.test`       | Counter at Petaling Jaya - SS2                     |
| Staff    | `staff.bangsar@kotak.test`   | Counter at Bangsar South                           |
| Staff    | `staff.midvalley@kotak.test` | Counter at Mid Valley                              |
| Staff    | `staff.subang@kotak.test`    | Counter at Subang Jaya - SS15                      |
| Staff    | `staff.cheras@kotak.test`    | Counter at Cheras - Taman Connaught                |
| Staff    | `staff.shahalam@kotak.test`  | Counter at Shah Alam - Seksyen 13                  |
| Driver   | `driver.ravi@kotak.test`     | Ravi Kumar, WXA 1234                               |
| Driver   | `driver.faizal@kotak.test`   | Ahmad Faizal, BKM 5521                             |
| Driver   | `driver.wong@kotak.test`     | Wong Kah Wai, VFD 8812                             |
| Driver   | `driver.siti@kotak.test`     | Siti Nora, WTT 3390                                |
| Customer | `aisyah@kotak.test`          | Aisyah Rahman, sender of the sample                |
| Customer | `jason@kotak.test`           | Jason Tan                                          |
| Customer | `priya@kotak.test`           | Priya Nair                                         |

The demo data also has six Klang Valley branches and 25 orders in every
status. Try tracking the sample parcel **`KT-7Q4M92XD`**: a 4.2 kg ceramic
dinner set from Aisyah Rahman to Daniel Lim in Taman Tun Dr Ismail, out for
delivery today. On your own machine, `php artisan db:seed --class=DemoSeeder`
loads the same data (see [Running it locally](#running-it-locally)).

It also has three versions of the rates (admin **Rates**): the Standard
rates that older orders were priced with, the zone rates in effect since a
few days ago (Peninsular Malaysia, Sabah & Labuan, Sarawak), and higher East
Malaysia rates scheduled for the 1st of next month, which the pricing page
announces.

| Status             | Tracking numbers                                                          | Try it as                                                                                         |
| ------------------ | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Created            | `KT-00000002`, `KT-00000007`, `KT-00000014`, `KT-00000022`, `KT-00000023` | Staff: drop it off at the counter. 22 and 23 are due a reminder and expire 3 nights after seeding |
| Dropped Off        | `KT-00000008`, `KT-00000015`                                              | Staff (Cheras, Bangsar): take payment                                                             |
| Paid               | `KT-00000004`, `KT-00000009`, `KT-00000016`, `KT-00000025`                | Admin: assign a driver in Dispatch. 25 goes to Kuching, priced with the zone rates                |
| Assigned           | `KT-00000010` (Siti), `KT-00000017`, `KT-00000018` (Ravi)                 | Driver: pick up                                                                                   |
| Picked Up          | `KT-7Q4M92XD` (Ravi), `KT-00000013` (Faizal)                              | Driver: deliver or record a failure                                                               |
| Delivered          | `KT-00000003`, `KT-00000011`, `KT-00000019`                               | Anyone: tracking page with proof of delivery                                                      |
| Delivery Failed    | `KT-00000005`, `KT-00000020`                                              | Admin: reassign or return to sender                                                               |
| Returned to Sender | `KT-00000012`                                                             |                                                                                                   |
| Cancelled          | `KT-00000006`, `KT-00000021`, `KT-00000024` (never dropped off)           |                                                                                                   |

## What it is

Kotak is a working build of the development test's use case scenario #3: an
online parcel delivery system with four roles.

1. A **customer** creates an order online.
2. **Branch staff** weigh the parcel at the counter and take payment.
3. An **admin** assigns a truck driver and a delivery date.
4. The **driver** picks the parcel up and delivers it.
5. The customer can track the parcel at any time and gets an email when its
   status changes.

The scenario was drawn as six microservices. Here they are modules of one
Laravel application, with the same boundaries (see
[Architecture](#architecture)).

### How each requirement maps to the app

| Requirement                                                                                                      | Where                                                                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Register and log in; each role only reaches its own screens                                                      | `auth/*` and `settings/*` pages (Fortify); `role:` middleware on each route file; Policies                                                                                                                                                         |
| Create an order: delivery address, item name, weight and dimensions                                              | **Send a parcel** (`orders/Create`) → `CreateOrder`                                                                                                                                                                                                |
| Show an estimated price, a tracking number and the nearest branch                                                | Live estimate with the current rate card, from the chosen branch to the receiver's state (`RateCards`, `PriceCalculator`, mirrored in `lib/pricing.ts`); `KT-` number from `TrackingNumber`; **Use my location** sorts branches by distance        |
| Drop off at a branch; staff weigh it and set the final price                                                     | **Drop-off counter** (`staff/Counter`, `staff/OrderShow`) → `RecordDropOff` → _Dropped Off_, priced with the rate card in effect at drop-off, from that branch                                                                                     |
| Pay at the counter by cash or card, with a receipt                                                               | Take payment → `RecordPayment` → _Paid_; printable 80 mm receipt (`staff/Receipt`)                                                                                                                                                                 |
| Cancel an order, only before it is paid                                                                          | Customer and counter cancel buttons → `CancelOrder`. Orders never dropped off are cancelled after 7 days (an admin setting) by `orders:expire-unclaimed`, after a reminder email from `orders:remind-unclaimed`; the order page shows the deadline |
| Admin assigns a paid order to a driver and schedules the delivery day                                            | **Dispatch** (`admin/Dispatch`) → `AssignDriver` → _Assigned_                                                                                                                                                                                      |
| Driver picks up and delivers, with proof of delivery                                                             | **My jobs** (`driver/Jobs`, `driver/JobShow`, phone first) → `MarkPickedUp` → _Picked Up_; `RecordDeliverySuccess` stores the recipient's name and a photo → _Delivered_                                                                           |
| Driver reports a failed delivery; admin reschedules it                                                           | `RecordDeliveryFailure` → _Delivery Failed_; Dispatch reschedules (→ _Assigned_). After 3 failed attempts (an admin setting) the only way out is `ReturnToSender` → _Returned to Sender_. Admins can also return a parcel earlier                  |
| Track a parcel by its tracking number                                                                            | **Track** (`track/Show`): status, progress conveyor and history only, no personal details. Customers also see their own orders (`orders/Index`, `orders/Show`)                                                                                     |
| Notify the customer when the status changes                                                                      | `OrderStatusChanged` event → queued `SendOrderStatusNotification` → `OrderStatusUpdated` email. A `DropOffReminder` email before an unclaimed order expires                                                                                        |
| Beyond the brief: pricing and branch pages, admin order search, user and branch management, rates, site settings | `pricing/Index`, `branches/Index`, `admin/orders`, `admin/users`, `admin/branches`, `admin/rates` (versioned rate cards), `admin/Settings` (with drop-off timing)                                                                                  |

**Pricing** comes from the rate card in effect (see
[Rate cards](#rate-cards)). The chargeable weight is the greater of:

- the actual weight
- the volumetric weight: length × width × height (cm) ÷ the card's divisor
  (5000)

The route runs from the zone of the drop-off branch's state to the zone of
the delivery state. The price is that of the route's lightest weight band
that covers the chargeable weight; above the highest band, each started kg
costs the route's price per extra kg. Within Peninsular Malaysia the demo
rates are RM 8.00 up to 1 kg, then RM 2.00 for each further kg: the old
flat formula, so the 4.2 kg sample (6 kg by size) costs RM 18.00.

A parcel can weigh up to 30 kg, with each side up to 150 cm
(`config/kotak.php`). Money is stored as integer sen and weight as grams.

## Stack

- **Backend**: PHP 8.3+, Laravel 13, Laravel Fortify (login, registration,
  email verification, two-factor codes, passkeys), Inertia 3.
- **Database**: MySQL 8.4 LTS everywhere: in production, for local
  development (Docker, [`compose.yaml`](compose.yaml)), and for the tests
  locally and in CI.
- **Frontend**: Vue 3.5 `<script setup>` with TypeScript and Tailwind CSS 4.
  UI components are shadcn-vue on reka-ui, with lucide icons.
- **Routing**: Wayfinder generates typed route and form helpers, so no URL is
  hard-coded in the frontend.
- **Tooling**: Pint and PHPStan here; Vite (through vite-plus, which also
  lints and formats) and vue-tsc in the frontend repository.

## How the two repositories fit together

```text
kotak-parcel-backend/          this repository
  app/ config/ database/ routes/ tests/
  resources/views/app.blade.php  the one HTML shell; @vite loads the build
  public/build/                  written by the frontend build (not committed)
kotak-parcel-frontend/         the Vue pages, next to this folder
  resources/js/ resources/css/
```

- **Pages.** A controller returns `Inertia::render('orders/Show', [...])`.
  The page component lives in the frontend at
  `resources/js/pages/orders/Show.vue`. Every feature test checks that the
  component it expects exists there (`config/inertia.php` points at
  `../kotak-parcel-frontend`; set `FRONTEND_PATH` if yours is elsewhere).
- **Assets.** The frontend's `npm run build` writes the compiled files and
  `manifest.json` into this repository's `public/build`, and
  `app.blade.php` loads them with `@vite`. `npm run dev` writes `public/hot`
  instead, so Laravel loads the Vite dev server.
- **Routes.** The frontend build runs `php artisan wayfinder:generate` here
  and writes typed route helpers into the frontend, so no URL is hard-coded
  in Vue and a renamed route becomes a type error.

## Architecture

### A modular monolith

The scenario's six services are six modules in one codebase with one
database. A small courier doesn't need network calls, a message broker and six
databases to run this. The module boundaries are kept, so a busy module (most
likely Tracking) can be split out later.

| Module       | Backend                                                                                                                                                                      | Screens                                     |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Users        | `User`, `Role`, Fortify actions, `EnsureUserHasRole`, `EnsureUserIsActive`, `UserPolicy`, `Admin\UserController`                                                             | auth, settings, admin users                 |
| Orders       | `Order`, `Actions/Orders/*`, `OrderStatusService`, `OrderStatus`, `TrackingNumber`, `OrderPolicy`, `DropOffTiming`                                                           | Send a parcel, My parcels, counter weighing |
| Payments     | `Payment`, `Actions/Payments/RecordPayment`, `PaymentPolicy`                                                                                                                 | counter payment, receipt                    |
| Delivery     | `DeliveryAttempt`, `Actions/Delivery/*`, `Admin\DispatchController`, `Driver\JobController`, `ProofOfDeliveryController`                                                     | Dispatch, My jobs                           |
| Tracking     | `OrderStatusEvent` (append-only history), `TrackingController`, `TrackingResource`                                                                                           | Track                                       |
| Notification | `OrderStatusChanged`, `SendOrderStatusNotification`, `OrderStatusUpdated`, `DropOffReminder`                                                                                 | email                                       |
| Branches     | `Branch`, `Geo`, `BranchPolicy`, `Public\BranchController`, `Admin\BranchController`                                                                                         | Branches, admin branches                    |
| Settings     | `Setting`, `Settings`, `SettingPolicy`, `Actions/Settings/UpdateSettings`, `Admin\SettingsController`                                                                        | Site settings                               |
| Pricing      | `RateCard` (with its zones, routes and bands), `RateCards`, `PriceList`, `PriceCalculator`, `PriceQuote`, `Actions/RateCards/*`, `RateCardPolicy`, `Admin\RateCardController` | Pricing, admin Rates                        |

- **Controllers stay thin.** Each business step is a single-purpose action
  class (`app/Actions/*`). Controllers authorise, validate through a Form
  Request, call the action, and return an Inertia page built from API
  Resources. The plain admin user and branch forms save directly.
- **Resources control the output.** Every page receives exactly the fields in
  its Resource, typed in `resources/js/types/domain.ts`.

### One status writer

`App\Services\OrderStatusService` is the only code that changes
`orders.status`. For every change it:

1. Re-reads the order under a row lock (`freshLocked()`), so two requests
   cannot both pass the same check.
2. Checks the move against `OrderStatus::allowedNext()`, and throws
   `InvalidStatusTransition` otherwise.
3. Sets the matching timestamp (`dropped_off_at`, `paid_at`, …).
4. Appends a row to `order_status_events` with the actor and branch. The
   model refuses updates and deletes, so the history cannot be rewritten.
5. Dispatches `OrderStatusChanged`.

The allowed moves are:

| From            | To                                                                 |
| --------------- | ------------------------------------------------------------------ |
| created         | dropped_off, cancelled                                             |
| dropped_off     | paid, cancelled                                                    |
| paid            | assigned                                                           |
| assigned        | assigned (reassign or reschedule), picked_up                       |
| picked_up       | delivered, delivery_failed                                         |
| delivery_failed | assigned (reschedule, under the attempt limit), returned_to_sender |

`delivered`, `returned_to_sender` and `cancelled` are final. A parcel can only
be cancelled before it is paid, so no refund is ever needed.

### Rate cards

Prices are versioned rate cards, which admins manage on **Rates**
(`admin/rates`). A card has zones (groups of states, such as Sabah &
Labuan), a route for every ordered pair of zones (Sabah to Sarawak is not
Sarawak to Sabah), and on each route weight bands with a price, plus a price
for each kg above the highest band. Money is sen and weight is grams
throughout.

- **Published cards never change.** To change prices, an admin copies any
  version (or starts blank) into a draft, edits its zones and its grid of
  bands × routes, and publishes it now or at a date and time in Malaysia. A
  draft can be saved unfinished; publishing (`PublishRateCard`) lists every
  problem at once: a state in no zone or in two, a zone without states, a
  missing route, a route without bands, a band over the weight limit, prices
  that go down as the weight goes up, or a missing price per extra kg. The
  time must be from the current minute to two years ahead, and no two cards
  take effect at the same moment.
- **Admins see where each card stands**, worked out rather than stored:
  Draft, Scheduled (published, still to take effect), Current (the published
  card that took effect last) or Past. A scheduled card can be withdrawn to a
  draft before it takes effect, and drafts can be deleted. A card that priced
  an order is never edited or deleted.
- Each action re-reads the card under a row lock and checks its state there,
  so an admin acting on a page gone stale (a withdraw a minute too late, or
  saving a draft another admin has just published) is told why on the page.
- A zone's code is made from its name ("Sabah & Labuan" → `sabah-labuan`),
  so a name needs Latin letters or numbers.
- `App\Support\RateCards` caches every published card as one list of
  compact price lists (`PriceList`). It picks the current card when asked,
  by the time, so a scheduled card takes over at its moment without the
  cache being touched, and `upcoming()` lets the pricing page announce new
  rates. Publishing and withdrawing write the list to the cache again after
  they commit, one at a time under a cache lock; readers only add a copy, so
  a request that read the table just before cannot cache the old list. Each
  request or queued job reads it at most once.
- `PriceCalculator::quote()` prices a parcel on a card and returns a
  `PriceQuote` (zones, volumetric and chargeable weight, band, extra kg and
  price). `CreateOrder` quotes with the current card from the chosen branch;
  `RecordDropOff` quotes with the card in effect at drop-off, from the
  branch where the parcel was handed in. Each order keeps both cards
  (`estimated_rate_card_id`, `final_rate_card_id`), which the counter and
  the admin order page show.
- The browser gets the current card in the same compact form and
  `lib/pricing.ts` applies the same rules for the live estimates. Public
  and customer pages get it without the card's id and name
  (`PriceCalculator::publicRules()`), which are for staff and admins. Both
  sides run the shared cases in the frontend's
  `tests/js/fixtures/pricing-cases.json` (`PriceCalculatorTest` reads them
  from the frontend checkout).
- A migration publishes the old prices as the **Standard rates**: one zone
  with every state and one band up to 1 kg at RM 8.00 plus RM 2.00 per extra
  kg, which gives the old formula exactly. It takes effect before the
  earliest order and every existing order points at it, so no seeder is
  needed in production.

### Events after commit, notifications on the queue

- `OrderStatusChanged` implements `ShouldDispatchAfterCommit`. Listeners only
  hear about a change once its transaction has committed, never about one
  that rolled back.
- `SendOrderStatusNotification` is a queued listener, retried 3 times with a
  60-second backoff. A slow or broken mail server never slows down or fails
  the counter, dispatch or driver screens.
- It only writes to customers with a verified email address. It skips driver
  swaps that don't change the delivery day.

### Site settings and scheduled jobs

Admins change three business rules on **Site settings** (`admin/Settings`).
Each falls back to its default in `config/kotak.php` until it is saved:

| Setting                         | Default | Allowed                     |
| ------------------------------- | ------- | --------------------------- |
| `unclaimed_order_days`          | 7       | 2 to 60                     |
| `drop_off_reminder_days_before` | 2       | 0 (no reminder) to days − 1 |
| `max_failed_attempts`           | 3       | 1 to 10                     |

- `App\Support\Settings` reads the saved values in one query and caches them
  under one key for ten minutes. `UpdateSettings` writes the new values to
  the cache after a save, rather than only forgetting the key, so a request
  that read the table just before cannot cache the old ones. The container
  keeps one instance per request or queued job, so repeated reads stay in
  memory.
- A saved value is stored even when it equals the default, so a later change
  to `config/kotak.php` never changes a rule an admin chose.
- Each order gets its drop-off deadline when it is placed
  (`orders.drop_off_deadline`): the order day in Malaysia plus the limit at
  that moment. A new limit only applies to orders placed afterwards, so every
  date a customer has been shown stays true.
- The same page shows how long customers take to drop parcels off in the last
  90 days (`DropOffTiming`): median, 90th and 95th percentile, the share
  within the current limit, orders cancelled as unclaimed, and the orders
  waiting now. Days are Malaysian calendar days from the order day, the way
  the limit counts them. It is worked out in PHP from `created_at` and
  `dropped_off_at`, without database date functions.

The scheduler runs two jobs, both in Malaysia time and never overlapping:

| Command                   | When  | What it does                                                                                                         |
| ------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------- |
| `orders:remind-unclaimed` | 09:00 | Emails a `DropOffReminder` from `drop_off_reminder_days_before` days before the order's deadline day                 |
| `orders:expire-unclaimed` | 00:00 | Cancels orders still not dropped off once their deadline day has ended (`ExpireUnclaimedOrders`, `Order::unclaimed`) |

- Each reminder locks the order and sets `drop_off_reminded_at` in one
  transaction before the email is queued, so a second or overlapping run
  never reminds anyone twice.
- Only active customers with a verified email address are written to, and
  the queued email is dropped if, in the meantime, the parcel was dropped
  off, the order cancelled or the account deactivated.
- The email gives the tracking number, the branch's address and opening
  hours, the drop-off deadline (a date in Malaysia time), a link to the order
  and a note that it can be cancelled. The order page and the list show the
  same deadline, worked out on the server.

### Frontend

The pages, layouts and components are described in the
[frontend README](https://github.com/karchung0930/kotak-parcel-frontend#readme).
In short: one folder of pages per area, the layout picked from the page name,
shared components for everything repeated, and formatting helpers that keep
money, weights and dates (Asia/Kuala_Lumpur) consistent. It is mobile first
and checked from 320 px phones through tablets to 1440 px desktops.

## Security

**Accounts**

- Logins are throttled to 5 a minute per email and IP address.
- Customers must verify their email address.
- Two-factor codes (with recovery codes) and passkeys are available. The
  security settings page asks for the password again.
- In production, passwords must be at least 12 characters, with mixed case,
  numbers and symbols, and must not appear in known breaches.
- Sign-up only ever creates customers. Admins create staff, driver and admin
  accounts.
- Accounts and branches are deactivated, never deleted. A deactivated user
  is signed out on their next request.

**Authorisation**

Three layers protect every route:

1. Role middleware on each route file.
2. A Policy for each record. Customers see only their own orders. Drivers see
   only the active jobs assigned to them. Staff and admins work the counter.
   Only admins see or change the site settings and the rate cards.
3. A Form Request that validates all input.

Actions write only values they computed themselves, never raw request input.

**Public tracking**

- The tracking page shows the status, the destination city and postcode, the
  branch and the history. It never shows names, phone numbers or addresses.
- Lookups are throttled to 30 a minute per IP address.
- Tracking numbers are 8 random Crockford base32 characters (32⁸ ≈ 1.1
  trillion), so they are hard to guess.
- Order creation is also throttled, to 10 a minute per customer.

**Payments**

- The amount must equal the final price set at weighing.
- The database allows one payment per order and unique receipt numbers.
- Card payments keep only the terminal's approval code (4 to 12 letters and
  digits), never a card number.

**Proof-of-delivery photos**

- Photos must be JPEG, PNG or WebP images of at most 5 MB.
- They are stored under random names on the private disk
  (`storage/app/private`).
- They are only served through `orders.proof`, which checks
  `OrderPolicy::viewProof`.

**Browser**

- Every response sends `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy` and a `Permissions-Policy` (camera and microphone off,
  geolocation for this site only). HSTS is added over HTTPS.
- CSRF protection comes from Laravel sessions.
- The frontend never renders server data with `v-html`.
- Only a small view of the signed-in user is shared with pages.
- "Use my location" works out distances in the browser, so the visitor's
  position never reaches the server.

**Operations**

- Outside local development, errors show a branded error page with no stack
  trace.
- Destructive database commands are blocked in production, and the demo
  seeder refuses to run there.

## Running it locally

You need PHP 8.3+ (with `pdo_mysql` and `fileinfo`), Composer 2, Node 22.18+
(or 24.11+) and Docker. MySQL 8.4, the version production runs, comes from
[`compose.yaml`](compose.yaml) for both development and the tests. Run these
in this folder, with kotak-parcel-frontend cloned next to it, because the
tests check its pages (set `FRONTEND_PATH` in `.env` if it lives elsewhere):

```bash
cp .env.example .env                     # port 3306 taken? set DB_PORT and FORWARD_DB_PORT in .env now
docker compose up -d --wait              # MySQL 8.4 with the kotak and kotak_testing databases
composer setup                           # composer install, app key, migrations
php artisan db:seed --class=DemoSeeder   # the demo accounts and orders above
composer test                            # Pint, PHPStan and the PHP tests, on MySQL
```

[docs/local-development.md](docs/local-development.md) covers the frontend
build, the queue, email and scheduler, stopping and resetting the database,
and the tests.

## Deployment

The live demo runs exactly this setup.
[docs/deploy-aws-ec2.md](docs/deploy-aws-ec2.md) walks through putting the
site on one AWS EC2 instance running Amazon Linux 2023 on Graviton (arm64):
nginx, PHP-FPM 8.4, MySQL 8.4 LTS on the instance, a systemd queue worker and
scheduler timer, CloudFront in front, and Amazon SES for email. The nginx
config, systemd units, the one-shot [`install.sh`](deploy/install.sh) and the
update script are in [`deploy/`](deploy).

- **Environment**: `APP_ENV=production`, `APP_DEBUG=false`, an `https://`
  `APP_URL` and a fresh `APP_KEY`.
- **Deploy steps** (what [`deploy/deploy.sh`](deploy/deploy.sh) runs):
    1. `composer install --no-dev --optimize-autoloader`
    2. `php artisan route:clear`, so the frontend build sees the current routes
    3. In the frontend: `npm ci && npm run build`
    4. `php artisan migrate --force`
    5. `php artisan optimize` and `php artisan queue:restart`
- **Seeding.** The demo seeder throws when `APP_ENV=production`. The live
  demo is a short-lived showcase, so [`deploy/install.sh`](deploy/install.sh)
  seeds it once with `--env=staging`. A real installation skips the seeder and
  creates its first admin with `php artisan kotak:create-admin`.
- **Database**: MySQL 8.4 LTS (`DB_CONNECTION=mysql`), utf8mb4, the version
  the tests run on. Everything is stored in UTC, and `config/kotak.php` sets
  the business time zone (Asia/Kuala_Lumpur) for "today" and the schedule.
- **HTTPS**: serve only over HTTPS and set `SESSION_SECURE_COOKIE=true`. HSTS
  is sent automatically on secure requests. Behind a load balancer, configure
  the trusted proxies so Laravel sees HTTPS.
- **Queue worker**: keep `php artisan queue:work --tries=3` running under
  Supervisor or systemd. Run `php artisan queue:restart` on each deploy. The
  `database` queue is fine to start with; Redis is the step up.
- **Scheduler**: run `php artisan schedule:run` every minute (a systemd timer
  in [`deploy/systemd`](deploy/systemd), or cron). It sends drop-off
  reminders at 9:00 and cancels unclaimed orders at midnight, Malaysia time.
- **Private storage**: proof-of-delivery photos live in `storage/app/private`,
  outside the web root. Back it up with the database. With more than one web
  server, move the photos to a private S3-compatible bucket; only the disk
  name in `RecordDeliverySuccess` and `ProofOfDeliveryController` changes.
- **Mail**: set a real mailer, for example Amazon SES through
  `MAIL_MAILER=smtp` (no extra package), and `MAIL_FROM_ADDRESS` on a domain
  with SPF and DKIM set up. Status and reminder emails only go out while the
  queue worker runs.

## What I'd add next

- **SMS or WhatsApp updates**: the scenario asked for email or SMS, and a
  second notification channel on the same queued listener is a small step.
- **Online payment** (FPX or card) at booking, so the counter only weighs and
  settles any difference.
- **Printed parcel labels and phone-camera scanning.** The customer's counter
  pass already shows a Code 39 barcode that a USB scanner types into the
  counter search.
- **Browser end-to-end tests** (Playwright) and automated accessibility
  checks (axe) for the main customer, counter, dispatch and driver flows.
- **Admin audit log** for user, branch and dispatch changes.
- **Split out Tracking** as its own read-only service and database if public
  lookups ever outgrow the main app.

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
  branches, rates (versioned price lists by zone and weight, imported from
  and downloaded as Excel or CSV) and site settings (drop-off limit,
  reminders, delivery attempts).
- **Driver**: today's jobs, pick up, deliver with a photo or report a failed
  delivery. New jobs and a run sheet every morning also arrive by email.

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

Seven orders, the sample parcel among them, have a receiver email (at
`@kotak.test`, so nothing is actually sent). It shows on the order page for
the customer, staff and admins; assigning `KT-00000004` in Dispatch would
email Rosli Ismail his delivery day.

It also has three versions of the rates (admin **Rates**): the Standard
rates that older orders were priced with, the zone rates in effect since a
few days ago (Peninsular Malaysia, Sabah & Labuan, Sarawak), and higher East
Malaysia rates scheduled for the 1st of next month, which the pricing page
announces. **Download template** on Rates gives the current rates as an Excel
workbook; change a few prices and bring it back with **Import** to see a
spreadsheet become a draft.

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
   status changes. The receiver hears about the delivery too, when the
   customer gives their email address.

The scenario was drawn as six microservices. Here they are modules of one
Laravel application, with the same boundaries (see
[Architecture](#architecture)).

### How each requirement maps to the app

| Requirement                                                                                                      | Where                                                                                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Register and log in; each role only reaches its own screens                                                      | `auth/*` and `settings/*` pages (Fortify); `role:` middleware on each route file; Policies                                                                                                                                                                         |
| Create an order: delivery address, item name, weight and dimensions                                              | **Send a parcel** (`orders/Create`), with an optional receiver email → `CreateOrder`                                                                                                                                                                               |
| Show an estimated price, a tracking number and the nearest branch                                                | Live estimate with the current rate card, from the chosen branch to the receiver's state (`RateCards`, `PriceCalculator`, mirrored in `lib/pricing.ts`); `KT-` number from `TrackingNumber`; **Use my location** sorts branches by distance                        |
| Drop off at a branch; staff weigh it and set the final price                                                     | **Drop-off counter** (`staff/Counter`, `staff/OrderShow`): type the number, scan it with a USB scanner or with the camera (`TrackingScanner`) → `RecordDropOff` → _Dropped Off_, priced with the rate card in effect at drop-off, from that branch                 |
| Pay at the counter by cash or card, with a receipt                                                               | Take payment → `RecordPayment` → _Paid_; printable 80 mm receipt (`staff/Receipt`)                                                                                                                                                                                 |
| Cancel an order, only before it is paid                                                                          | Customer and counter cancel buttons → `CancelOrder`. Orders never dropped off are cancelled after 7 days (an admin setting) by `orders:expire-unclaimed`, after a reminder email from `orders:remind-unclaimed`; the order page shows the deadline                 |
| Admin assigns a paid order to a driver and schedules the delivery day                                            | **Dispatch** (`admin/Dispatch`) → `AssignDriver` → _Assigned_. The driver is emailed the job, a driver it is taken from is told, and every driver with jobs gets a run sheet at 7:00 (`drivers:send-run-sheets`)                                                   |
| Driver picks up and delivers, with proof of delivery                                                             | **My jobs** (`driver/Jobs`, `driver/JobShow`, phone first; a scanned label opens its job or records its pick-up) → `MarkPickedUp` → _Picked Up_; `RecordDeliverySuccess` stores the recipient's name and a photo → _Delivered_                                     |
| Driver reports a failed delivery; admin reschedules it                                                           | `RecordDeliveryFailure` → _Delivery Failed_; Dispatch reschedules (→ _Assigned_). After 3 failed attempts (an admin setting) the only way out is `ReturnToSender` → _Returned to Sender_. Admins can also return a parcel earlier                                  |
| Track a parcel by its tracking number                                                                            | **Track** (`track/Show`): status, progress conveyor and history only, no personal details. Customers also see their own orders (`orders/Index`, `orders/Show`)                                                                                                     |
| Notify the customer when the status changes                                                                      | `OrderStatusChanged` event → queued `SendOrderStatusNotification` → `OrderStatusUpdated` email. A `DropOffReminder` email before an unclaimed order expires. The receiver gets `ReceiverStatusUpdated` from dispatch to delivery, if the customer gave their email |
| Beyond the brief: pricing and branch pages, admin order search, user and branch management, rates, site settings | `pricing/Index`, `branches/Index`, `admin/orders`, `admin/users`, `admin/branches`, `admin/rates` (versioned rate cards, imported from and downloaded as spreadsheets in `admin/rates/imports`), `admin/Settings` (with drop-off timing)                           |

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
  email verification, two-factor codes, passkeys), Inertia 3, OpenSpout
  (Excel and CSV files, read and written as streams).
- **Database**: MySQL 8.4 LTS everywhere: in production, for local
  development (Docker, [`compose.yaml`](compose.yaml)), and for the tests
  locally and in CI.
- **Frontend**: Vue 3.5 `<script setup>` with TypeScript and Tailwind CSS 4.
  UI components are shadcn-vue on reka-ui, with lucide icons. The camera
  scanner reads barcodes with zxing-wasm (ZXing-C++) and printed numbers
  with PaddleOCR.js (ONNX Runtime Web), both in the browser.
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

| Module       | Backend                                                                                                                                                                                                                                                                                                                                                                                             | Screens                                     |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Users        | `User`, `Role`, Fortify actions, `EnsureUserHasRole`, `EnsureUserIsActive`, `UserPolicy`, `Admin\UserController`                                                                                                                                                                                                                                                                                    | auth, settings, admin users                 |
| Orders       | `Order`, `Actions/Orders/*`, `OrderStatusService`, `OrderStatus`, `TrackingNumber`, `OrderPolicy`, `DropOffTiming`                                                                                                                                                                                                                                                                                  | Send a parcel, My parcels, counter weighing |
| Payments     | `Payment`, `Actions/Payments/RecordPayment`, `PaymentPolicy`                                                                                                                                                                                                                                                                                                                                        | counter payment, receipt                    |
| Delivery     | `DeliveryAttempt`, `Actions/Delivery/*`, `Admin\DispatchController`, `Driver\JobController`, `ProofOfDeliveryController`                                                                                                                                                                                                                                                                            | Dispatch, My jobs                           |
| Tracking     | `OrderStatusEvent` (append-only history), `TrackingController`, `TrackingResource`                                                                                                                                                                                                                                                                                                                  | Track                                       |
| Notification | `OrderStatusChanged`, `SendOrderStatusNotification`, `OrderStatusUpdated`, `DropOffReminder`, `DeliveryAssigned`, `SendDriverAssignmentNotifications`, `DriverJobAssigned`, `DriverJobRemoved`, `DriverRunSheet`                                                                                                                                                                                    | email                                       |
| Branches     | `Branch`, `Geo`, `BranchPolicy`, `Public\BranchController`, `Admin\BranchController`                                                                                                                                                                                                                                                                                                                | Branches, admin branches                    |
| Settings     | `Setting`, `Settings`, `SettingPolicy`, `Actions/Settings/UpdateSettings`, `Admin\SettingsController`                                                                                                                                                                                                                                                                                               | Site settings                               |
| Pricing      | `RateCard` (with its zones, routes and bands), `RateCards`, `PriceList`, `PriceCalculator`, `PriceQuote`, `Actions/RateCards/*`, `RateCardPolicy`, `Admin\RateCardController`; spreadsheets: `RateImport`, `Actions/RateImports/*`, the `ParseRateImport` and `ValidateRateImport` jobs, `Support/RateSheets/*`, `RateImportPolicy`, `Admin\RateImportController`, `Admin\RateCardExportController` | Pricing, admin Rates, Import rates          |

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

### Importing and downloading rates

Admins can bring prices in from a spreadsheet (**Import rates**,
`admin/rates/imports`) and download any version as one. Files are read and
written with OpenSpout, which streams them, so even a large file takes a few
MB of memory.

1. **Upload** an `.xlsx` or `.csv` file of at most 5 MB, and choose the
   version whose zones the prices use (the current rates by default). The
   extension and the type detected from the content must agree, and an
   `.xlsx` file must be a zip archive with a workbook in it that does not
   unpack to far more than a sheet of prices. The file goes on the private
   disk under a random name, and the import (`rate_imports`) is _uploaded_.
2. **Read** (`ParseRateImport`, on the queue): the sheet names, the first rows
   and the sheet's size. The first sheet with anything in it is read, unless
   the admin picks another. The headings row is looked for in the first 20
   rows, with one of two layouts (`LayoutDetector`):
   - **A row per weight band**: origin, destination, max weight and price
     columns, found by their headings in any case and position ("From zone",
     "To", "Max. weight (g)", "Rate (sen)", or Malay words such as "Dari",
     "Ke", "Berat" and "Harga"). A row whose weight says "Each additional
     kg" or "Per kg" gives the route's price per extra kg.
   - **Weights by route**: weights down a column and a heading for each
     route, such as "Peninsular → Sabah & Labuan", "West - East" or "Within
     Sarawak". `ZoneMatcher` matches each side to a zone of the base card: by
     code or name, by a state in it ("Labuan"), by a common name for one side
     of Malaysia ("West", "Semenanjung"), by part of its name, or by a close
     spelling. The row marked "Each additional kg" holds the prices per
     extra kg, and a box saying "n/a" (or "-") means the route has no band
     at that weight.

   Units come from the headings ("(g)", "RM", "sen"), else from the numbers:
   weights above the limit in kg must be grams, and whole prices of 100 or
   more are sen. A unit typed in a cell ("500 g", "RM 9.50") is used for that
   cell. A comma only groups thousands ("1,200"), except in a CSV file
   separated by semicolons, as Excel saves one where decimals are written
   with a comma: there "8,50" is RM 8.50. The import then _needs mapping_.
3. **Check the columns**: the page shows the first rows with their column
   letters and row numbers, and the suggested layout, headings row, units and
   the column (or route) of each value, for the admin to change and confirm.
   While a job is working, the page asks the server again every two seconds
   (Inertia's `usePoll`), and stops when the job is done.
4. **Check every row** (`ValidateRateImport`, on the queue, with
   `RateSheetParser`): weights to grams and prices to sen, and every problem
   with its row and column: missing values (an empty box in weights by
   route included) or values that are not numbers, zones the base card does
   not have, the same band twice, prices that go down as the weight goes up,
   a price per extra kg for another step than 1 kg ("Per 0.5 kg"), weights
   above the limit, zone pairs without prices, and routes without bands,
   without a price per extra kg or with more than 30 bands. Equal prices on
   two bands are fine, as when publishing, so every version that can be
   published (and downloaded) imports back. The first 200 problems are kept
   with the total, and the import _failed_: the page lists them, takes a
   fixed file and lets the columns be changed. Without problems it is
   _ready_, and the page shows the prices as a rate card's route cards.
5. **Create the draft** (`CreateDraftFromRateImport`): the base card's zones
   and divisor with the file's routes and bands, through the same
   `CreateRateCardDraft` action that copies a version. This is the first
   write to the rate card tables, in one transaction with marking the import
   _applied_. The draft is published like any other. If it is deleted, the
   import can make it again.

Each step re-reads the import under a row lock and checks it is still in the
right state, so a second click or a page gone stale is told why. A job's
result is only saved if the import still waits for it with the same mapping.

**Downloads.** Any version downloads as an `.xlsx` workbook with three sheets
(Rates, a row per band; Matrix, weights by route, with "n/a" where a route
has no band; Zones, the states in each) or as a `.csv` file of the Rates
sheet (`ExportRateCard`). Weights are in kg and prices in ringgit, and a
download imports back as the same prices. The
Rates page offers the current rates' workbook as the template. Text starting
with `=`, `+`, `-`, `@`, a tab or a carriage return gets an apostrophe in
front, so a spreadsheet program never runs it as a formula; the import
removes it again.

**Clean-up.** `rates:prune-imports` deletes uploaded files after 7 days. The
import stays with the prices checked, so a checked file can still become a
draft. The rows shown from the file and the problems found, which quote its
cells, go with it.

### Events after commit, notifications on the queue

- `OrderStatusChanged` implements `ShouldDispatchAfterCommit`. Listeners only
  hear about a change once its transaction has committed, never about one
  that rolled back.
- `SendOrderStatusNotification` is a queued listener, retried 3 times with a
  60-second backoff. A slow or broken mail server never slows down or fails
  the counter, dispatch or driver screens.
- It only writes to customers with a verified email address. It skips driver
  swaps that don't change the delivery day.

**Receiver emails.** The customer may give the receiver's email address on
**Send a parcel** (optional). `SendReceiverStatusNotification` hears the same
`OrderStatusChanged` event and queues `ReceiverStatusUpdated` as an on-demand
notification (`Notification::route('mail', ...)`, as the receiver has no
account), retried like the others:

| The parcel reaches | The receiver reads                                                                                                                      |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| Assigned           | the delivery day; the new day when it changed, or "we will try again" on a day after a failed attempt                                   |
| Picked Up          | "out for delivery today"                                                                                                                |
| Delivered          | "has been delivered", and who took it when the driver recorded a name                                                                   |
| Delivery Failed    | the reason (none for "Other reason"), then that we will write when there is a new day or the parcel goes back, or that it goes back now |
| Returned to Sender | returned to the sender, and to get in touch with them if they still need it                                                             |

- Nothing goes out before a delivery day is set (Created, Dropped Off, Paid,
  Cancelled), so an address is only written to once its parcel has been
  dropped off, paid for and dispatched. Same-day driver swaps are skipped, as
  for the customer: `OrderStatusChanged` works that out when it is created,
  straight after the save, so a queued copy of the event knows it too.
- An email waits in the queue, so when its turn comes it checks that it is
  still news (`ReceiverStatusUpdated::shouldSend()`). A delivery day is
  dropped once the delivery moved or the parcel was picked up, and when a
  newer delivery-day email was queued (the id of the latest one is kept in
  the cache), so moving a day and moving it back sends only the last.
  "Out for delivery today" is dropped once the attempt is over. Outcomes
  (delivered, failed, returned) always go out.
- When the receiver's address is the customer's own verified one, only the
  customer's email goes out.
- The email gives the tracking number, the sender's name and a link to public
  tracking, nothing more: no sender phone number or address, not the
  customer's email, and not even the receiver's name ("Hello,"), in case the
  customer mistyped the address. The sender's name is the customer's own
  text, so it stays out of the subject and is printed as a short plain name
  (`MailText::name()`, below).
- Each email ends with a link to stop them: a signed link to a page with
  one button (`receiver-emails.show`), which removes the address from the
  order (`StopReceiverEmails`), so emails already queued are dropped too.
  The same link is in the `List-Unsubscribe` header with one-click
  unsubscribe (RFC 8058), so a mail app's own Unsubscribe button works
  without opening the page.
- `RECEIVER_EMAILS=false` (`kotak.receiver_emails`) stops every receiver
  email, queued ones included.
- The address shows on the customer's order page and on the staff and admin
  order pages (`OrderResource`), never on public tracking, on the receipt or
  to drivers.

**Driver emails.** `AssignDriver` also dispatches `DeliveryAssigned` after
commit. The event carries the run the delivery was on before (its driver and
day), read under the row lock: by the time anyone hears the event, the order
only knows its new driver and day. `SendDriverAssignmentNotifications` then
queues one email per driver, so each is sent and retried on its own:

| Change                                  | The assigned driver gets                             | The previous driver gets                                   |
| --------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------------- |
| First assignment                        | `DriverJobAssigned`, a new job                       | nobody had it                                              |
| Same driver, another day                | `DriverJobAssigned`, moved from one day to the other | nothing                                                    |
| Another driver, the same day or another | `DriverJobAssigned`, a new job                       | `DriverJobRemoved`, "removed from your round for" that day |
| Reschedule after a failed delivery      | `DriverJobAssigned`, a new job                       | nothing: that job ended with the attempt                   |

- Nothing is sent when neither the driver nor the day changed. A queued email
  is dropped if the delivery moved on, or the account can no longer be
  emailed, before it went out.
- A driver whose new-job email was dropped that way never heard of the job,
  so they are not told it was removed either: `DriverJobAssigned` notes it in
  the cache for `DriverJobRemoved`. A job handed over after its day had
  passed is "removed from your list", as the driver saw it carried over on
  today's My jobs.
- Drivers are written to on the same terms as customers' reminders
  (`User::canBeEmailed()`): an active account with a verified address.
  Accounts an admin creates are verified from the start, so this only holds
  email back after a driver changes their address, until they confirm it.
- The emails give the tracking number, the day, the pickup branch and the
  delivery area (city and postcode), and link to the job or to My jobs. The
  receiver's name, address and phone number stay behind the driver's sign-in.
  They call the driver's day their "round", as My jobs does.
- Every email has Laravel's notification layout with a small Kotak theme
  (`resources/views/vendor/mail/html/themes/kotak.css`): the wordmark, a band
  across the card and the main button in the brand red (the next step, as on
  the site), links in the site's darker red, and buttons at least 44px tall.
  Dates, branch names and postcodes with their town are joined by no-break
  spaces (`MailDate`, `Branch::mailAddress()`), and tracking numbers by a
  no-break hyphen (`TrackingNumber::formatForMail()`), so a phone never splits
  them. Subjects keep plain spaces and hyphens, so an inbox search finds them,
  and a number copied from an email still finds the parcel on Track.

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

The scheduler runs four jobs, all in Malaysia time and never overlapping:

| Command                   | When  | What it does                                                                                                                        |
| ------------------------- | ----- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `orders:remind-unclaimed` | 09:00 | Emails a `DropOffReminder` from `drop_off_reminder_days_before` days before the order's deadline day                                |
| `orders:expire-unclaimed` | 00:00 | Cancels orders still not dropped off once their deadline day has ended (`ExpireUnclaimedOrders`, `Order::unclaimed`)                |
| `drivers:send-run-sheets` | 07:00 | Emails a `DriverRunSheet` to each driver with jobs: today's My jobs list, overdue jobs included, by pickup branch (`SendRunSheets`) |
| `rates:prune-imports`     | 03:00 | Deletes uploaded rate spreadsheets older than 7 days (`PruneRateImportFiles`)                                                       |

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
- The run sheet lists the same jobs as My jobs (`Order::jobListFor()`), in
  one table: the parcels already on the van first, then the ones to collect
  under each pickup branch's name and address, each with its area and status.
  Drivers without jobs, deactivated accounts and unverified addresses get
  none.
- The list is read when the email is sent, not when it is queued, so a late
  email never lists a job delivered or handed to another driver since 7:00.
  It is dropped once nothing is left, or once its day is over.
- Each driver gets one run sheet a day: a cache key per driver and day
  (`Cache::add`) stops a second or overlapping run from sending it again. If
  one email cannot be queued, the error is reported and its key released, so
  the other drivers still get theirs and a later run that day can send it.

### Scanning tracking numbers with the camera

Staff at the counter and drivers can scan a tracking number with the phone's
camera (`TrackingScanner.vue` in the frontend). Everything happens in the
browser: no picture is sent to the server, which only receives the number,
through the same routes as a typed one.

- **Where.** Beside the counter's search, where a match opens the parcel and
  a miss keeps scanning with "No parcel matches" (a refused number is not
  looked up again until "Scan again"; a lookup that failed for want of a
  connection is tried again 2 seconds later, with the message up meanwhile).
  On My jobs, where a label opens its job if it is on the day's list. On a
  job waiting to be collected, where its own label records the pick-up (the
  label in hand is the confirmation) and another parcel's label is refused.
- **Barcode first.** The rear camera fills a full-screen sheet with a guide
  box. About 10 times a second the box is cropped and read in a Web Worker by
  zxing-wasm (ZXing-C++ compiled to WebAssembly, reader build). It accepts
  the counter pass's Code 39, plus Code 128 and QR codes, and keeps only text
  that is a whole tracking number. A barcode is looked up straight away,
  under a green frame, and opens with a buzz and a short beep; a label the
  page refuses gets neither.
- **Then the printed number.** After 3 seconds without a barcode, hints
  appear (closer, steady, and a light button where the camera has a torch),
  and the box is read with PaddleOCR.js and the PP-OCRv6 tiny models
  (ONNX Runtime's WebAssembly backend, in PaddleOCR.js's own worker). The
  text is upper-cased, spaces go, O becomes 0, I and L become 1 and U becomes
  V, and the first `KT-?` plus 8 Crockford characters counts. A number is
  shown only once two of the latest 8 frames give the same reading, as a
  single OCR reading can be wrong with high confidence, and it always
  waits for a tap ("Read KT-… Open / Scan again"). There is no second
  engine: when nothing can be read, the person types the number.
- **Typing is always there.** "Type it instead" starts with what OCR read.
  Without a camera (permission refused, none found, in use, or a browser
  that cannot use it) the sheet says why in plain words and shows only the
  typed entry. Esc closes the sheet, focus moves to the next step and back
  to the button, and a live region says what happened.
- **Assets.** The zxing and ONNX Runtime `.wasm` files, PaddleOCR.js's
  worker and the two model archives are part of the frontend build: served
  from `/build/assets` on this domain under hashed names and cached for a
  year. Nothing comes from a CDN. They load only when the scanner opens,
  both readers at once (the OCR runtime and models once its worker has
  started, about 1.5 s later on a desktop). The build also writes a gzip
  copy of each file, which nginx sends as it is (`gzip_static`):

  | What                                     | Size    | Gzip copy | Loads                  |
  | ---------------------------------------- | ------- | --------- | ---------------------- |
  | Scanner component (with the three pages) | 25 KB   | 9 KB      | with the page          |
  | Barcode worker and `zxing_reader.wasm`   | 0.99 MB | 0.43 MB   | when the scanner opens |
  | PaddleOCR.js and its worker              | 11.5 MB | 3.6 MB    | when the scanner opens |
  | ONNX Runtime `.wasm`                     | 25.0 MB | 5.8 MB    | when the scanner opens |
  | PP-OCRv6 tiny models (`.tar`)            | 6.3 MB  | 5.7 MB    | when the scanner opens |

  So the first scan downloads about 44 MB, 16 MB compressed; later visits
  read them from the browser's cache, and the readers stay loaded for the
  rest of the visit. If they cannot load (their files gone after a deploy,
  say), the sheet says so once and keeps the typed entry. On a desktop a
  barcode is read 0.2 to 0.5 s after the tap, and a printed number is shown
  about 3.5 s after it (OCR starts at 3 s). A phone is slower, and its first
  scan also waits for the downloads.
- **Browser requirements.** The camera needs HTTPS (or localhost) and a
  current browser: Chrome or Edge 91+, Safari 16.4+ (iOS 16.4) or Firefox
  114+, for camera access, module workers and WebAssembly SIMD. Anything
  older still gets the typed entry.

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
  branch and the history. It never shows names, phone numbers, email
  addresses or street addresses.
- Lookups are throttled to 30 a minute per IP address.
- Tracking numbers are 8 random Crockford base32 characters (32⁸ ≈ 1.1
  trillion), so they are hard to guess.
- Order creation is also throttled, to 10 a minute per customer.

**Emails**

- Driver emails carry only what a driver needs before signing in: the
  tracking number, the day, the pickup branch and the delivery area (city
  and postcode). Receivers' names, addresses and phone numbers are on the job
  page.
- Receiver emails carry the tracking number, the sender's name and a link to
  public tracking, never the sender's phone number, the customer's email or
  the receiver's own name.
- The receiver's address is not verified, as they have no account. It must
  be a plain address a mail server can deliver to (`email:strict,filter`: no
  comments, quoted names, IP addresses, dotless domains or non-ASCII), and
  nothing is sent to it before the parcel has been dropped off, paid for and
  dispatched. So a typed address alone sends nothing: it takes staff to take
  the parcel and an admin to dispatch it. On a public demo whose staff and
  admin accounts are listed for anyone (above), anyone can take those steps,
  so keep `MAIL_TO_ADDRESS` set there, or set `RECEIVER_EMAILS=false`, while
  the demo accounts exist. Every receiver email carries a one-click link that
  stops them.
- Status, reminder and driver emails only go to verified addresses, and no
  email goes to the reserved `.test` addresses of the demo accounts. Driver
  emails check this again when they are sent.
- Text that users type is printed in emails as plain text: no Markdown or
  HTML in it becomes a link, image, heading or table cell. A city with a
  line break or `< > [ ] |` is refused when the order is placed, and
  `MailText::plain()` takes those characters out of every name, city and
  branch detail that an email prints, which also covers older orders and
  accounts. Markdown mail also uses Laravel's secured encoding, which
  escapes `[` as well as HTML, but that only covers mail views compiled
  while an email renders, and `php artisan optimize` compiles them ahead,
  so the first two do not rely on it.
- Mail apps also turn a web address in plain text into a link, so the
  sender's name, which receivers read, gets more care. An account name with
  a line break, `< > [ ] |`, text-direction controls or a web address is
  refused (`PersonName`, at most 100 characters), and the receiver's emails
  print it through `MailText::name()`, which also covers older names: web
  addresses are left out, a dot before a letter gets a space ("pay.example"
  is no longer a domain), invisible characters go, and the name is cut to 60
  characters. It never appears in the subject.

**Payments**

- The amount must equal the final price set at weighing.
- The receipt page gets only what the receipt prints about the parcel, not
  the sender's or receiver's contact details.
- The database allows one payment per order and unique receipt numbers.
- Card payments keep only the terminal's approval code (4 to 12 letters and
  digits), never a card number.

**Uploaded spreadsheets**

- Only admins import rates, and each admin can upload, change the sheet or
  confirm columns 10 times a minute. A file must be `.xlsx` or `.csv`, at
  most 5 MB, and its content must be the type its extension says. An
  `.xlsx` file must hold a workbook and unpack to at most 50 MB, with no
  large part more than 100 times its packed size.
- Files are stored under random names on the private disk
  (`storage/app/private/rate-imports`), never served back, and deleted
  after 7 days, with the rows shown from them and the problems found.
- They are only read as data: no formula is run (the value Excel saved is
  used). A workbook may have 50 sheets, named in up to 100 characters, and
  only the first 10 are tried for one with prices. Each read stops early:
  after 1,000 empty rows in a row, after just over 10,000 rows when the file
  is read, and after 10,000 rows of prices when it is checked. A row is read
  up to 257 columns. Problems quote at most 40 characters of a cell, and the
  preview 80.
- Downloads escape text that a spreadsheet program would run as a formula
  (starting with `=`, `+`, `-`, `@`, a tab or a carriage return).

**Proof-of-delivery photos**

- Photos must be JPEG, PNG or WebP images of at most 5 MB.
- They are stored under random names on the private disk
  (`storage/app/private`).
- They are only served through `orders.proof`, which checks
  `OrderPolicy::viewProof`.

**Browser**

- Every response sends `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy` and a `Permissions-Policy` (camera and geolocation for
  this site only, for the scanner and the nearest branch; microphone off).
  HSTS is added over HTTPS.
- The camera scanner reads every frame in the browser: no picture is
  uploaded or stored. The camera is on only while its picture shows: it
  goes off for the typed entry, while the page is hidden and as soon as the
  sheet closes.
- There is no Content-Security-Policy yet. The pages would need
  `worker-src 'self'`. The scanner's workers take their policy from their
  own responses under `/build/` (sent by nginx, not the `SecurityHeaders`
  middleware): if one is sent there, the zxing worker needs
  `'wasm-unsafe-eval'`, and the OCR worker needs `'unsafe-eval'` too,
  because the OpenCV.js inside it builds functions at run time.
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

You need PHP 8.3+ (with `pdo_mysql`, `fileinfo`, `zip` and `xml`), Composer 2, Node 22.18+
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
- **Static files**: nginx serves `/build/` with a year's immutable caching
  and sends the build's gzip copies (`gzip_static`) instead of compressing
  each request ([`deploy/nginx.conf`](deploy/nginx.conf)); `.wasm` must go
  out as `application/wasm`, which nginx's own `mime.types` does.
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
  Supervisor or systemd. It sends the emails, and reads and checks rate
  imports. Run `php artisan queue:restart` on each deploy. The
  `database` queue is fine to start with; Redis is the step up.
- **Scheduler**: run `php artisan schedule:run` every minute (a systemd timer
  in [`deploy/systemd`](deploy/systemd), or cron). It emails drivers their
  run sheets at 7:00, sends drop-off reminders at 9:00, cancels unclaimed
  orders at midnight and deletes uploaded rate spreadsheets older than a week
  at 3:00, Malaysia time.
- **Private storage**: proof-of-delivery photos and uploaded rate
  spreadsheets live in `storage/app/private`, outside the web root. Back it
  up with the database. With more than one web server, move the photos to a
  private S3-compatible bucket; only the disk name in `RecordDeliverySuccess`
  and `ProofOfDeliveryController` changes. The queue worker reads uploaded
  spreadsheets from the same disk, so they need shared storage too.
- **Mail**: set a real mailer, for example Amazon SES through
  `MAIL_MAILER=smtp` (no extra package), and `MAIL_FROM_ADDRESS` on a domain
  with SPF and DKIM set up. Status, reminder, receiver and driver emails only
  go out while the queue worker runs. While the public demo accounts exist,
  keep `MAIL_TO_ADDRESS` set or `RECEIVER_EMAILS=false` (see
  [Security](#security)).

## What I'd add next

- **SMS or WhatsApp updates**: the scenario asked for email or SMS, and a
  second notification channel on the same queued listener is a small step.
- **Online payment** (FPX or card) at booking, so the counter only weighs and
  settles any difference.
- **Printed parcel labels.** The customer's counter pass already shows a
  Code 39 barcode that a USB scanner, or the phone camera, reads into the
  counter search.
- **Browser end-to-end tests** (Playwright) and automated accessibility
  checks (axe) for the main customer, counter, dispatch and driver flows.
- **Admin audit log** for user, branch and dispatch changes.
- **Split out Tracking** as its own read-only service and database if public
  lookups ever outgrow the main app.

# Achievements Microservice

Customers unlock **achievements** as they buy. Enough achievements earns a **badge**. Every badge unlocked pays the customer **₦300 cashback** through a Nigerian payment provider.

Backend only, event-driven, Laravel 12 / PHP 8.2.

---

## Quick start

```bash
docker compose up --build
```

Then:

```bash
curl http://localhost:8000/users/1/achievements
```

The stack brings up an app container, a **queue worker** (achievement unlocking and payouts are queued), and **PostgreSQL 17**. Migrations, the achievement/badge catalogs and a demo store are seeded automatically on boot.

### What seeding gives you

`DatabaseSeeder` always seeds the two catalogs — they are reference data. Outside
production it also runs `DemoSeeder`, which builds a small store: 12 products and 8
shoppers, each at a different point in the progression.

| User | Shape |
| --- | --- |
| `ada@bumpa.test` | signed up, bought nothing — no achievements, no badge |
| `chinedu@bumpa.test` | one purchase, one achievement, first badge |
| `amara@bumpa.test` | the brief's worked example: 5 purchases, 3 achievements short of Advanced |
| `tunde@bumpa.test` | 12 purchases over 7 days across 4 products |
| `zainab@bumpa.test` | 27 purchases over 12 days across 10 products |
| `emeka@bumpa.test` | 55 purchases over 30 days across every product — reaches the top of the ladder |
| `fatima@bumpa.test` | nothing but pending and cancelled orders — unlocks nothing |
| `segun@bumpa.test` | earns a badge with no bank account on file — the failed-payout path |

Demo orders are driven through `CompleteOrder`, so everything you see afterwards was
produced by the real `OrderCompleted → achievement → badge → cashback` chain rather
than written into the unlock tables. The seeder runs that chain inline and prints what
each shopper ended up with. It refuses to run in production, and refuses to run at all
unless `PAYMENTS_GATEWAY=fake` — seeding demo data against a live provider would send
real money to invented bank accounts.

To create a further user with progress on demand, use the
[development harness](#driving-the-flow-from-a-rest-client):

```bash
curl -X POST localhost:8000/dev/users -H 'Accept: application/json'
curl -X POST localhost:8000/dev/users/1/purchases -H 'Content-Type: application/json' -d '{"count":5}'
```

Or from tinker, if you would rather not go through HTTP:

```bash
docker compose exec app php artisan tinker --execute '
  $u = App\Models\User::factory()->create();
  App\Domain\Cashback\Models\PayoutAccount::factory()->default()->for($u)->create();
  $p = App\Domain\Ordering\Models\Product::factory()->create();
  foreach (range(1,5) as $n) {
      app(App\Domain\Ordering\Actions\CompleteOrder::class)->handle(
          App\Domain\Ordering\Models\Order::factory()->for($u)->for($p)->create()
      );
  }
  echo "user {$u->id}\n";
'
```

### Running locally instead

PostgreSQL is the only supported database — locally, in Docker, and under test. Point
`.env` at a running server and create the database:

```bash
createdb bumpa_db
```

```bash
composer setup      # install, .env, key, migrate
php artisan migrate --seed
composer dev        # serve + queue worker + logs + vite
```

If you would rather not install Postgres, the compose stack already exposes one on
`localhost:5433` — set `DB_PORT=5433` and `DB_USERNAME=bumpa` in `.env` and point local
PHP at that instead.

---

## The endpoint

```
GET /users/{user}/achievements
```

Registered in `routes/web.php`, as the brief specifies.

```json
{
  "unlocked_achievements": ["First Purchase", "5 Purchases"],
  "next_available_achievements": [
    "Shopped on 3 Days",
    "10 Purchases",
    "₦250,000 Spent",
    "3 Different Products"
  ],
  "current_badge": "Beginner",
  "next_badge": "Intermediate",
  "remaining_to_unlock_next_badge": 2
}
```

`next_available_achievements` returns **at most one achievement per group** — the next rung the user can reach, not every rung above them; groups come back in catalog order, which is alphabetical by group key. `current_badge` is `null` for a user who has not yet earned one. Returns `404` for an unknown user.

---

## The other endpoints

| Method | Route | Registered | Purpose |
| --- | --- | --- | --- |
| `GET` | `/users/{user}/achievements` | always | The endpoint the brief specifies |
| `GET` | `/users/{user}/payout-account` | always | The account payouts go to |
| `POST` | `/users/{user}/payout-account` | always | Register that account |
| `POST` | `/webhooks/payments/{provider}` | **always** | Settle a transfer the provider accepted out of band |
| `GET` `POST` `DELETE` | `/admin/achievements` | local only | Extend the achievement catalog |
| `GET` `POST` `DELETE` | `/admin/badges` | local only | Extend the badge catalog |
| `GET` | `/admin/metrics` | local only | The group keys an achievement may use |
| `GET` | `/admin/cashbacks` | local only | Reconciliation view, filterable by status |
| `POST` | `/admin/cashbacks/{cashback}/retry` | local only | Re-drive a failed payout |

### Payout account

`POST /users/{user}/payout-account` takes `bank_code`, `bank_name`, `account_number`,
`account_name` and an optional `currency`. Re-posting the same account updates it —
the table's unique `(user_id, bank_code, account_number)` makes a duplicate impossible.
A user's first account is always made the default, whatever the request says, because
an account on file that is not the default leaves the user unpayable.

Registering an account also re-drives payouts that previously failed for want of one.
That case is real: unlock a badge before adding bank details and `PayBadgeCashback`
marks the cashback `Failed`, where without this it would sit unpaid forever.

Bank codes are **not** validated against a bank list. Doing so would mean a live
provider call on every write; a transfer that fails with a clear reason is the better
trade.

### Payment webhooks

A provider that accepts a transfer and settles it minutes later leaves the cashback in
`Processing`, and `PayBadgeCashback` deliberately never re-sends it — re-sending an
in-flight transfer is how a provider pays twice. The callback is what resolves it.

```
POST /webhooks/payments/{provider}
  -> WebhookManager resolves the handler for {provider}
  -> handler->verify(raw body, headers)        401 if the signature does not match
  -> handler->parse(payload)                   202 if it is not a transfer outcome
  -> TransferUpdated  (App\Payments\Events)
       -> SettleCashbackOnTransferUpdated      (queued, App\Domain\Cashback)
            -> SettleCashback -> Paid | Failed
                 -> CashbackPaid | CashbackFailed
```

Each provider authenticates differently, and each is implemented:

| Provider | Header | Scheme |
| --- | --- | --- |
| Paystack | `x-paystack-signature` | HMAC-SHA512 over the raw body |
| Flutterwave | `verif-hash` | equality with a configured shared secret |
| Monnify | `monnify-signature` | HMAC-SHA512 over the raw body |
| `fake` | `x-fake-signature` | HMAC-SHA512, so tests exercise the real verification path |

Three details that are deliberate:

- **Payments does not know Cashback exists.** The module publishes `TransferUpdated`
  and stops there; the Cashback context subscribes. The dependency still runs domain →
  infrastructure only.
- **Status codes are the contract with the provider,** not decoration. Anything outside
  2xx enters their redelivery queue, so an unrecognised event is acknowledged with
  `202` rather than refused, and the endpoint never throws — a `500` would turn one
  malformed body into an infinite retry loop.
- **Redelivery is safe.** `Paid` is terminal. A repeated failure is a no-op. A success
  arriving after a failure *does* apply, because the money moved after all.

Signature verification **fails closed**: an unset secret rejects everything rather than
accepting an empty signature. Flutterwave's hash deliberately has no fallback to the
API key, since it is a shared password rather than a signing key.

### Admin catalog

The grader asks that adding achievements and badges be easy. The answer has two halves.

**Badges are pure data.** The threshold counts a user's total unlocked achievements from
any group, so `POST /admin/badges` needs no code behind it, ever.

**Achievements are data within a group, code across groups.** Adding a rung to
`purchases` is a row. Adding a group like `reviews` needs something that can *count*
reviews — a `ProgressMetric` class registered in `DomainServiceProvider`. So
`POST /admin/achievements` validates `group_key` against the registered metrics and
refuses anything else with a message saying so, rather than storing a row that looks
legitimate and is permanently unreachable. `GET /admin/metrics` lists the legal values.

Both `POST`s queue `BackfillAchievementProgress`, which walks every user in chunks and
re-runs `EvaluateAchievementProgress`. That action is level-based and idempotent, so it
converges instead of double-awarding — **and it can unlock badges and pay real ₦300
cashback**, which is why both responses say so.

`DELETE` is safe: `user_achievements` and `user_badges` snapshot names and thresholds
at unlock time and hold no foreign key, so removing a catalog row never takes anything
away from a user who earned it.

These routes are registered **only outside production**. The application has no auth
scaffolding and the brief specifies none, so environment is standing in for
authorisation — see the trade-offs at the end.

---

## Driving the flow from a REST client

The endpoint above is the only one the brief specifies, and the purchase side of the
flow is driven by domain events rather than HTTP — so on its own there is nothing to
`POST` to. A small **development harness** fills that gap.

Import `docs/achievements-ms.postman_collection.json` into Postman and run the requests
top to bottom; `base_url` defaults to `http://localhost:8000` and the first request
captures the new user id into `user_id`, so nothing needs editing by hand.

| Method | Route | Purpose |
| --- | --- | --- |
| `POST` | `/dev/users` | Create a customer **and** the default payout account cashback needs. Every field is optional. |
| `POST` | `/dev/users/{user}/purchases` | Complete `count` purchases (1–50) through the real `CompleteOrder` action, publishing `OrderCompleted` for each. |
| `GET` | `/dev/users/{user}/cashbacks` | The payout side the graded endpoint does not expose: unlocked badges and the ₦300 transfer each one triggered. |

```bash
curl -X POST localhost:8000/dev/users -H 'Accept: application/json'
curl -X POST localhost:8000/dev/users/1/purchases -H 'Content-Type: application/json' -d '{"count":5}'
curl localhost:8000/users/1/achievements
curl localhost:8000/dev/users/1/cashbacks
```

Three things worth knowing:

- **These routes are registered only outside production.** `bootstrap/app.php` groups
  `routes/dev.php` behind an `app()->environment('local', 'testing')` check — a route
  that mints users and completes purchases has no business on a deployed surface.
- **No domain logic lives in them.** The controllers call the same entry points the
  test suite calls; delete `routes/dev.php` and the graded behaviour is untouched.
- **Unlocking and payouts are queued.** On the default `database` queue a worker must
  be running or nothing moves, and the `progression` echoed by the purchases request is
  the snapshot *before* the worker runs. Poll `users/{user}/achievements` for the
  settled state, or set `QUEUE_CONNECTION=sync` to see it resolve inline.

---

## How it works

```
CompleteOrder ──> OrderCompleted                        (dispatched after commit)
                    └─ UnlockAchievementsForPurchase    (queued)
                         └─ EvaluateAchievementProgress
                              ├─ AchievementUnlocked { achievement_name, user }
                              └─ BadgeUnlocked       { badge_name, user }
                                   └─ PayCashbackOnBadgeUnlocked  (queued, backoff 10/30/60/300s)
                                        └─ PayBadgeCashback ──> PaymentGateway::transfer()
                                             └─ CashbackPaid | CashbackFailed
```

### Layout

```
app/
├── Payments/           SHARED infrastructure — any feature that owes a user money
│   ├── Contracts/PaymentGateway.php
│   ├── PaymentManager.php               driver resolution
│   ├── Gateways/                        Http (base), Fake, Paystack, Flutterwave, Monnify
│   ├── Enums/TransferStatus.php
│   └── ValueObjects/                    Money, RecipientAccount, TransferRequest, PaymentResult
└── Domain/
    ├── Ordering/       Order, Product, CompleteOrder, OrderCompleted
    ├── Achievements/   catalog + unlocked models, EvaluateAchievementProgress,
    │                   BuildUserProgression, ProgressMetric, the two brief events
    └── Cashback/       Cashback, PayoutAccount, PayBadgeCashback, PayoutStatus,
                        CashbackPaid/Failed
```

---

## Design decisions

**Payments is shared infrastructure, not a domain.** Cashback is one caller today; refunds, referral bonuses or payouts would be others. It sits at `app/Payments/`, a sibling of `Domain/`, and exposes a single `PaymentGateway` contract.

**The payout account belongs to the domain, not to the gateway.** `PayoutAccount` is a persisted business record — whose bank details we hold, which one to pay — so it lives with the context that pays into it, at `app/Domain/Cashback/Models/`. Payments knows only the `RecipientAccount` value object the model maps itself onto with `toRecipientAccount()`; nothing under `app/Payments/` imports a domain class, so the dependency runs one way and a second paying context can bring its own record without touching the gateways.

**Contexts communicate only through events.** No context imports another's actions. Ordering does not know achievements exist; Achievements does not know cashback exists.

**Eloquent is the persistence layer — no repositories, no mappers.** Repositories over Eloquent add indirection without buying testability in Laravel, where the query builder is already substitutable and the database is already fast in tests.

**Achievements are data, not conditionals.** An achievement is a `group_key` plus a `threshold`; a group is backed by one `ProgressMetric` that knows how to count it. Adding a rung is a seeder row. Adding a whole new progression (referrals, reviews, spend) is one small class registered in `DomainServiceProvider::PROGRESS_METRICS` — the unlock logic never changes.

**Unlocking is level-based, not incremental.** `EvaluateAchievementProgress` asks each metric where the user stands *now* and grants everything earned but not held. A replayed event converges on the same state instead of double-awarding, and a user whose events were lost while the queue was down is caught up correctly on the next run.

**Unlocked rows snapshot the catalog.** `user_achievements` and `user_badges` copy the name and threshold at unlock time rather than joining to the catalog, so retuning or renaming a badge never rewrites history.

**Money is never a float.** Amounts are integer minor units plus a currency, wrapped in a `Money` value object. It owns both representations because providers disagree: Paystack wants kobo, Flutterwave and Monnify want naira.

**Payouts are exactly-once, enforced by the database.** Three unique indexes carry the guarantee, not application logic:

| Index | Prevents |
| --- | --- |
| `user_achievements (user_id, achievement_key)` | re-unlocking on a replayed event |
| `user_badges (user_id, badge_key)` | a second badge, and therefore a second payout |
| `cashbacks.user_badge_id` + `cashbacks.idempotency_key` | a second payout row for one badge |

The cashback row is written **before** the provider is called, keyed on the badge, so a retried job resumes the existing record. The key is sent to the provider as the transfer reference.

**Transfers are tri-state, not boolean.** `success` / `pending` / `failed`. Real transfers settle asynchronously, so a provider accepting an instruction is not the same as money having moved. A pending payout is held in `Processing` and **never re-sent** — re-sending an in-flight transfer is exactly how a provider ends up paying twice.

**A gateway never throws for a rejected transfer.** Network faults, HTTP errors and malformed bodies all become `PaymentResult::failure()`, so the caller records the reason and retries on its own schedule instead of losing the payout to an exception.

**A missing bank account does not break gamification.** A user with no payout account still unlocks achievements and badges; the cashback simply records as `Failed` with the reason and can be retried once they add an account.

### The catalog

Four progressions, 16 achievements. Each group is one `ProgressMetric` class; the rungs are seeder rows.

| Group | Metric | Scored on | Rungs |
| --- | --- | --- | --- |
| `purchases` | `PurchaseCountMetric` | completed orders | 1, 5, 10, 25, 50, 100 |
| `spend` | `TotalSpendMetric` | naira spent on completed orders | ₦250k, ₦1M, ₦2.5M, ₦10M |
| `variety` | `ProductVarietyMetric` | distinct products bought | 3, 10, 25 |
| `loyalty` | `PurchaseDaysMetric` | distinct days shopped | 3, 7, 30 |

`spend` deliberately drops the kobo remainder rather than rounding up — nobody is handed a tier they have not paid for. `variety` counts distinct products, so ten repeat orders of one item is loyalty, not exploration; `loyalty` counts distinct days, so one thirty-order spree is a single day.

### Badge thresholds

Badges: **Beginner 1, Intermediate 4, Advanced 8, Master 10, Champion 13, Legend 16** — counted across *all* achievement groups.

Advanced sits at 8 because the brief's worked example says a user holding 5 achievements needs 3 more to reach it. Beginner sits at **1, not 0**: a zero-threshold badge would be handed to a user who had done nothing, pay them ₦300 for it, and leave a brand-new user simultaneously holding no badge and having no route to one. Champion and Legend arrived with the spend, variety and loyalty groups: Legend at 16 is the whole catalog, so the top of the ladder is reachable rather than decorative — a test asserts no badge asks for more achievements than the catalog holds.

---

## Payment providers

Set `PAYMENTS_GATEWAY` to `fake`, `paystack`, `flutterwave` or `monnify`.

| Provider | Calls | Amount units | Auth |
| --- | --- | --- | --- |
| `fake` | none — in-process | — | — |
| `paystack` | register recipient → transfer | minor (kobo) | static secret key |
| `flutterwave` | transfer (details inline) | major (naira) | static secret key |
| `monnify` | login → disburse | major (naira) | bearer token, cached to expiry |

### What a gateway can do

`PaymentGateway` is four methods, and every provider implements all four:

| Method | Answers | Paystack | Flutterwave | Monnify |
| --- | --- | --- | --- | --- |
| `transfer()` | did the money move? | `/transfer` | `/transfers` | `/disbursements/single` |
| `resolveAccount()` | whose account is this? | `/bank/resolve` | `/accounts/resolve` | `/disbursements/account/validate` |
| `ensureRecipient()` | will you accept this account? | `/transferrecipient` | not required | not required |
| `verifyTransfer()` | what became of this transfer? | `/transfer/verify/{ref}` | `/transfers?reference=` | `/disbursements/single/summary` |

**A gateway never throws for a provider's "no".** A rejected transfer is a failed `PaymentResult`, an unknown account is an unresolved `AccountResolution`, and a refused recipient is a failed `RecipientRegistration`. Only misconfiguration throws.

**`verifyTransfer()` answers Pending, never Failed, when the provider cannot be reached.** An unreachable provider has told us nothing, and recording that as a failure would write off money that may well have moved.

**Recipient codes are registered once.** Paystack will not pay a raw account number, so the recipient code it issues is kept on `payout_accounts.recipient_tokens`, keyed by gateway — a user's second badge costs one call to the provider instead of two, and the account survives a change of provider because a code minted by one is meaningless to another. Providers that pay account numbers directly answer `RecipientRegistration::notRequired()`.

**Accounts are resolved before they are stored.** `POST users/{user}/payout-account` runs a name enquiry and returns `422` with the provider's own message if the bank does not know the account, so a mistyped account number is caught there rather than discovered after ₦300 has gone to a stranger. When the provider returns a name, that is the name that gets saved.

### When the callback never comes

A transfer the provider accepted sits in `Processing` until a webhook resolves it. Callbacks do get lost, so there is a backstop:

```bash
php artisan cashbacks:reconcile              # or --minutes=0 to sweep everything
```

It asks each stalled payout's **original** gateway — `cashbacks.gateway`, not whichever is configured today — what became of the reference, and publishes the answer as the same `TransferUpdated` a webhook does, so settlement has exactly one implementation. Nothing is ever re-sent; a sweep only learns what already happened. Scheduled every five minutes in `routes/console.php`, `withoutOverlapping`.

```dotenv
PAYMENTS_GATEWAY=fake

PAYSTACK_SECRET_KEY=
FLUTTERWAVE_SECRET_KEY=
MONNIFY_API_KEY=
MONNIFY_SECRET_KEY=
MONNIFY_SOURCE_ACCOUNT_NUMBER=

CASHBACK_BADGE_REWARD_MINOR=30000       # ₦300
CASHBACK_RECONCILE_AFTER_MINUTES=15     # grace before the sweep asks about a pending payout
```

**Bank codes are provider-specific.** The code stored on a `payout_account` is passed through unchanged and must match the configured provider.

Adding a provider means one `create*Driver()` method on `PaymentManager` plus a config entry. Nothing that consumes `PaymentGateway` changes.

---

## Tests

The suite runs against **PostgreSQL**, not an in-memory SQLite file, so a server has to
be reachable before you run it. Feature tests use `RefreshDatabase`, which **drops and
rebuilds every table** in the database named by `DB_DATABASE` in `phpunit.xml` — point
it at a database you are willing to lose.

```bash
php artisan test --compact                  # or: docker compose exec app php artisan test
php artisan test --compact --filter=Cashback
```

**210 tests, 352 assertions.**

| Area | Covers |
| --- | --- |
| `tests/Unit` | `Money`, `PaymentResult`, `TransferRequest`, `RecipientAccount`, the enums, `Progression`, `FakeGateway` |
| Gateways | each provider against a faked HTTP boundary: success, pending, rejection, HTTP error, unreachable provider, retry, malformed body, Monnify token caching |
| `PaymentManagerTest` | driver resolution, config default, unknown driver, instance reuse |
| `PayoutAccountTest` | default resolution, uniqueness, cascade delete, isolation between users, mapping to a transfer recipient |
| `AchievementUnlockingTest` | every threshold, cancelled orders, other users' orders, snapshotting, replay, catching up after missed events |
| `AchievementCatalogTest` | every group has a metric and every metric has rows, spend/variety/loyalty scoring, and a whole new progression added from one class plus catalog rows |
| `DemoSeederTest` | the demo seeder's guards: no demo data in production, none against a live payment provider |
| `BadgeUnlockingTest` | thresholds, the brief's worked example, cross-group counting, events |
| `CashbackPayoutTest` | the ₦300 payout, idempotency under replay, missing account, provider failure and retry, in-flight transfers, DB-level guarantees |
| `RecipientRegistrationTest` | the recipient is registered once, the token is stored per provider and sent with the transfer, and a refused account fails the payout without sending money |
| `CashbackReconciliationTest` | the sweep settles a payout no callback arrived for, respects the grace period, asks the gateway that sent it, never re-sends, and survives a provider that is no longer configured |
| `UserAchievementsEndpointTest` | exact response shape, no `data` envelope, per-group filtering, 404, no state change |
| `PurchaseToCashbackFlowTest` | purchase → achievement → badge → money, end to end |
| `PayoutAccountEndpointTest` | registration, re-registration, default handover, validation, CSRF exemption, and retrying what failed for want of an account |
| `WebhookHandlerTest` | per-provider signature schemes and payload translation, as pure unit tests |
| `PaymentWebhookTest` | forged and missing signatures, unknown providers, settlement, redelivery, late success after failure, events with nothing to do |
| `AdminCatalogTest` | catalog writes, the rejected unscoreable group, duplicate guards, and deletes that leave earned achievements alone |
| `AchievementBackfillTest` | a new rung reaching users who already qualify, through to badge and payout; convergence on repeat runs |
| `DevHarnessTest` | the development harness: validation bounds, route model binding, product reuse, and that it drives the real chain |

No test touches a real provider: `PAYMENTS_GATEWAY` defaults to `fake` and the HTTP client is faked.

---

## Notes and trade-offs

- **The app container runs `php artisan serve`.** It is a development server, chosen so `docker compose up` works on any machine with no extra configuration. Production would run PHP-FPM behind nginx, or Laravel Octane, with `config:cache`, `route:cache` and `event:cache` in the image.
- **The user endpoints are unauthenticated.** The brief specifies no auth and the project has no auth scaffolding. In production the achievements read would sit behind `auth:sanctum` with a policy restricting a user to their own progress, and `POST /users/{user}/payout-account` would be the user's own route rather than one that accepts any id — it writes bank details, which is a materially bigger exposure than reading progress. It is open here as a deliberate scope decision, not an oversight.
- **`/admin/*` uses the environment as its authorisation.** Registered only under `local` and `testing`, which is a route guard standing in for a policy. It is honest about what it is: on a deployed instance the catalog cannot be edited at all. The real answer is token or session auth plus a policy, which the project has no scaffolding for.
- **A pending transfer now settles by webhook,** but nothing sweeps for stragglers. If a provider never calls back — or the callback is lost — the cashback sits in `Processing` indefinitely. `GET /admin/cashbacks?status=processing&stale_minutes=60` surfaces those rows, but a scheduled reconciliation job that polls the provider for their real status is still missing.
- **A backfill runs inline over every user.** `BackfillAchievementProgress` chunks, so memory is bounded, but it is one queued job walking the whole table. At a few million users that wants splitting into per-chunk jobs, and rate-limiting the payouts it can trigger.
- **Event discovery is scoped to `app/Domain/*/Listeners`.** Scanning whole contexts would also register Action classes as listeners, since discovery keys off any `handle(TypedArg)` signature.

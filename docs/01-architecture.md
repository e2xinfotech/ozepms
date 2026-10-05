# OzePMS — Architecture (Phase 0)

E2X Infotech Pvt Ltd. · Version 1 · October 2026

## 1. Stack

| Layer | Choice |
|---|---|
| Backend | Laravel (latest stable), PHP 8.4, Laravel Octane on FrankenPHP |
| Database | MySQL 8.4 LTS (InnoDB, utf8mb4, strict mode) |
| Cache / queue / locks / sessions | Redis, Laravel Horizon |
| Frontend | React 19 + TypeScript, Vite, TanStack Query & Table, Inter |
| Web server | Nginx → Octane |
| Payments | Razorpay |

## 2. Multi-page application (no SPA)

Every screen is a real server route with its own URL and its own full page load.

```
Browser  GET /p/OZ-7K4Q2M/reservations?status=confirmed
   │
Nginx  ──► Laravel route ──► middleware (auth, property, permission, subscription)
   │                              │
   │                       Controller → Service → Query
   │                              │
   │                Blade shell (sidebar, top bar, property switcher)
   │                + page data JSON (escaped) + this page's Vite bundle only
   ▼
React mounts on #page-root and renders the page (tables, calendar, forms)
```

* One Vite entry per page: `resources/js/pages/reservations/index.tsx`. A page downloads only its own code plus shared chunks (React, UI kit), which the browser caches after the first visit.
* The first render needs no extra API call: the controller embeds the initial data in the page.
* Inside a page, actions (save, filter, calendar edit) call same-origin JSON endpoints under `/web-api/...` protected by the session cookie + CSRF token. Filters and pagination are reflected in the URL query string, so Back/Forward, refresh, bookmarks and "open in new tab" all work.
* `/api/v1/...` (token auth via Sanctum) is reserved for the booking engine, mobile apps and integrations. Both use the same Service classes, so business logic lives in one place only.

### Property in the URL
Routes are `/p/{propertyCode}/...`. The top-bar dropdown switches property by navigating to the same page under the other code.
Why the URL rather than only the session: with two browser tabs open on two properties, a session-only design can save a change from tab A into the property selected in tab B. With the code in the URL each tab is always unambiguous. The last used property is remembered (`users.last_property_id`) and opened after login.

### Clean URLs, nothing revealing the technology
* Only `public/` is web-root; Nginx routes everything to the front controller internally. Any request ending in `.php` returns 404; `/index.php/x` is 301-redirected to `/x`.
* `expose_php = Off`, `server_tokens off`, `X-Powered-By` removed; session cookie renamed (`oz_sid`); custom error pages; hashed asset filenames.
* No framework default pages, no debug output in production (`APP_DEBUG=false` enforced by a boot check).

## 3. Tenancy and authorisation

1. `auth` — valid session (or Sanctum token for `/api/v1`).
2. `property` — resolves `{propertyCode}`; verifies an active `property_users` row for this user (cached in Redis 5 min, cleared on membership change). Binds a request-scoped `PropertyContext`. Super Admin may enter any property in read-only "support" mode, which is audit-logged.
3. `subscription` — blocks writes when expired/suspended (read access kept; data never deleted).
4. `can:permission.key` — permission check per route/action.
5. Every tenant model uses a `BelongsToProperty` global scope that adds `property_id = ?` from `PropertyContext`; creating a model without context throws.
6. Composite foreign keys `(property_id, id)` make it impossible in the database for, e.g., a PMS room of property A to point at a room type of property B (verified).
7. Public IDs (ULID / property code) only; internal numeric IDs never leave the server.
8. Automated tests attempt cross-property access on every route.

## 4. Login and password security

* HTTPS only, HSTS (preload). The password travels inside TLS; it is never sent in a URL, never logged, never stored in plain text. (Hashing in the browser adds no protection — the hash simply becomes the password — so we rely on TLS + server hashing, which is the industry standard.)
* Argon2id hashing; automatic rehash when parameters are raised.
* Password policy: min 10 chars, mixed case + number, checked against known-breached passwords.
* Rate limit: 5 attempts/min per email+IP, progressive lockout after 10 failures, all attempts recorded in `login_attempts`.
* Same message for "unknown email" and "wrong password" (no account discovery); constant-time comparison.
* Session ID regenerated at login; cookie `HttpOnly`, `Secure`, `SameSite=Lax`; idle timeout 60 min; "log out other devices".
* Two-factor (TOTP) mandatory for E2X Super Admin and property owners, optional for staff.
* Password re-confirmation before sensitive actions (users, permissions, refunds, tax settings).
* Reset tokens hashed, single use, 60 minutes; e-mail alert on password change and new-device login.
* Logs redact `password`, `token`, `secret`, `otp`, `id_number`, card data.
* Headers: CSP (nonce-based), X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.

## 5. Central database access

* One connection definition (`config/database.php`, values from `.env` only). Read/write split ready (`read` hosts) for later replicas.
* Session settings applied once at connect: `time_zone='+00:00'`, strict `sql_mode`, utf8mb4.
* All queries go through Eloquent/Query Builder inside Services or Query classes. An architecture test fails the build if `PDO`, `mysqli`, or `DB::connection()` appear outside `app/Infrastructure/Database`.
* Transactions use one helper (`Tx::run`) that applies the deadlock-retry policy (3 attempts) and logs every retry.
* Slow-query listener logs anything over 200 ms with the request ID.

## 6. Error logging (every error is logged)

| Source | How |
|---|---|
| PHP exceptions | Central handler in `bootstrap/app.php` reports everything to the `oz` channel (JSON, daily files, 30-day retention) |
| Queue jobs / scheduler | `failed` events logged with job payload (redacted) |
| Browser (React) | Global `error` / `unhandledrejection` handlers + React error boundary → `POST /web-api/client-errors` (rate-limited) → `client` log channel |
| Slow queries | listener → `performance` channel |
| Grouping | Each error is fingerprinted and upserted into `system_error_events` for the Super Admin "System health" screen |

Every log line carries `request_id`, `user_id`, `property_id`, route and IP. Users see a friendly message with a short reference (e.g. `Ref 7KQ2-M4`) that support can search in the logs. Stack traces, SQL and file paths are never shown to users.

## 7. Room / rate model

```
Property
 ├── Room Type  (Deluxe)                        ── PMS Rooms 101, 102, 103 …
 ├── Rate Plan  (Room Only / Breakfast / NRF)   ── meal plan, cancellation policy, payment rule
 └── Product = Room Type × Rate Plan            ── daily price & restrictions, offers, OTA mapping
        manual  → price per date in ari_daily
        derived → parent product ± % / fixed (computed, not stored)
```

* Total inventory of a room type = number of active PMS rooms. If the user enters only a quantity, the rooms are generated (DLX-01 … DLX-05) and can be renamed.
* Out-of-order rooms reduce availability for their dates.
* Every reservation room is assigned a PMS room (auto-suggested, editable).

## 8. Availability algorithm

For room type R, stay [check_in, check_out):

```
nights_found = COUNT(rows)                       must equal number of nights
available    = MIN( LEAST(total_units, sell_limit) − ooo_units − sold − held )
sellable     = available ≥ rooms_requested
               AND no stop_sell (room type level)
For each mapped, active product P of R:
   restrictions from ari_daily (or parent if derived & inherit_restrictions)
   arrival day:   not CTA, not stop_sell, cutoff satisfied, MinLOS-on-arrival ≤ nights
   every night:   not stop_sell, min_los ≤ nights ≤ max_los
   departure day: not CTD
   occupancy:     adults ≤ max_adults, children ≤ max_children, adults+children ≤ max_occupancy
   price per night = base (or parent ± adjustment) + occupancy rules
→ OfferService → TaxService → quote
```
Two indexed queries per search (inventory, then ARI for all products of the property), the rest in memory. Measured on the validation database: inventory range 0.9 ms; ARI range < 1 ms.

Public booking-engine results are cached in Redis under `avail:{property}:{ari_version}:{dates}:{occupancy}`. Any ARI change increments `properties.ari_version`, so stale results are never served and nothing needs purging.

## 9. Reservation concurrency (no double booking)

Inside one transaction:

1. Re-validate the quote (prices may have changed since the guest searched).
2. For each room type, in a fixed order (room_type_id, then date):
   ```sql
   UPDATE inventory_daily SET sold = sold + :rooms
    WHERE room_type_id = :rt AND stay_date >= :in AND stay_date < :out
      AND LEAST(total_units, COALESCE(sell_limit,total_units)) - ooo_units - sold - held >= :rooms
   ```
   If affected rows ≠ nights → rollback, "No longer available".
3. Insert reservation, reservation_rooms, reservation_room_nights.
4. Insert `unit_nights` rows for the assigned PMS room — the primary key `(unit_id, stay_date)` rejects a second booking of the same room on the same night.
5. Folio, audit, commit. Increment `ari_version`.

Safety nets (both verified on MySQL): CHECK `sold + held + ooo_units <= total_units` and the `unit_nights` primary key. A nightly job recounts `sold` from `reservation_room_nights` and alerts on any drift. Every create call needs an `Idempotency-Key` so double clicks / retries never create two bookings.

Booking-engine holds: `held` is incremented the same way with `hold_expires_at` (default 15 minutes); a scheduler job releases expired holds.

## 10. Fast search

| Search | Technique | Measured* |
|---|---|---|
| Booking reference | unique `(property_id, booking_ref)` | < 1 ms (warm) |
| Today's arrivals / departures | `(property_id, check_in, status)` | 1.1 ms |
| Guest phone — last digits ("…4321") | reversed phone column, prefix index | 1.4 ms |
| Guest e-mail prefix | lower-cased generated column + index | 0.5 ms |
| Guest name, partial | ngram FULLTEXT | 70 ms (worst case) |
| Reservation list | keyset pagination on `(property_id, created_at, id)` | 18 ms |
| Room-type availability, 7 nights | clustered PK range | 0.9 ms |

\*300 000 guests and 500 000 reservations, MySQL 8, single small container.

Global search box (Ctrl + K) detects the input type (reference / phone / e-mail / room number / name), runs only the matching indexed query, returns max 8 results per group, debounced 150 ms. Meilisearch can be added later through Laravel Scout without schema changes if needed.

## 11. Dates, time, money

* Timestamps: UTC `DATETIME`. Displayed in the property timezone.
* Stay dates: `DATE` in the property's local calendar, never converted. Check-in/out times are property settings.
* `business_date` per property, advanced by night audit (manual or automatic at a configured hour).
* Money: `DECIMAL(14,2)` amounts, `DECIMAL(14,4)` rates; PHP uses `brick/money`; rounding per currency minor units in one place (`Money\Rounding`). Invoices carry a round-off line.

## 12. GST

* Tax rules are data: country templates + property rules, effective dates, slabs on per-room-per-night tariff, inclusive/exclusive, compound.
* Current India room slabs are seeded as data (0% ≤ ₹1,000; 5% ≤ ₹7,500; 18% above) and can be changed without code.
* Accommodation place of supply = property location → CGST + SGST split. IGST support exists for other services.
* SAC 9963 for accommodation; invoice series per financial year, max 16 characters, gap-free through `property_counters`; invoices immutable; corrections via credit note.

## 13. Soft delete / retention

| Entity | Strategy |
|---|---|
| Reservations, folios, payments, invoices | never deleted; cancel / void / credit note |
| Room types, rate plans, PMS rooms, offers | deactivate; soft delete only when never used |
| Users | disable |
| Guests | anonymise on request (keeps financial history) |
| Daily ARI older than 2 years | moved to archive tables by a monthly job |
| Audit logs | 7 years (monthly partitions) |

## 14. UI direction

Designed as a product, not a template:

* Inter with tabular figures for every number; 13–14 px data text, 8 px spacing grid.
* Calm neutral base (warm greys), one deep accent colour, status colours used only for status.
* Hairline borders and spacing instead of heavy shadows, gradients or glass effects; no decorative illustrations or emoji.
* One icon set (Lucide), one stroke weight.
* Dense, keyboard-friendly tables; sticky headers; inline editing on the calendar.
* Light and dark themes from the same tokens.
* The calendar (room type ▸ rate plans ▸ PMS rooms, collapsible) is the signature screen and is designed first.

A clickable style preview is delivered at the start of Phase 1 for approval before screens are built.

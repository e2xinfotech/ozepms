# OzePMS — Application Structure, Routes & Phases (Phase 0)

## Backend (Laravel)

```
app/
  Domain/                       business rules, no HTTP here
    Property/        Services, Models, Policies, Events
    Accommodation/   RoomTypeService, PhysicalUnitService, AmenityService
    Rates/           RatePlanService, ProductService, CancellationPolicyService
    Inventory/       InventoryService, AriService, CalendarQuery
    Availability/    AvailabilityService, RestrictionEvaluator, Quote (value object)
    Pricing/         PricingService, OccupancyPricer, DerivedRateResolver
    Tax/             TaxService, GstSplitter
    Offers/          OfferService
    Reservations/    ReservationService, UnitAssignmentService, CheckInOutService, HoldService
    Guests/          GuestService, GuestSearch
    Billing/         FolioService, PaymentService, InvoiceService, Razorpay/
    Subscription/    SubscriptionService, FeatureGate
    Search/          GlobalSearch
  Http/
    Controllers/Web/            page controllers (return pages)
    Controllers/WebApi/         JSON for in-page actions (session + CSRF)
    Controllers/Api/V1/         token API (booking engine, mobile, integrations)
    Middleware/                 ResolveProperty, EnsureSubscription, SecurityHeaders, RequestId
    Requests/                   validation per action
    Resources/                  JSON output shapes
  Infrastructure/
    Database/                   Tx helper, connection hooks (only place allowed raw DB access)
    Logging/                    processors (request id, redaction), ErrorFingerprinter
    Cache/                      AriCache
  Support/                      Money, Ulid, PropertyCodeGenerator, PropertyContext
config/  database/migrations  database/seeders  lang/{en,fr,it,de}
resources/views/layouts/app.blade.php     (shell: sidebar, top bar, switcher)
routes/web.php  routes/web-api.php  routes/api.php
tests/Unit  tests/Feature  tests/Security  tests/Concurrency  tests/Arch
```

## Frontend (React, one entry per page)

```
resources/js/
  pages/                         one folder per screen, each index.tsx is a Vite entry
    dashboard/  reservations/{index,show,create}  calendar/  room-types/{index,edit}
    rate-plans/  guests/  settings/  admin/properties/ …
  components/ui/                 Button, Input, Select, DateRangePicker, Table, Drawer, Modal, Toast, Badge …
  components/domain/             CalendarGrid, RatePlanPicker, OccupancyInput, PriceBreakdown …
  lib/                           http (CSRF, error reporting), money, dates, i18n, page-data
  styles/                        tokens.css (colours, spacing, type), base.css
  boot.tsx                       mounts the page component, installs error handlers
```

## Route map (pages)

| URL | Page | Permission |
|---|---|---|
| `/login`, `/two-factor`, `/forgot-password` | auth | public |
| `/properties` | choose / create property | auth |
| `/p/{code}/dashboard` | dashboard | property.view |
| `/p/{code}/calendar` | rates & availability | calendar.view |
| `/p/{code}/reservations` · `/new` · `/{ref}` | list / create / detail | reservations.* |
| `/p/{code}/front-desk` | arrivals, in-house, departures, tape chart | checkin.perform |
| `/p/{code}/guests` · `/{id}` | guests | guests.view |
| `/p/{code}/setup/room-types` · `/rate-plans` · `/amenities` · `/taxes` · `/offers` | configuration | rooms.* / rate_plans.* … |
| `/p/{code}/settings/*` | property, users, roles, billing | property.update / users.manage |
| `/admin/*` | E2X Super Admin | platform permissions |

In-page JSON: `/web-api/p/{code}/...`. Token API: `/api/v1/...` (same services).

Response format (JSON):
```json
{ "data": {...}, "meta": {"cursor": "…"} }
{ "error": {"code": "NOT_AVAILABLE", "message": "…", "fields": {"check_in": ["…"]}, "ref": "7KQ2-M4"} }
```
Status codes: 200/201, 401 auth, 403 permission, 404 (also for other-property IDs — never 403, to avoid revealing existence), 409 availability/conflict, 422 validation, 429 rate limit, 500 with reference only.

## Phases (each ends with tests + your sign-off before the next starts)

| # | Phase | Delivers | Sign-off test |
|---|---|---|---|
| 0 | Architecture | this document set + validated schema | your approval |
| 1 | SaaS foundation | project skeleton, Nginx/Octane config, secure login + 2FA, users/roles/permissions, property create + switcher, Super Admin (properties, plans, subscriptions), audit log, error logging, UI design system + style preview, i18n | cross-property access tests, login brute-force tests, error-logging test |
| 2 | Accommodation & rates | property configuration, room types + PMS rooms (bulk), amenities, meal plans, cancellation policies, rate plans, product mapping (manual/derived, occupancy), onboarding wizard, tax rules + GST seed | relationship & permission tests, onboarding run-through |
| 3 | Inventory & calendar | inventory_daily / ari_daily horizon jobs, calendar (room type ▸ rate plan ▸ PMS rooms), single/range/bulk edits, out-of-order blocks, AvailabilityService, PricingService, TaxService | load test with large data, search under 100 ms p95 |
| 4 | Reservations & billing | search → quote → book, multi-room, PMS room assignment & moves, edit/cancel, check-in/out, front desk, guests, global search, folio, payments, Razorpay, GST invoices & credit notes, night audit | concurrency test (50 parallel attempts on last room → exactly 1 success), invoice checks |
| 5 | Offers | OfferService, promo codes, windows, priority/stacking | rule matrix tests |
| 6 | Booking engine | public property site, holds, Razorpay checkout, confirmation e-mail | end-to-end booking, cached search speed |
| 7 | Reports | stats_daily rollups, occupancy, ADR, RevPAR, pickup, source, cancellations, GST reports | figures reconcile with folios |
| 8 | Channel manager | connections, mappings, delta sync, reservation import, retries, logs | one OTA certified |

# OzePMS — Development Guide

Read this before changing code. It explains how the application is put together, the
conventions every module follows, and which part of the code each module owns.
The architecture and database decisions are in `01-architecture.md` and `02-database.md`;
the approved screen designs are in `docs/design/` (see `docs/design/README.md`).

---

## 1. Ground rules

1. **Follow the approved schema.** Tables are defined in `database/schema/ozepms_schema_v1.sql`
   and created by the phase migrations in `database/migrations/2026_10_0X_*`. If a design needs a
   new column, add it in a *new* migration file of your phase (`2026_10_0X_00000N_add_..._to_...`)
   and note it in `docs/02-database.md`. Never edit another phase's migration.
2. **Tenant safety first.** Every property-owned model uses `BelongsToProperty`. Never query a
   tenant table with `withoutGlobalScope('property')` unless the code is platform-only, and say why
   in a comment.
3. **Business logic lives in services** (`app/Domain/<Module>/*Service.php`). Controllers validate,
   call one service method and shape the response. No logic in controllers, React pages or models.
4. **One way to do each thing** — transactions via `Tx::run()`, audit via `AuditLogger`, permissions via
   `can.do:` middleware / `AccessService`, money via `App\Support\Money` (Phase 2+), dates via the
   property timezone, HTTP calls from pages via `lib/http.ts`, formatting via `lib/format.ts`.
5. **Everything centralised:** colours/fonts/spacing in `resources/css/tokens.css`; shared components in
   `resources/js/components/ui`; icons registered in `components/ui/Icon.tsx`; status colours in
   `lib/status.ts`; text in `lang/{en,fr,it,de}/*.php`; menu in `config/navigation.php`;
   permissions in `config/permissions.php`; app settings in `config/ozepms.php`; DB access only through
   the default connection.
6. **Code style:** PSR-12 PHP, strict TypeScript. Comments explain *why*, briefly, in plain English.
   No mention of tools, assistants or generators anywhere in code, comments, commit messages or docs.
7. **Never hard-delete** reservations, folio lines, payments, invoices or audit records. Deactivate,
   cancel, void or credit instead.

## 2. Request flow (multi-page app)

```
GET /p/P1001/room-types?status=active&page=2
  → web.php route (middleware: auth, two-factor, property, can.do:rooms.view)
  → Web\Property\RoomTypesController@index
       - reads filters from the query string
       - calls RoomTypeQuery / service for one page of rows (server-side filter + paginate)
       - return Page::render('property/room-types/index', [...props], __('nav.room_types'))
  → resources/views/page.blade.php embeds props as JSON + loads resources/js/pages/property/room-types/index.tsx
  → the page calls createPage(Component) which renders the shared AppLayout (sidebar + top bar)

In-page actions (save, delete, open side panel):
  fetch → /web-api/p/P1001/room-types/{publicId}   (routes/web-api.php, same middleware + 'subscription')
  → WebApi\Property\RoomTypesController@update → FormRequest → RoomTypeService::update → JSON
```

* Filters, tabs, sorting and pagination are **query-string driven** (`navigateWithQuery()` in
  `lib/http.ts`) so Back/Refresh/bookmarks work. Lists are always paginated on the server.
* Side panels (detail on the right) load their data from a `GET /web-api/...` endpoint when a row is
  clicked, and remember the selection in the URL as `?selected=<publicId>`.
* Forms post JSON with `http.post/put` and show field errors from `ApiError.field(name)`.

### Route & file naming

| Kind | Location / name |
|---|---|
| Page route | `routes/web.php`, inside the `p/{property}` group, name `property.<module>[.<action>]` |
| JSON route | `routes/web-api.php`, inside the `p/{property}` group, name `webapi.property.<module>.<action>` |
| Page controller | `app/Http/Controllers/Web/Property/<Module>Controller.php` |
| JSON controller | `app/Http/Controllers/WebApi/Property/<Module>Controller.php` |
| Validation | `app/Http/Requests/Property/<Action><Thing>Request.php` |
| Service | `app/Domain/<Module>/<Thing>Service.php` |
| Read/query objects | `app/Domain/<Module>/Queries/<Thing>Query.php` (filtering + pagination) |
| JSON shape | `app/Http/Resources/<Thing>Resource.php` |
| React page | `resources/js/pages/property/<module>/<page>.tsx` |
| Page-only components | `resources/js/pages/property/<module>/_components/*.tsx` (folders starting with `_` are not entries) |
| Translations | `lang/en/<module>.php` (+ fr, it, de with the same keys) |
| Tests | `tests/Feature/<Module>/*Test.php`, `tests/Unit/<Module>/*Test.php` |

Each route group is added to `routes/web.php` / `routes/web-api.php` **inside a clearly marked block
for the module**:

```php
// --- Module: room-types (Phase 2) -------------------------------------------
...
// --- End module: room-types ---------------------------------------------------
```

## 3. Pagination contract

List endpoints/pages return:

```json
{ "rows": [...], "meta": { "page": 1, "per_page": 10, "total": 156, "last_page": 16 }, "counts": { "all": 156, "active": 140 } }
```

Use `App\Support\Listing::paginate($query, $request)` (see `app/Support/Listing.php`) which applies
`page`, `per_page` (allowed values from `config('ozepms.pagination.options')`) and returns this shape.
The React side uses `<DataTable>` + `<Pagination meta={meta}>`.

## 4. UI building blocks (use these, do not restyle)

`resources/js/components/ui`:
`Button, LinkButton, Icon, Input, Select, Textarea, Checkbox, Toggle, Field, FormSection, Badge, Avatar,
Flag, KpiCard, Change, Card, PageHeader, EmptyState, KeyValue, Alert, Progress, Tooltip, Stars,
PillTabs, Tabs, Segmented, Stepper, DataTable, Pagination, Modal, Drawer, ConfirmDialog, SidePanel,
Dropdown, RowMenu, ToastProvider/useToast/toast`. Charts: `components/charts/Charts.tsx` (`BarLineChart`, `Donut`).

Layout patterns (match `docs/design`):

* **List page:** `PageHeader` (title, description, actions) → KPI row (`kpi-row` of `KpiCard`) →
  filter bar (`.filter-bar`: search `Input icon="search"`, `Select`s, More Filters) → `PillTabs` with
  counts → `DataTable` → `Pagination`.
* **Master–detail:** wrap the page content in `<div className="content with-panel">` with the list in
  `<div className="content-main">` and a `<SidePanel>` on the right.
* **Forms:** group fields into `FormSection` blocks; inside, use the 12-column grid with
  `fieldClass="span-4"` etc. Every field has a label; optional fields pass `optional`. Long forms end with
  a sticky `.form-footer` (Save as Draft · Cancel · primary action).
* **Fixes over the screenshots** (always apply): single 40px control height, labels above fields,
  aligned rows, one font (Inter), tabular numbers, money right-aligned, every icon/colour has a tooltip
  and a `title`.

## 5. Back-end building blocks

| Need | Use |
|---|---|
| Current property | `app(PropertyContext::class)->property()` (or inject `PropertyContext`) |
| Permission check | route middleware `can.do:rooms.update`, or `$access->allows($user, 'rooms.update')` |
| Transaction | `Tx::run(fn () => ...)` |
| Audit entry | `$audit->log('room_type.updated', $model, $audit->diff($model))` |
| Public id | model `use HasPublicId;` (ULID in `public_id`, used for route binding) |
| Page render | `Page::render('property/x/index', $props, $title)` |
| Option lists | `App\Support\Lookups` (add new static methods for your module) |
| Errors | throw `ValidationException::withMessages([...])` for user errors; anything else is logged automatically |
| Money | `App\Support\Money` (add in Phase 2: brick/math-free decimal helpers using `bcmath`, rounding per currency `minor_units`) |

Never return internal ids; resources expose `public_id` as `id`.

## 6. Module ownership (parallel development)

| Module / phase | Owns (may create/modify) | Uses read-only |
|---|---|---|
| **Foundation / Phase 1** | `app/Support/*`, `app/Infrastructure/*`, `app/Domain/{Auth,Access,Audit,Users,Property,Subscription,Platform}`, `app/Http/Middleware/*`, `resources/js/{lib,components}/*`, `resources/css/*`, `resources/js/pages/{auth,account,onboarding,admin}/*`, `resources/js/pages/property/{dashboard,users,settings,properties}/*`, `lang/*/{ui,nav,errors,auth,mail,users,roles,property,subscription,admin,permissions,property_type}.php`, `config/*`, `bootstrap/*` | — |
| **Phase 2 — accommodation & rates** | migrations `2026_10_02_*`; models `RoomType, RoomTypeBed, RoomTypeImage, PhysicalUnit, UnitBlock, Amenity, BedType, MealPlan, CancellationPolicy, CancellationPolicyRule, RatePlan, Product (table room_type_rate_plans), ProductOccupancyRule, TaxCategory, TaxRule, TaxRuleScope, ContentTranslation`; `app/Domain/{Accommodation,Rates,Tax}`; `app/Support/Money.php`; pages `property/{room-types,rooms,rate-plans,taxes,amenities}`; onboarding wizard steps for rate plan / room types; `lang/*/{rooms,rates,taxes,amenities}.php` | Phase 1 code |
| **Phase 3 — inventory & calendar** | migrations `2026_10_03_*`; models `InventoryDay, AriDay, AriDayOccupancy, AriChangeLog`; `app/Domain/{Inventory,Availability,Pricing}` (`InventoryService, AriService, AriCopyBuilder, InventoryArchiver, AvailabilityService, PricingService, RestrictionEvaluator, CalendarQuery, CalendarYearQuery`); scheduled horizon and archive jobs; page `property/calendar`; `lang/*/calendar.php` | Phase 1–2 models (by class name above) |
| **Phase 4 — reservations & billing** | migrations `2026_10_04_*`; models `BookingSource, Guest, Reservation, ReservationRoom, ReservationRoomNight, UnitNight, ReservationGuest, ReservationStatusHistory, Service, Folio, FolioLine, FolioLineTax, Payment, PaymentGatewayEvent, Invoice`; `app/Domain/{Reservations,Guests,Billing}`; pages `property/{reservations,guests,front-desk}`; global search endpoint; `lang/*/{reservations,guests,billing}.php` | Phase 1–3 services (`AvailabilityService::search()`, `PricingService::quote()`, `TaxService::calculate()`, `InventoryService::reserve()/release()`) |
| **Phase 5 — offers** | migrations `2026_10_05_*`; models `Offer, OfferScope, OfferCondition, OfferApplication`; `app/Domain/Offers` (OfferService, OfferAdminService, OfferContext, OfferResult, Queries/*); listener `Listeners/Offers/ReleaseOfferRedemptions`; pages `property/offers/*`; `lang/*/offers.php`; `OfferDemoSeeder` | Pricing interfaces; `StayPricer` applies offers; `ReservationService` freezes them (offer_applications) |
| **Phase 6 — booking engine & API** | `routes/booking.php`, `routes/api.php`; `app/Domain/BookingEngine` (BookingEngineService, BookingPresenter); `app/Domain/Api/ApiKeyService`; `Http/Controllers/Booking/*`, `Http/Controllers/Api/V1/*`, `WebApi/Property/{BookingEngineSettingsController, ApiKeysController}`; middleware `AuthenticateApiKey`; `Http/Requests/Booking/*`; model `ApiKey`; command `booking:expire-holds`; pages `booking/*` (layout `booking`); `components/booking/*`, `components/property/{BookingEngineCard, ApiKeysCard}`; `css/modules/booking.css`; `lang/*/booking.php`; docs `07-api.md` | Uses `AvailabilityService`, `StayPricer`, `OfferService`, `ReservationService`, `PaymentService` unchanged in role; never duplicates their rules |
| **Phase 7 — reports** | migration `2026_10_07_000002` (`stats_daily_mix`); `app/Domain/Reports` (ReportRollupService, ReportService, ReportFilter, ReportCatalog, ReportPresenter, Report, Queries/{StayReports, BookingReports, LedgerReports}); listener `Listeners/Reports/RefreshReportStats`; command `reports:refresh`; middleware `plan.feature` (`RequirePlanFeature`); `Http/Requests/Reports/ReportRequest`; controllers `Web/Property/ReportsController`, `WebApi/Property/ReportsController`; routes `property/reports.php`, `property-api/reports.php`; pages `property/reports/{index, show}`; `components/reports/*`; `css/modules/reports.css`; `lang/*/reports.php` | Reads rollups and the ledger only; never writes booking or billing tables |
| **Phase 8 — channel manager** | migrations `2026_10_08_*`; models `ChannelConnection, ChannelRoomMapping, ChannelRateMapping, ChannelSyncLog, ChannelReservation`; `app/Domain/Channels` (ChannelRegistry, ConnectionService, ChannelSyncService, ChannelReservationService, AriSnapshot, ChannelPresenter, TestChannelService, Contracts/ChannelProvider, Providers/TestChannelProvider); webhook `Hooks/ChannelWebhookController`; commands `channels:sync`; controllers `Web/Property/ChannelsController`, `WebApi/Property/ChannelsController`; routes `property/channels.php`, `property-api/channels.php`; pages `property/channels/{index, show}`; `css/modules/channels.css`; `lang/*/channels.php`. A new OTA = one class implementing `ChannelProvider` registered in `config/channels.php`; `Providers/BookingComProvider` (OTA XML, polling, machine account) and `Providers/AgodaProvider` (YCS XML, OAuth token, polling) and `Providers/MakeMyTripProvider` (provisional, unverified wire format) exist but stay switched off until `OZ_CHANNEL_BOOKING_COM=true` / `OZ_CHANNEL_AGODA=true` (needs OTA sandbox access and certification; see `.handoff/NEXT-SESSION.md` section 3f) | Reads `ari_change_log`, `PricingService`, `ReservationService` (bookings in/out) |
| **Platform levels, impersonation, approvals, audit** | migrations `2026_10_10_*` (roles split, `audit_logs.impersonator_id`, `request_trail`, `approval_requests`, plan and channel approval columns); `app/Domain/Users/PlatformHierarchy` (who may change whom), `app/Domain/Platform/{ImpersonationService, ApprovalService}`, `app/Domain/Channels/{ConnectionApprovalService}`; middleware `GuardImpersonation` (expiry, blocked account-security routes) and `RecordRequestTrail` (one row per write request, field names only); command `user:create-super-admin`; notifications `Approval{Requested,Decided}Notification`; controllers `Web/Admin/ApprovalsController`, `WebApi/Admin/ApprovalsController`, `WebApi/ImpersonationController`, `Web/Property/PendingApprovalController`; pages `admin/approvals`, `property/pending-approval`; component `components/platform/ImpersonateDialog`; `lang/*/{approvals,impersonation}.php` | Uses `AccessService`, `AuditLogger`, `PropertyService`, `PlanService`, `ConnectionService`. Rules: Super Admin > Admin > IT Support; Admins cannot manage Admins/Super Admins; nobody impersonates a Super Admin; property staff cannot hand out rights they do not hold |
| **Housekeeping** | migration `2026_10_09_000001` (`housekeeping_staff`, `housekeeping_tasks`, `physical_units.housekeeping_staff_id`); models `HousekeepingStaff, HousekeepingTask`; `app/Domain/Housekeeping` (HousekeepingService, HousekeepingPresenter); listener `Listeners/Housekeeping/OpenCleaningTasks` (on check-out); notification `CleaningRequestNotification`; command `housekeeping:notify` (every minute); permission `housekeeping.manage`; controllers `Web/Property/HousekeepingController`, `WebApi/Property/HousekeepingController`; routes `property/housekeeping.php`, `property-api/housekeeping.php`; page `property/housekeeping/index`; `lang/*/housekeeping.php` | `PhysicalUnitService::setHousekeeping` opens / closes tasks |

Cross-module contracts (method signatures agreed up front — implement exactly these):

```php
// Phase 2 — App\Domain\Tax\TaxService
public function calculate(Property $property, array $lines, ?string $guestStateCode = null): TaxBreakdown;
//   $lines: [['category' => 'accommodation', 'amount' => '4500.00', 'date' => '2026-10-04', 'unit_night_tariff' => '4500.00', 'room_type_id' => 1, 'rate_plan_id' => 2, 'nights' => 1, 'persons' => 2], ...]

// Phase 3 — App\Domain\Availability\AvailabilityService
public function search(Property $property, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, int $children, int $infants, string $channel = 'pms'): AvailabilityResult;
//   AvailabilityResult->roomTypes: [{room_type, available_units, products: [{product, sellable, reasons[], nightly: [{date, price}], total}]}]

// Phase 3 — App\Domain\Pricing\PricingService
public function quote(Product $product, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, array $childAges): Quote;  // nightly breakdown + room total (before offers & tax)

// Phase 3 — App\Domain\Inventory\InventoryService (called inside Phase 4's transaction)
public function reserve(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void;   // throws NotAvailableException
public function release(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void;
public function syncUnitCount(int $roomTypeId): void;  // called by Phase 2 when PMS rooms are added/removed/deactivated

// Phase 5 — App\Domain\Offers\OfferService (rooms: key => room_type_id, rate_plan_id, check_in, check_out, adults, nights [date => price], fixed [dates])
public function evaluate(Property $property, OfferContext $context, array $rooms): OfferResult;   // context: booked on, channel pms|booking_engine, promo, source, guest country
public function redeem(array $offerIds): void;   // inside the booking transaction, guarded by max_redemptions
public function release(array $offerIds): void;  // cancelled booking

// Phase 6 — App\Domain\BookingEngine\BookingEngineService (public booking engine and /api/v1)
public function search(Property $property, array $query): array;  // rooms + rate plans that pass every rule (availability, restrictions, min/max stay, occupancy), with offers, tax lines, policy lines
public function book(Property $property, array $data): array;     // ['reservation', 'payment', 'due'] via ReservationService::create (channel booking_engine)

// Phase 7 — App\Domain\Reports
// ReportRollupService::refresh(int $propertyId, CarbonImmutable $from, CarbonImmutable $toExclusive): void  — rebuild rollups (call after bulk data changes)
// ReportService::run(string $key, ReportFilter $f): array  — {summary, chart, tables}; ReportService::csv($key, $f): Generator of CSV rows
// Adding a report: entry in ReportCatalog::REPORTS + method in a Queries class + match arm in ReportService + lang reports.{key}.*
```

Shared value objects and events already exist (do not rename): `App\Domain\Pricing\Quote`,
`App\Domain\Availability\AvailabilityResult`, `App\Domain\Tax\TaxBreakdown`, `App\Domain\Offers\OfferResult`,
`App\Domain\Inventory\Exceptions\NotAvailableException`, events `App\Domain\Property\Events\PropertyCreated`
(dispatched by PropertyService), `App\Domain\Accommodation\Events\{RoomTypeUnitsChanged, UnitBlockChanged, ProductChanged}`
(dispatched by Phase 2, handled by Phase 3 listeners in `app/Listeners/Inventory/`). Model classes for every table already
exist in `app/Models` as stubs; the owning phase adds relationships and behaviour.

Money: amounts are decimal strings; arithmetic uses `bcmath` (`ext-bcmath`), never floats.

Until a dependency is merged, code against the signature and cover it with a test double.

## 7. Testing

* PHPUnit with MySQL (`ozepms_test` database). SQLite is not supported — the schema uses MySQL CHECK
  constraints, generated columns and FULLTEXT indexes.
* Every module adds: feature tests for each endpoint (success, validation, permission denied,
  **another property's record → 404**), unit tests for services, and a concurrency test where stock is involved.
* `tests/Arch/NoRawDatabaseAccessTest.php` fails the build if code outside `app/Infrastructure/Database`
  uses PDO, mysqli or `DB::connection()`.
* Run: `php artisan test` and `npm run build` before handing over.

## 8. Translations

Add keys to `lang/en/<module>.php` first, then the same keys to `fr`, `it`, `de`. All groups in
`lang/en` are sent to the browser automatically except `validation, mail, passwords, pagination`.
In React: `t('rooms.add_room_type')`; in PHP: `__('rooms.add_room_type')`.

## Input rules (everything users send)

1. Every field in a request class is bounded: text has `max`, numbers have a range, lists have a maximum size, files have a size limit and a type list. `tests/Feature/Security/RuleLintTest` fails the build when a new field is not bounded.
2. A fixed list of allowed values must match the database column (enum). The same test checks it.
3. Uploads only go through `App\Support\SafeUpload`: real content decides the type (JPEG, PNG, WebP, PDF for ID documents), pictures are re-drawn (metadata and hidden data removed), PDFs with active content are refused, stored names are generated.
4. Downloads that open in spreadsheets use `App\Support\Csv` (formula characters are neutralised).
5. `App\Http\Middleware\SanitizeInput` runs on every request: control characters removed, invalid UTF-8 refused, nesting / count / body size limited, array values dropped from page query strings.
6. Never read a request value as a string before it has been validated (`(string) $this->input(...)` in `rules()` breaks on arrays); `tests/Feature/Security/InputFuzzTest` sends hostile input to every endpoint and expects a 4xx, never a 5xx.

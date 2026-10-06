# 07 — Public booking engine and versioned API

## 1. Booking engine (public pages)

| | |
|---|---|
| URL | `/book/{PROPERTY_CODE}` — no login, property code only |
| Pages | search `/book/{code}`, checkout `/book/{code}/checkout`, confirmation `/book/{code}/booking/{ref}` (signed link, also e-mailed) |
| JSON | `GET /book/{code}/api/search`, `POST /book/{code}/api/book`, `POST /book/{code}/api/payments/{id}/checkout`, `POST /book/{code}/api/payments/{id}/verify` |
| Switch | Settings → Booking engine (on/off, intro text, booking terms, shareable link) |
| Open when | property active, subscription not read-only, plan feature `booking_engine`, switched on |
| Limits | `config/ozepms.php` → `booking_engine` (days ahead, nights, rooms, hold minutes, cache, rate limits) |

Routes live in `routes/booking.php`, controllers in `app/Http/Controllers/Booking`, logic in
`app/Domain/BookingEngine`. The PMS reservation list is not part of these pages; bookings arrive
there with source **Booking Engine**.

### Rules applied to every search
1. Offers and promo codes (same `OfferService` as the PMS).
2. Rooms without free units for every night, or with closed / CTA / CTD restrictions, are hidden.
3. Min / max stay not met → hidden.
4. Search by check-in, check-out, adults, children, infants and number of rooms.
5. Every bookable room type with every sellable rate plan is listed; advance payment (full or deposit)
   is asked online when the rate plan requires it and online payments are set up; otherwise the hotel
   confirms and collects. Cancellation terms are shown on the results and before booking.
6. Taxes are listed line by line (GST / VAT / city tax …) with the total.
7. Rate plan badges: refundable / non-refundable, breakfast included / not included, payment type.

## 2. Versioned API `/api/v1`

For the hotel's own website, mobile app or a partner. Uses the same engines as the booking engine.

### Authentication
- Create a key in **Settings → API keys** (owner / manager with `property.update`). The full key is shown once.
- Format `ozk_{12}_{40}`. Stored as prefix + SHA-256 hash; revocable; last use recorded.
- Send `Authorization: Bearer ozk_…` (or `X-Api-Key: ozk_…`).
- `Accept-Language: en|fr|it|de` selects the language of messages and texts.
- Rate limit: `config('ozepms.api.requests_per_minute')` per key.

| Ability | Allows |
|---|---|
| `availability` | `GET /property`, `GET /availability` |
| `reservations.create` | `POST /reservations` |
| `reservations.read` | `GET /reservations/{ref}` |

### Errors
```json
{ "error": { "code": "UNAUTHENTICATED", "message": "…" } }
```
| Status | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | missing, invalid, expired or revoked key |
| 403 | `FORBIDDEN` | key lacks the ability |
| 403 | `PROPERTY_UNAVAILABLE` | property inactive, read-only subscription or plan without booking engine |
| 404 | | unknown booking reference (or of another property) |
| 422 | | validation — `error.fields` lists messages per field; price changed → `quoted_total` |
| 429 | | rate limit |

### Endpoints
All successful responses are wrapped in `{ "data": … }`.

**GET /api/v1/property** — name, address, contact, currency, check-in/out times, booking URL, room types (id, name, occupancy, size, view, images).

**GET /api/v1/availability** — query: `check_in`, `check_out` (Y-m-d), `adults`, `children`, `infants`, `rooms`, `promo_code` (optional).
Returns `nights`, `currency`, `promo` and `room_types[]` each with `rates[]`:
`rate_plan_id, name, meal_plan, breakfast, refundable, payment_type, room_total, discount, taxes, grand_total, grand_before, per_night, pay_now, tax_lines[], policy_lines[], offers[]`.

**POST /api/v1/reservations** — body:
```json
{
  "check_in": "2026-11-02", "check_out": "2026-11-04", "adults": 2, "children": 0, "infants": 0, "rooms": 1,
  "room_type_id": "…", "rate_plan_id": "…", "promo_code": null, "quoted_total": "11800.00",
  "guest": { "first_name": "Maya", "last_name": "Iyer", "email": "maya@example.com", "phone": "+91 98765 43210", "nationality_iso2": "IN" },
  "arrival_time": "15:00", "special_requests": null, "external_ref": "WEB-77"
}
```
Header `Idempotency-Key` (or body `idempotency_key`) makes retries safe. `quoted_total` (optional) rejects the
booking with 422 when the price has changed. Response 201: booking (`ref`, `status`, rooms, totals, `paid`),
`confirmation_url`, `payment.due_now` and — when advance payment is due online — `payment.checkout` and `payment.payment_id`.
Pay-at-hotel bookings are `confirmed`; online-payment bookings are `pending` and held for `hold_minutes`.

**GET /api/v1/reservations/{ref}** — the booking as above, for this property only.

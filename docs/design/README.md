# OzePMS — UI Design Reference

The screenshots in this folder are the approved visual reference provided by E2X. Every screen is built to match them, with the corrections listed under "Fixes to apply".

| File | Screen |
|---|---|
| reservations-list.png | Reservations list |
| reservation-create-search.png | Create reservation — step 1 (search availability) |
| reservation-create-form.png | Create reservation — full form with summary |
| reservation-details.png | Reservation details |
| calendar-ari.png | Calendar — inventory, rates & restrictions |
| calendar-view-toggle.png | Calendar — Inventory/Rates vs Reservations toggle, status legend |
| calendar-reservation-bars.png | Calendar — reservation bars over the PMS rooms |
| guests.png | Guests list + profile side panel |
| room-types.png | Room types list |
| rooms-pms.png | PMS rooms list + room side panel |
| rate-plans.png | Rate plans list + detail side panel |
| offers.png | Offers & promotions list + detail side panel |
| calendar-reference.png (earlier copy, same as calendar-ari) | — |
| reservations-list-panel.png | Reservations list v2 — KPI chips + side "Reservation Details" panel (preferred layout) |
| taxes-fees.png | Taxes & fees list + inline edit panel |
| rate-plans-v2.png | Rate plans v2 — KPI tiles, channels column + details panel (preferred layout) |
| login.png | Login |
| dashboard.png | Property dashboard |
| properties.png | Properties (property-level view) |
| users-roles.png | Users & roles |
| super-admin-dashboard.png | Super Admin dashboard |
| super-admin-properties.png | Super Admin — properties management |

Still to come: Reports, Channel Manager, Settings.

## Shared layout (every page)

* **Sidebar** (dark navy): logo "OzePMS / Property Management System", menu: Dashboard, Reservations, Calendar, Properties, Room Types, Rooms (PMS), Rate Plans, Guests, Offers & Promotions, Taxes & Fees, Reports, Channel Manager, Users & Roles, Settings. The active item is a solid blue rounded pill. Items are shown or hidden by permission.
* **Top bar**: property photo + name + dropdown (property switcher) + "code | city, country"; global search "Search booking, guest, room…"; language (globe + EN); notifications bell with red count; avatar + name + role + dropdown (profile, security, logout).
* **Page header**: large bold title, one-line grey description below; actions on the right (secondary outline buttons such as Export / Import; one primary blue button such as "+ Create Reservation").
* **List pages** follow one pattern: filter bar (search with icon, labelled dropdowns, date range, "More Filters") → status tabs with count pills → table with checkbox, sortable headers, coloured status badges, outline blue "Edit/View" button + "⋮" menu → footer "Showing 1 to 10 of N", numbered pagination, "10 per page".
* **Master-detail pages** (Guests, Rooms, Rate Plans, Offers): the table on the left and a detail panel on the right for the selected row (header with name + status + Edit, image carousel where relevant, key/value grid, tabs, quick actions).

## Page specifics

**Reservations list** — 5 KPI cards (Upcoming arrivals next 7 days, In-house guests, Today check-out, Pending reservations, Total reservations this month), each tinted with its own colour and icon. Filters: Search, Status, Reservation Source, Check-in date, Check-out date, Room Type, Rate Plan, More Filters, Reset / Apply. Tabs: All, Upcoming, In-House, Checked-Out, Cancelled, No-Show. Columns: ID (blue link), Guest (flag, name, email, phone), Check-in (date + time), Check-out, Nights, Room / Room Type, Rate Plan, Guests (adults / children icons), Total Amount, Status badge, Source (channel logo + name), Actions. Overdue dates are shown in red.

**Create reservation** — stepper: 1 Guest & Stay Details, 2 Rooms & Rate Plan, 3 Additional Services, 4 Review & Confirm. Step "search" block: Check-in (date + time), Check-out, Adults, Children, Infants, "Search Availability" → results table: room type (image, occupancy "2A • 1C • 0I"), rate plan, price, rooms available (green), policy badge, Select. Form blocks: Guest Information (Search Guest, guest type, title, first/last name, email, phone with flag, nationality, ID type, ID number, company, notes), Stay Details (dates, times, nights auto, adults, children, purpose, source, market, travel agent, group/company), Room & Rate Plan rows (room type, PMS room, rate plan, adults, children, price per night, nights, total, delete, "+ Add Room"). Sticky right column: Reservation Summary, Price Summary (currency selector, room charges, extras, discount, subtotal, taxes & fees, Total), Payment (method, amount paid, status). Sticky footer: Save as Draft · Cancel · Review & Confirm →.

**Reservation details** — back arrow, title, reference chip, status badge, "Created on … · Source"; actions Print, Copy, Cancel (red tint), Edit Reservation (primary). Summary strip: guest (flag, phone, email), check-in, check-out, nights, guests; money strip: Total, Paid (badge), Balance. Tabs: Overview, Guest Details, Rooms & Rate, Payments, Notes & History, Documents. Cards: Room & Rate Information, Guest Information, Stay Details, Additional Information (source, created/modified by/on, channel reservation ID, OTA guest ID), Price Breakdown, Payment Information (+ Add Payment), Quick Actions (Add Extra Charge, Change PMS Room, Send Email, Download Voucher, Print Folio, Add Note).

**Calendar** — view toggle "Inventory / Rates" | "Reservations"; month navigation, Today, Month/Week selector, Bulk Update, Update. Filters: Room Type, PMS Room, Rate Plan, Status, More Filters. Legend: Confirmed (green), In-House (blue), Pending (yellow), Blocked (pink), Out of Service (grey), Inventory 0 (red), Inventory > 0, Stop Sell, No Arrival (CTA), No Departure (CTD). Weekend columns tinted. Hierarchy room type ▸ rows; reservation bars (guest + reference, coloured by status, click opens reservation). Every icon and coloured cell has a tooltip and `title` (e.g. "Stop Sell").

**Guests** — Import, Export, + Add Guest. Filters: search (name, email, phone, ID number), nationality, guest type, status, created date range. Tabs: All, In-House, Upcoming, Past. Panel: profile, tags (+ Add Tag), tabs Stay History / Notes / Documents / Preferences, Quick Actions (New Reservation, View Folio, Send Email, More).

**Room types** — columns: image + name (code) + short description, code, category, base occupancy, max occupancy, total rooms, active rooms, default rate plan (dropdown), status, actions.

**Rooms (PMS)** — tabs: All, Available, Occupied, Out of Service, Out of Order, Housekeeping, Due for Cleaning. Columns: room no, name, type, floor, status, current guest + reservation. Panel: photos, details, tabs Details / Amenities / Images / Notes / History, housekeeping status, last/next cleaning, buttons "Mark as Out of Service" (red tint) and "Assign Housekeeping".

**Rate plans** — tabs by status and meal plan with counts. Columns: name, code, meal plan, refundable badge (green Refundable / orange Non-Refundable), channel visibility, status. Panel: details, tabs Assigned Room Types (room type, base rate, min LOS, edit) / Rates & Restrictions / Conditions / History, Rate Plan Settings.

**Offers & promotions** — tabs: All, Active, Scheduled, Expired, Room Discount, Package, Last Minute, Early Bird. Columns: ID, name, type, discount/benefit, validity, status, channels. Panel: image carousel, details, tabs Description / Conditions / Applicable Rooms / Channels / History.

**Reservations list v2 (preferred)** — 7 compact KPI chips (Total, Confirmed, In-House, Arrivals Today, Departures Today, Pending, Cancelled); search, status, source, date range, More Filters, Export ▾; tabs All, Confirmed, In-House, Pending, Arrivals, Departures, Cancelled, No Show, Group; columns booking ID, guest (initials avatar + flag + name), room no., room type, check-in, check-out, nights, guests "2 + 1", total, status, source, ⋮. Clicking a row opens the right "Reservation Details" panel: name + status + Edit, booking ID, tabs Overview / Guest / Rooms / Payments / Notes / History, stay box, room box, guests/source, Price Summary, Actions (View Folio, Modify, Cancel, Send Email, Print Confirmation, More Actions).

**Taxes & fees** — Tax Settings, + Add Tax / Fee. Tabs All / Taxes / Service Charges / Fees; search, status, apply-to. Columns: name, code, type badge (Tax red, Service Charge green, Fee blue), rate/amount (+ "per room per night"), apply to, calculation, status toggle, default radio, ⋯. Right panel is an edit form: tabs Details / Applies To / Advanced; Name, Code, Type, Rate/Amount + currency + basis, Apply To, Calculation Method + basis, Description, Options (default for new room types, include in displayed rate), Status toggle; Delete · Cancel · Save Changes.

**Rate plans v2 (preferred)** — KPI tiles All / Active / Inactive / Channel Mapped / Not Mapped; columns name, code, meal plan (icon), room types, policy badge (Flexible / Non-Refundable), base rate, min LOS, channel icons (+N), status. Panel tabs Overview / Rates & Restrictions / Room Types / Channels; Quick Actions Set Rates, Copy, Deactivate, Map to Channels, View History.

**Login** — split screen: left full-bleed resort photo with logo, headline "Simplify Operations. Maximize Revenue.", sub-text, six feature chips, three stats; right: language selector, card with logo, "Welcome Back", email (icon), password (icon + show/hide), Forgot Password?, Sign In (primary full width), "or continue with" Google / Microsoft (shown only when SSO is configured).

**Property dashboard** — date-range picker; KPI cards Total Rooms, Occupied Rooms (+occupancy %), Arrivals Today, Departures Today, Total Revenue, each with change chip; Occupancy & Revenue chart (tabs Occupancy % / Room Revenue / ADR / RevPAR, bars + line, two axes); Room Status donut (Occupied, Vacant, Out of Service, Blocked); Today Summary tiles (Arrivals, Departures, Housekeeping, Issues); Arrivals Today and Departures Today tables (View All); Housekeeping Status bars (Cleaned, In Progress, Pending, Inspected).

**Properties** — KPI tiles Total / Active / Inactive / Setup In Progress; filters countries, statuses, types; columns property (photo, name, tagline), code, location (flag), type, total rooms, status. Panel: hero photo + Edit, name, status, code, star rating, tagline, tabs Overview / Settings / Facilities / Images / Users, key/value list with icons (code, type, address, time zone, total rooms, active rate plans, channels connected, contact person/email/phone), actions Manage Rooms, Rate Plans, Copy Property, Deactivate.

**Users & roles** — Role Management, + Add User; KPI tiles Total Users, Active, Inactive, Roles; filters roles, properties, statuses; columns user (avatar, name, title), email, role badge (each role its own colour), property access, status, last login. Panel: avatar, status, Edit User, role + description, tabs Overview / Permissions / Properties / Activity Log, details (incl. Two-Factor Auth Enabled), Property Access thumbnails (+N, Manage Access), Quick Actions Reset Password, Disable User, Duplicate User, Delete User.

**Super Admin dashboard** — sidebar adds a "Super Admin" item at the bottom; top bar search "Search properties, users, bookings…", no property switcher. KPIs Total Properties, Total Rooms, Total Users, Total Bookings, Total Revenue (with change chips); Booking Performance chart (Daily ▾); Property Distribution donut; Properties Overview table with occupancy bars; right column Recent Activities, System Overview (connected channels, upcoming expirations, pending approvals, system health), Quick Actions (Add Property, Add User, View Reports, System Settings).

**Super Admin properties management** — Add Property, Bulk Actions ▾, Export; KPI tiles Total Properties, Total Rooms, Active, Inactive, Setup In Progress (with % of total); table with occupancy bars; panel as Properties with actions Manage Rooms, Rate Plans, View Bookings, Property Settings.

Notes: property codes in the designs use "P1001"; the system generates codes in this short readable format (P + 4–6 digits, unique, not reused). Money values use the property currency (AED in the samples).

## Fixes to apply over the screenshots

1. **One input height** (40 px) and one label style (12 px, medium, grey, 6 px above the field) for every input, select and date field. Labels always above fields, never beside; no field without a label.
2. **Grid alignment**: forms use a 12-column grid with 16 px gaps; fields in one row share the same top and bottom edges; buttons in a filter row align to the bottom of the inputs.
3. **Group fields in cards** with a card title (e.g. Guest Information, Stay Details, Room & Rate Plan, Payment). Related fields sit together; optional fields are marked "(Optional)" in the label.
4. Create-reservation step 1 search: same 40 px height fields, check-in/check-out show date and time inside one field, the Search button matches the field height (not oversized).
5. Fix the typo "Check-ou" → "Check-out" on reservation details.
6. Typography must be one family (Inter) everywhere; the create-reservation search screenshot uses a different font and spacing and is corrected to the shared style.
7. Numbers and money use tabular figures and right alignment in tables.
8. Detail panels align their key/value columns to a fixed label width.

## Centralised configuration (must be followed in code)

| Concern | Single place |
|---|---|
| Colours, font family, sizes, radius, spacing, shadows, status colours | `resources/css/tokens.css` (CSS variables) — components never use raw hex values |
| Component styles (button, input, select, badge, table, card, tabs, modal) | `resources/css/components/*.css` + React components in `resources/js/components/ui/` — pages only compose them |
| Font loading | one import in `resources/css/fonts.css` |
| Translations | `lang/{en,fr,it,de}/*.php` (server) exported to `resources/js/i18n/` at build — no hard-coded labels |
| Status → colour/label mapping | `resources/js/lib/status.ts` + `config/ozepms.php` |
| Menu items, icons, permissions | `config/navigation.php` |
| Database connection | `config/database.php` reading `.env` only |
| App settings (formats, defaults, limits) | `config/ozepms.php` + `property_settings` table |
| Icons | one icon set (Lucide), wrapped in `components/ui/Icon.tsx` |

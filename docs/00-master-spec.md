# OzePMS --- Full Product Scope, Database Architecture & Development Brief

## MASTER INSTRUCTION

You are the lead software architect and senior full-stack engineering
team for **OzePMS**, a commercial-grade, multi-tenant SaaS Property
Management System owned and operated by **E2X Infotech Pvt Ltd.**

Read this entire specification before proposing or writing code.

**Do not immediately generate the full application.**

First analyze the requirements, identify architectural risks, resolve
ambiguities, and produce the architecture deliverables described in this
document. Database architecture must be finalized before implementation.

The objective is to build OzePMS as a serious hospitality technology
platform, not as a basic hotel CRUD application.

The first product is the PMS. The architecture must also support future:

-   Property-specific Booking Engine
-   OTA Channel Manager
-   Two-way OTA synchronization
-   Mobile applications
-   Revenue management
-   AI hospitality services
-   Multi-property expansion
-   International tax/currency/language support

E2X Infotech Pvt Ltd. is always the platform-level Super Admin.

------------------------------------------------------------------------

# 1. PRODUCT VISION

OzePMS should provide a modern, fast, secure and scalable platform for:

-   Hotels
-   Resorts
-   Villas
-   Apartments
-   Homestays
-   Individual properties
-   Caravans
-   Other accommodation businesses

Core philosophy:

**One reliable hospitality core for property operations, availability,
pricing and reservations.**

The same core business engines must eventually power:

-   PMS
-   Direct Booking Engine
-   Channel Manager
-   Mobile Apps
-   Future AI services

Avoid duplicated business logic.

------------------------------------------------------------------------

# 2. NON-NEGOTIABLE ARCHITECTURAL PRINCIPLES

1.  Multi-tenant data isolation must be enforced by the backend.
2.  Frontend must never access MySQL directly.
3.  Backend source/configuration/secrets must never be publicly exposed.
4.  Room Type, Physical Unit and Rate Plan must be separate concepts.
5.  Rate Plans must be independent of Rooms/Units.
6.  Room Type ↔ Rate Plan must use a mapping relationship.
7.  Daily inventory, rates and restrictions must be date-based.
8.  Do not store an entire month/year of calendar information in one
    JSON/serialized field.
9.  Do not create duplicate master data unnecessarily.
10. Availability must have one central source of truth.
11. Pricing must have one central source of truth.
12. Tax calculation must have one central source of truth.
13. Offer calculation must have one central source of truth.
14. Reservation creation must perform a final backend availability check
    inside a safe transaction.
15. Booking Engine must use the same availability/pricing/reservation
    engines as the PMS.
16. Future OTA integrations must not require rewriting core PMS tables.
17. Country/tax/currency assumptions must not be hardcoded to India.
18. All user-facing labels must support internationalization.
19. Performance must be designed around actual query patterns and
    indexes.
20. Security must be designed before production implementation.
21. Do not generate thousands of files before the architecture is
    reviewed.
22. Do not sacrifice database integrity for rapid UI development.
23. Do not duplicate business logic between controllers, frontend pages
    and integrations.

------------------------------------------------------------------------

# 3. RECOMMENDED TECHNOLOGY

## Backend

Recommended:

-   PHP latest stable version
-   Laravel latest stable version
-   REST API architecture
-   MySQL
-   Redis where appropriate
-   Queue workers
-   Scheduled jobs
-   Database transactions
-   Validation
-   Authorization
-   Audit logging

## Frontend

Use a modern component-based frontend.

Recommended options:

-   React
-   Vue

Select one architecture and use it consistently.

Frontend communicates with backend only through authenticated APIs.

## Future

Architecture must support:

-   Android
-   iOS
-   OTA APIs
-   Payment providers
-   Email/SMS/WhatsApp providers
-   AI services

------------------------------------------------------------------------

# 4. SYSTEM ARCHITECTURE

Target architecture:

``` text
                    E2X SUPER ADMIN
                          |
                    SaaS Platform
                          |
        +-----------------+-----------------+
        |                                   |
   Property/Tenant A                   Property/Tenant B
        |                                   |
   Owner / Managers                    Owner / Managers
        |                                   |
   OzePMS Core                         OzePMS Core
        |
   +----+----+----+----+----+
   |    |    |    |    |    |
Rooms Rates Inventory Reservations Guests Offers
        |
   Central Business Engines
        |
Availability / Pricing / Tax / Offer / Reservation
        |
    Future Shared Services
        |
 Booking Engine / Channel Manager / Mobile / AI
```

------------------------------------------------------------------------

# 5. MULTI-TENANT MODEL

OzePMS is a SaaS application.

Recommended hierarchy:

``` text
E2X Platform
    |
    +-- Property/Tenant
          |
          +-- Property Owner
          +-- Managers
          +-- Staff
          +-- Rooms
          +-- Rate Plans
          +-- Inventory
          +-- Reservations
          +-- Guests
          +-- Offers
          +-- Taxes
```

Every tenant-specific record must be tied to the appropriate
property/tenant.

A user must never access another property's:

-   Rooms
-   Rate plans
-   Inventory
-   Reservations
-   Guests
-   Offers
-   Settings
-   Users
-   Financial information

Do not rely only on frontend route/menu restrictions.

Tenant isolation must exist in:

-   Authentication context
-   Authorization
-   Services
-   Query scopes/repositories
-   API endpoints
-   Database relationships
-   Tests

------------------------------------------------------------------------

# 6. SUPER ADMIN

E2X Infotech is the permanent Super Admin.

## Super Admin Dashboard

Display:

-   Total properties
-   Active properties
-   Trial properties
-   Suspended properties
-   Expired properties
-   Total users
-   Active subscriptions
-   Expiring subscriptions
-   Subscription revenue
-   Total room types
-   Total physical units
-   Total reservations
-   Recent registrations
-   Recent activity
-   System health

## Super Admin Property Management

Super Admin can:

-   Register property
-   Edit property
-   View property
-   Activate property
-   Suspend property
-   Deactivate property
-   Manage owner
-   Manage users
-   Manage subscription
-   View property settings
-   View room types
-   View physical units
-   View rate plans
-   View reservations
-   View audit activity

------------------------------------------------------------------------

# 7. PROPERTY REGISTRATION

Support property types:

-   Hotel
-   Resort
-   Villa
-   Apartment
-   Homestay
-   Individual Property
-   Caravan
-   Other

The property type system must be extensible.

Property fields:

-   Property name
-   Property type
-   Phone
-   Email
-   Website
-   Description
-   Logo
-   Country
-   State/Province
-   City
-   ZIP/Postcode
-   Address
-   Latitude
-   Longitude
-   Google Maps link
-   Currency
-   Timezone
-   Default language

------------------------------------------------------------------------

# 8. FIRST LOGIN / ONBOARDING

After login:

If the user has no property:

→ require property creation.

After property creation:

→ require initial rate-plan setup.

Then:

→ require room-type/room setup.

Important:

If a user tries to create a room type and no rate plan exists:

Display:

**No Rate Plan Found**

and:

**+ Create Rate Plan**

The rate plan can be created without leaving the workflow.

After saving the rate plan, return to the room-type/rate-plan mapping
workflow.

The onboarding process should be simple and fast.

------------------------------------------------------------------------

# 9. PROPERTY CONFIGURATION

Create a dedicated Property Configuration section.

## General

-   Property name
-   Property type
-   Phone
-   Email
-   Website
-   Description
-   Logo

## Location

-   Country
-   State
-   City
-   ZIP
-   Address
-   Latitude
-   Longitude
-   Google Maps link

## Regional

-   Currency
-   Timezone
-   Date format
-   Number format
-   First day of week

## Language

Default:

-   English

Additional:

-   French
-   Italian
-   German

Architecture must allow additional languages later.

------------------------------------------------------------------------

# 10. ROOM ARCHITECTURE --- CRITICAL

Do NOT treat "Room" as one single database concept.

Use three separate concepts:

## A. Room Type

Represents the sellable accommodation category.

Examples:

-   Standard Room
-   Deluxe Room
-   Executive Room
-   Suite
-   Villa
-   Caravan
-   Apartment

Room Type fields may include:

-   Property ID
-   Name
-   Code
-   Description
-   Max adults
-   Max children
-   Max infants
-   Max total occupancy
-   Bed configuration
-   Room size
-   Smoking/non-smoking
-   View
-   Default amenities
-   Images
-   Active/inactive

## B. Physical Unit

Represents the actual physical inventory unit.

Examples:

-   Room 101
-   Room 102
-   Room 103
-   Villa A
-   Villa B
-   Caravan 01

Physical Unit belongs to a Room Type.

Physical Unit fields may include:

-   Property ID
-   Room Type ID
-   Unit number/name
-   Internal code
-   Floor
-   Status
-   Notes
-   Active/inactive

This allows:

``` text
Deluxe Room
    |
    +-- 101
    +-- 102
    +-- 103
    +-- 104
```

without duplicating room type/rate plan information.

## C. Rate Plan

Rate Plan is independent.

Examples:

-   Room Only
-   Breakfast Included
-   Half Board
-   Full Board
-   All Inclusive
-   Non-Refundable

Rate Plan does NOT belong directly inside an individual physical unit.

------------------------------------------------------------------------

# 11. ROOM TYPE ↔ RATE PLAN

Create an explicit mapping between Room Type and Rate Plan.

Concept:

``` text
Property
   |
Room Type
   |
Room Type ↔ Rate Plan
```

A Deluxe Room can have:

-   Room Only
-   Breakfast Included
-   Non-Refundable

A Villa can have:

-   Room Only
-   Breakfast
-   Full Board

The mapping must support:

-   Active/inactive
-   Default rate plan
-   Display order
-   Configuration overrides where genuinely necessary

Do not duplicate the complete rate-plan definition into every room.

------------------------------------------------------------------------

# 12. ROOM AMENITIES

Create normalized amenity management.

Common amenities:

-   Wi-Fi
-   Air conditioning
-   Heating
-   TV
-   Smart TV
-   Refrigerator
-   Mini bar
-   Safe
-   Hair dryer
-   Iron
-   Balcony
-   Bathtub
-   Shower
-   Desk
-   Wardrobe
-   Telephone
-   Coffee maker
-   Kettle
-   Parking
-   Room service

Allow:

**+ Add Custom Amenity**

Amenities should be reusable.

Avoid duplicate amenity records.

Decide whether an amenity applies to:

-   Room Type
-   Physical Unit
-   Property

Use relationships instead of repeated text fields.

------------------------------------------------------------------------

# 13. RATE PLAN

Rate plan fields should support:

-   Property ID
-   Name
-   Code
-   Description
-   Meal plan
-   Refundable/non-refundable
-   Cancellation policy
-   Default/base price where appropriate
-   Occupancy pricing rules
-   Adult pricing
-   Child pricing
-   Infant rules
-   Minimum stay
-   Maximum stay
-   Booking conditions
-   Active/inactive

All values must be editable later.

------------------------------------------------------------------------

# 14. MEAL PLAN

Support:

-   Room Only
-   Breakfast
-   Lunch
-   Dinner
-   Breakfast + Dinner
-   Half Board
-   Full Board
-   All Inclusive
-   Custom

The internal model must be flexible enough for future OTA mapping.

------------------------------------------------------------------------

# 15. REFUNDABLE / NON-REFUNDABLE

Rate plans support:

-   Refundable
-   Non-refundable

Refundable plans can define cancellation rules.

Potential rules:

-   Free cancellation until X days before arrival
-   First-night charge
-   Percentage charge
-   Full charge
-   Fixed amount
-   Custom rules

Do not hardcode only one policy model.

------------------------------------------------------------------------

# 16. OCCUPANCY

Support:

-   Adults
-   Children
-   Infants
-   Total occupancy

Define:

-   Maximum adults
-   Maximum children
-   Maximum infants
-   Maximum total occupants

The backend must validate occupancy.

Do not trust frontend values.

------------------------------------------------------------------------

# 17. DAILY INVENTORY ARCHITECTURE --- CRITICAL

Inventory must be date-based.

Do NOT store a month/year as one JSON field.

Recommended conceptual model:

``` text
inventory_daily

property_id
room_type_id
date
total_inventory
sold_inventory
blocked_inventory
available_inventory
```

However, do not blindly duplicate derived values.

During database design determine which values should be:

-   Stored
-   Calculated
-   Cached

The system must have one authoritative inventory model.

For a property with 500+ physical units and years of data, design
indexes and retention/archival strategies so queries remain fast.

------------------------------------------------------------------------

# 18. DAILY RATE ARCHITECTURE

Do not put all pricing into the room table.

Create a date-based rate model.

Concept:

``` text
rate_daily

property_id
room_type_id
rate_plan_id
date
price
currency
```

The final schema must also support future:

-   Occupancy-based pricing
-   Adult pricing
-   Child pricing
-   Seasonal pricing
-   Derived rates
-   Discounts
-   OTA rate mapping

Do not duplicate unnecessary pricing data.

------------------------------------------------------------------------

# 19. DAILY RESTRICTION ARCHITECTURE

Restrictions should be independently manageable.

Support:

-   Minimum LOS
-   Maximum LOS
-   Stop Sell
-   Closed to Arrival
-   Closed to Departure
-   Cutoff

Concept:

``` text
restriction_daily

property_id
room_type_id
rate_plan_id
date
min_los
max_los
stop_sell
closed_to_arrival
closed_to_departure
cutoff
```

The final database can merge or separate some structures if
query/performance analysis justifies it.

Do not create a giant unindexed calendar table without analyzing access
patterns.

------------------------------------------------------------------------

# 20. CALENDAR

Create a premium operational calendar.

Views:

-   Month
-   Year
-   Optional week
-   Optional day

Filters:

-   Room Type
-   Rate Plan
-   Availability
-   Price
-   Restrictions
-   Active/inactive

Display:

-   Price
-   Available inventory
-   Min LOS
-   Max Stay
-   Stop Sell
-   CTA
-   CTD
-   Cutoff

Operations:

-   Edit single date
-   Edit date range
-   Bulk update
-   Copy values
-   Bulk restrictions

Calendar must only fetch the required date range.

Do not load years of records into the browser.

------------------------------------------------------------------------

# 21. INVENTORY QUERY / INDEX STRATEGY

Before coding, analyze actual queries such as:

1.  Availability for room type between check-in/check-out.
2.  Calendar for property for one month.
3.  Rate for room type/rate plan/date.
4.  Restrictions for room type/rate plan/date.
5.  Reservation impact on inventory.
6.  Future booking engine availability search.
7.  Future OTA synchronization.

Design composite indexes around these queries.

Do not add indexes blindly.

Do not assume a single generic index is enough.

The architecture must handle:

-   500+ units
-   multiple room types
-   multiple rate plans
-   years of daily records
-   high reservation volume

------------------------------------------------------------------------

# 22. RESERVATION ARCHITECTURE

Reservation must be separate from physical room allocation.

Concept:

``` text
Reservation
    |
    +-- Reservation Room 1
    |      |
    |      +-- Room Type
    |      +-- Rate Plan
    |      +-- Physical Unit (if assigned)
    |
    +-- Reservation Room 2
           |
           +-- Room Type
           +-- Rate Plan
           +-- Physical Unit
```

This supports multi-room reservations.

A reservation may initially reserve a Room Type without assigning a
specific physical unit, depending on business workflow.

Physical unit assignment can happen later.

------------------------------------------------------------------------

# 23. RESERVATION LIST

Display:

-   Reservation ID
-   Booking source
-   Guest name
-   Check-in
-   Check-out
-   Nights
-   Room type
-   Physical unit if assigned
-   Rate plan
-   Guest count
-   Total
-   Payment status
-   Reservation status
-   Created date
-   Edit

Filters:

-   Reservation ID
-   Guest
-   Check-in
-   Check-out
-   Date range
-   Room type
-   Physical unit
-   Rate plan
-   Status
-   Payment status
-   Booking source

Use server-side pagination/filtering.

------------------------------------------------------------------------

# 24. MANUAL RESERVATION CREATION

Used by:

-   Customer support
-   Front desk
-   Property manager
-   Authorized staff

Workflow:

1.  Select check-in
2.  Select check-out
3.  Adults
4.  Children
5.  Infants
6.  Search
7.  Check room-type availability
8.  Display available room types/rate plans
9.  Select room type
10. Select rate plan
11. Apply eligible offer
12. Calculate pricing
13. Calculate taxes
14. Enter guest details
15. Assign physical unit if appropriate
16. Confirm

Backend performs final availability check.

------------------------------------------------------------------------

# 25. RESERVATION EDITING

If dates change:

-   Re-check availability
-   Re-check restrictions
-   Recalculate nights
-   Recalculate rate
-   Recalculate offers
-   Recalculate taxes
-   Adjust inventory transactionally

If unavailable:

Do not silently overbook.

Show options:

-   Select another room type
-   Select another date
-   Assign alternative physical unit
-   Follow configured cancellation/recreation workflow

------------------------------------------------------------------------

# 26. RESERVATION DETAIL PAGE

Display:

-   Reservation ID
-   Status
-   Booking source
-   Guest information
-   All reservation rooms
-   Room types
-   Physical units
-   Rate plans
-   Check-in/out
-   Occupancy
-   Price breakdown
-   Discounts
-   Offers
-   Taxes
-   Other charges
-   Grand total
-   Payment information
-   Notes
-   Audit/history

For multi-room bookings, show each room separately.

------------------------------------------------------------------------

# 27. GUEST MANAGEMENT

Guest fields may include:

-   First name
-   Last name
-   Email
-   Phone
-   Country
-   Nationality
-   Date of birth where legally required
-   Address
-   ID type
-   ID number
-   ID issuing country
-   ID expiry where legally required

Multiple guests can belong to a reservation.

Avoid unnecessary sensitive data.

Protect sensitive guest information.

------------------------------------------------------------------------

# 28. CHECK-IN / CHECK-OUT

## Check-in

-   Guest verification
-   ID details
-   Assigned physical unit
-   Check-in timestamp
-   Notes
-   Status

## Check-out

-   Check-out timestamp
-   Additional charges
-   Final total
-   Payment status
-   Notes
-   Completion status

Statuses:

-   Inquiry
-   Pending
-   Confirmed
-   Checked-in
-   Checked-out
-   Cancelled
-   No-show

------------------------------------------------------------------------

# 29. AVAILABILITY ENGINE

Create a centralized AvailabilityService.

It must understand:

-   Room Type inventory
-   Reservations
-   Blocks
-   Maintenance/out-of-order inventory
-   Restrictions
-   Occupancy
-   Dates

The exact formula must be defined during architecture.

Do not maintain separate availability algorithms for:

-   PMS
-   Booking Engine
-   OTA
-   Reports

One central engine.

------------------------------------------------------------------------

# 30. PRICING ENGINE

Create PricingService.

Conceptually:

``` text
Base Rate
+ applicable adjustments
- eligible discounts
+ taxes
+ additional charges
= final amount
```

Pricing must be reusable by:

-   PMS reservation
-   Booking engine
-   Future OTA/channel manager
-   Future mobile app

Avoid duplicated calculation logic.

------------------------------------------------------------------------

# 31. TAX ENGINE

Create TaxService.

Support:

-   GST
-   VAT
-   Sales Tax
-   Tourism Tax
-   City Tax
-   Local Tax
-   Service Charge
-   Other configurable taxes

Tax model should support:

-   Country
-   Property
-   Tax type
-   Percentage/fixed
-   Applicability
-   Effective dates
-   Room/rate-plan scope where required

Do not hardcode India-specific rules into core code.

------------------------------------------------------------------------

# 32. OFFERS & PROMOTIONS

Create dedicated Offers module.

Support:

-   Offer name
-   Code
-   Percentage discount
-   Fixed discount
-   Applicable room type
-   Applicable rate plan
-   Minimum stay
-   Maximum stay
-   Minimum booking amount
-   Booking window
-   Stay window
-   From date
-   To date
-   Days of week
-   Promo code
-   Active/inactive
-   Priority
-   Conditions

Offers must be evaluated by a central OfferService.

------------------------------------------------------------------------

# 33. BOOKING ENGINE

Each property can have its own booking engine.

Search:

-   Check-in
-   Check-out
-   Adults
-   Children
-   Infants

Flow:

1.  Validate request
2.  Validate property status
3.  Check availability
4.  Check occupancy
5.  Check restrictions
6.  Find eligible room types
7.  Find mapped rate plans
8.  Calculate rates
9.  Apply offers
10. Calculate taxes
11. Display results
12. Collect guest details
13. Create reservation

The booking engine uses the same PMS core services.

------------------------------------------------------------------------

# 34. FUTURE CHANNEL MANAGER

Do not implement complete OTA integrations in the first PMS phase.

But design for them now.

Future:

``` text
OzePMS
    |
Oze Channel Manager
    |
+---+---+---+
|   |   |   |
OTA OTA OTA
```

Potential OTAs:

-   Booking.com
-   Expedia
-   Agoda
-   Airbnb
-   Other supported channels

## Outbound

PMS → OTA:

-   Availability
-   Rates
-   Restrictions
-   Stop Sell
-   Min LOS
-   Max LOS
-   CTA
-   CTD

## Inbound

OTA → PMS:

-   Reservations
-   Modifications
-   Cancellations
-   Guest details
-   Other supported events

Future conceptual entities:

-   channel_connections
-   channel_room_mappings
-   channel_rate_plan_mappings
-   channel_sync_logs
-   channel_reservations

Do not put OTA-specific columns throughout the core PMS tables.

------------------------------------------------------------------------

# 35. DATABASE ENTITY GROUPS

The final ERD should be organized into groups.

## Platform

-   users
-   roles
-   permissions
-   role_permissions
-   subscriptions
-   subscription_plans
-   audit_logs

## Property

-   properties
-   property_settings
-   property_users
-   property_user_roles

## Accommodation

-   room_types
-   physical_units
-   amenities
-   room_type_amenities
-   physical_unit_amenities where justified

## Pricing

-   rate_plans
-   room_type_rate_plans
-   rate_daily
-   occupancy pricing structures
-   cancellation policies

## Inventory

-   inventory_daily
-   inventory blocks
-   restrictions_daily

## Reservations

-   reservations
-   reservation_rooms
-   reservation_guests
-   guests
-   payments
-   charges

## Taxes

-   tax_rules
-   property_tax_rules
-   reservation_tax_lines

## Offers

-   offers
-   offer_conditions
-   offer_applications

## Future Channel Manager

-   channel_connections
-   channel_room_mappings
-   channel_rate_plan_mappings
-   channel_sync_logs
-   channel_reservations

The final schema should be refined after query analysis.

------------------------------------------------------------------------

# 36. DATABASE NORMALIZATION

Avoid duplicate information.

Examples:

Do not repeat:

-   Property name in every room row
-   Full rate-plan definition in every room
-   Full amenity text in every room
-   Tax definitions in every reservation
-   Offer definition inside every reservation

Use relationships.

However, historical transactional data may intentionally store immutable
snapshots where required for audit/accounting.

Example:

A reservation may store a rate/tax snapshot so that changing a rate plan
later does not rewrite historical reservations.

Distinguish carefully between:

-   Master data
-   Transaction data
-   Historical snapshots
-   Derived/cached data

------------------------------------------------------------------------

# 37. SOFT DELETE / ARCHIVING

Determine entity-specific deletion strategy.

Do not physically delete important transactional records.

Examples:

-   Reservations should normally not be hard deleted.
-   Historical financial records should not be hard deleted.
-   Rate plans may be deactivated.
-   Rooms may be deactivated.
-   Amenities may be archived.
-   Users may be disabled.

Design appropriate status/soft-delete behavior.

------------------------------------------------------------------------

# 38. CURRENCY / MONEY

Do not use floating point for financial calculations.

Use an appropriate decimal/fixed-precision strategy.

Store currency explicitly where required.

Support:

-   Property currency
-   Rate-plan currency where necessary
-   Transaction currency
-   Future OTA currency conversion

Define rounding rules centrally.

------------------------------------------------------------------------

# 39. DATE / TIME

Property operations are timezone-sensitive.

Every property should have a timezone.

Store timestamps consistently.

Date-only operational records such as hotel inventory dates must be
treated as local property dates, not blindly converted between
timezones.

Define clearly:

-   Database timestamp strategy
-   Property local date strategy
-   Reservation date strategy
-   Check-in/check-out time strategy

------------------------------------------------------------------------

# 40. USER / ROLE / PERMISSION ARCHITECTURE

Roles are not enough.

Use permission-based authorization.

Examples:

-   property.view
-   property.update
-   rooms.view
-   rooms.create
-   rooms.update
-   rate_plans.view
-   rate_plans.create
-   calendar.view
-   calendar.update
-   reservations.view
-   reservations.create
-   reservations.update
-   reservations.cancel
-   checkin.perform
-   checkout.perform
-   guests.view
-   guests.update
-   offers.manage
-   users.manage
-   reports.view

Super Admin permissions are separate from property permissions.

------------------------------------------------------------------------

# 41. SUBSCRIPTION SYSTEM

Super Admin controls:

-   Plans
-   Pricing
-   Trial
-   Grace period
-   Expiry
-   Limits
-   Feature access

Possible limits:

-   Number of room types
-   Number of physical units
-   Number of users
-   Number of properties
-   Booking engine
-   Channel manager
-   Reports
-   AI features

Subscription expiry must be enforced by backend.

Never delete property data because of expiry.

------------------------------------------------------------------------

# 42. SECURITY

Required:

-   Secure password hashing
-   Authentication
-   Authorization
-   Tenant isolation
-   SQL injection prevention
-   XSS protection
-   CSRF protection where applicable
-   Rate limiting
-   Brute-force protection
-   Secure cookies
-   HTTPS
-   Security headers
-   Input validation
-   Output escaping
-   Secure file uploads
-   API authentication
-   Audit logs
-   Secret management
-   Production-safe error handling

Never return:

-   Stack traces
-   SQL errors
-   Secrets
-   Internal file paths

to normal users.

------------------------------------------------------------------------

# 43. API ARCHITECTURE

Use versioned APIs.

Examples:

``` text
/api/v1/auth
/api/v1/properties
/api/v1/property-users
/api/v1/room-types
/api/v1/physical-units
/api/v1/amenities
/api/v1/rate-plans
/api/v1/room-type-rate-plans
/api/v1/inventory
/api/v1/rates
/api/v1/restrictions
/api/v1/reservations
/api/v1/guests
/api/v1/taxes
/api/v1/offers
/api/v1/booking-engine
/api/v1/subscriptions
```

Use consistent:

-   HTTP methods
-   Status codes
-   Response format
-   Validation errors
-   Pagination
-   Filtering
-   Sorting

------------------------------------------------------------------------

# 44. BACKEND CODE ARCHITECTURE

Avoid fat controllers.

Recommended:

``` text
Request
  ↓
Validation
  ↓
Controller
  ↓
Service
  ↓
Domain / Business Rules
  ↓
Repository / Query Layer where useful
  ↓
Model / Database
```

Core services:

-   PropertyService
-   RoomTypeService
-   PhysicalUnitService
-   RatePlanService
-   InventoryService
-   AvailabilityService
-   RestrictionService
-   ReservationService
-   GuestService
-   PricingService
-   TaxService
-   OfferService
-   SubscriptionService

Future:

-   ChannelManagerService
-   OtaSyncService
-   AiPricingService

------------------------------------------------------------------------

# 45. TRANSACTION SAFETY

Reservation creation must be transactional.

Recommended sequence:

1.  Authenticate user
2.  Authorize property
3.  Validate property/subscription
4.  Validate dates
5.  Validate room type
6.  Validate rate plan
7.  Validate occupancy
8.  Check restrictions
9.  Calculate pricing
10. Calculate offers
11. Calculate taxes
12. Begin transaction
13. Re-check availability
14. Safely reserve/decrement inventory
15. Create reservation
16. Create reservation-room records
17. Create guest relationships
18. Create payment/charge records where appropriate
19. Write audit log
20. Commit

Failure:

→ rollback.

The system must be tested for concurrent booking attempts.

------------------------------------------------------------------------

# 46. CONCURRENCY / DOUBLE-BOOKING

Test scenarios:

Two users attempt to reserve the last available unit/room type
simultaneously.

The system must prevent invalid overbooking according to the configured
inventory model.

Use appropriate:

-   Transactions
-   Row locking
-   Atomic updates
-   Unique constraints
-   Inventory checks

Do not assume frontend availability prevents double booking.

------------------------------------------------------------------------

# 47. PHYSICAL UNIT ASSIGNMENT

The architecture must allow:

Reservation created:

``` text
Deluxe Room
```

without immediately assigning:

``` text
Room 101
```

Later:

``` text
Assign Room 101
```

This is useful for hotel front desk workflows.

The system must also support reservations where the physical unit is
assigned during booking if required.

------------------------------------------------------------------------

# 48. DASHBOARD

Property dashboard:

-   Today's arrivals
-   Today's departures
-   Current occupancy
-   Available inventory
-   Occupied units
-   Pending reservations
-   Confirmed reservations
-   Check-ins
-   Check-outs
-   Revenue
-   Upcoming arrivals
-   Upcoming departures
-   Room availability
-   Reservation trends

Focus on operational value.

------------------------------------------------------------------------

# 49. AUDIT LOGGING

Log important changes:

-   Login
-   Logout
-   Property creation/update
-   Room type creation/update
-   Physical unit creation/update
-   Rate plan creation/update
-   Rate changes
-   Inventory changes
-   Restriction changes
-   Reservation creation
-   Reservation modification
-   Reservation cancellation
-   Check-in
-   Check-out
-   User creation
-   Permission changes
-   Subscription changes

Log:

-   User
-   Property
-   Action
-   Entity
-   Entity ID
-   Timestamp
-   IP where appropriate
-   Metadata

------------------------------------------------------------------------

# 50. INTERNATIONALIZATION

Default:

English

Support:

-   French
-   Italian
-   German

Use translation keys.

Do not hardcode labels.

Prepare for future languages.

------------------------------------------------------------------------

# 51. UI / UX

The product should feel premium and modern.

Recommended fonts:

-   Inter
-   Manrope
-   Plus Jakarta Sans

Select one.

Design principles:

-   Clean
-   Professional
-   Modern
-   Fast
-   Responsive
-   Accessible
-   High information density when useful
-   Strong hierarchy

Components:

-   Sidebar
-   Top bar
-   Tables
-   Cards
-   Forms
-   Modals
-   Drawers
-   Tabs
-   Filters
-   Date pickers
-   Status badges
-   Toasts
-   Loading states
-   Empty states
-   Error states

Avoid unnecessary animation.

------------------------------------------------------------------------

# 52. PERFORMANCE

Design for:

-   500+ physical units
-   Multiple room types
-   Multiple rate plans
-   Years of daily inventory/rate/restriction records
-   Large reservation history
-   High-frequency availability queries

Use:

-   Proper indexes
-   Query optimization
-   Pagination
-   Caching
-   Redis where justified
-   Queue workers
-   Bulk updates
-   Lazy loading
-   Minimal API payloads

Calendar must not retrieve unnecessary data.

------------------------------------------------------------------------

# 53. REPORTING

Future reports should include:

-   Occupancy
-   Revenue
-   Reservations
-   Room type performance
-   Rate plan performance
-   Cancellation
-   No-show
-   ADR
-   RevPAR where appropriate
-   Booking source
-   Future pickup analysis

Do not let reporting queries slow operational reservation queries.

Consider read-optimized/reporting strategies later if scale requires
them.

------------------------------------------------------------------------

# 54. MOBILE APP READINESS

Web application must be responsive.

Mobile apps should eventually use the same API.

Do not duplicate business logic inside mobile applications.

------------------------------------------------------------------------

# 55. AI READINESS

Future AI modules may include:

-   AI pricing recommendation
-   Demand forecasting
-   Occupancy prediction
-   Revenue optimization
-   Cancellation prediction
-   AI reservation assistant
-   Guest communication
-   Upselling
-   Operational recommendations
-   Anomaly detection

AI should consume stable business services/data.

Do not embed AI-specific assumptions into core reservation tables.

------------------------------------------------------------------------

# 56. TESTING

## Unit tests

Test:

-   Availability
-   Pricing
-   Tax
-   Offers
-   Occupancy
-   Subscription

## Feature/API tests

Test:

-   Authentication
-   Authorization
-   Tenant isolation
-   Property operations
-   Room type operations
-   Physical unit operations
-   Rate plans
-   Calendar
-   Reservations
-   Guests
-   Check-in/out
-   Booking engine

## Security tests

Test:

-   Cross-tenant access
-   Unauthorized endpoint access
-   ID manipulation
-   SQL injection
-   XSS
-   Rate limiting
-   Authentication

## Concurrency tests

Test:

-   Last-room booking race
-   Simultaneous reservations
-   Concurrent calendar updates
-   Reservation edits
-   Inventory updates

------------------------------------------------------------------------

# 57. DEVELOPMENT PHASES

## PHASE 0 --- ARCHITECTURE ONLY

Do not build the full application.

Produce:

1.  System architecture diagram
2.  Multi-tenant strategy
3.  ER diagram
4.  Complete database table specification
5.  Primary keys
6.  Foreign keys
7.  Unique constraints
8.  Indexes
9.  Data types
10. Relationships
11. Soft-delete strategy
12. Audit strategy
13. Date/time strategy
14. Currency/money strategy
15. Tenant isolation strategy
16. Authentication strategy
17. Authorization strategy
18. API architecture
19. Backend folder structure
20. Frontend folder structure
21. Service architecture
22. Availability algorithm
23. Reservation concurrency strategy
24. Calendar query strategy
25. Future OTA mapping strategy

Do not start coding until this architecture has been reviewed.

------------------------------------------------------------------------

# 58. PHASE 1 --- SAAS FOUNDATION

Implement:

-   Project setup
-   Environment configuration
-   Authentication
-   Super Admin
-   Property registration
-   Tenant isolation
-   Property owner
-   Roles
-   Permissions
-   Subscription plans
-   Subscriptions
-   Audit logging

Test thoroughly.

------------------------------------------------------------------------

# 59. PHASE 2 --- ACCOMMODATION MASTER DATA

Implement:

-   Property configuration
-   Room Types
-   Physical Units
-   Amenities
-   Rate Plans
-   Room Type ↔ Rate Plan mapping
-   Occupancy
-   Meal plans
-   Cancellation/refundable rules

Test relationships and permissions.

------------------------------------------------------------------------

# 60. PHASE 3 --- INVENTORY / CALENDAR

Implement:

-   Daily inventory
-   Daily rates
-   Restrictions
-   Calendar
-   Bulk updates
-   Date-range updates
-   Availability engine

Performance-test with large sample data.

------------------------------------------------------------------------

# 61. PHASE 4 --- RESERVATION ENGINE

Implement:

-   Reservation search
-   Availability search
-   Reservation creation
-   Multi-room reservations
-   Guest management
-   Physical unit assignment
-   Reservation editing
-   Cancellation
-   Check-in
-   Check-out
-   Pricing
-   Taxes
-   Audit/history

Concurrency-test the reservation system.

------------------------------------------------------------------------

# 62. PHASE 5 --- OFFERS

Implement:

-   Offers
-   Discount rules
-   Promo codes
-   Booking window
-   Stay window
-   Conditions
-   Priority
-   Application logic

------------------------------------------------------------------------

# 63. PHASE 6 --- BOOKING ENGINE

Implement:

-   Property-specific booking page
-   Search
-   Availability
-   Room type results
-   Rate plans
-   Occupancy
-   Offers
-   Taxes
-   Guest details
-   Reservation creation
-   Confirmation

Reuse the PMS core.

------------------------------------------------------------------------

# 64. PHASE 7 --- REPORTING

Implement:

-   Occupancy
-   Revenue
-   Reservation
-   Room type
-   Rate plan
-   Booking source
-   Cancellation
-   No-show
-   Operational reports

------------------------------------------------------------------------

# 65. PHASE 8 --- CHANNEL MANAGER

Only after the PMS core is stable:

-   OTA connections
-   Authentication/credentials
-   Room mapping
-   Rate-plan mapping
-   Availability synchronization
-   Rate synchronization
-   Restriction synchronization
-   Reservation import
-   Modification
-   Cancellation
-   Sync logs
-   Retry system
-   Error handling

------------------------------------------------------------------------

# 66. REQUIRED ARCHITECTURE DELIVERABLES BEFORE CODING

Before writing application code, provide:

## A. ER Diagram

Show:

-   Platform entities
-   Property entities
-   Room Type
-   Physical Unit
-   Rate Plan
-   Mapping
-   Inventory
-   Rates
-   Restrictions
-   Reservations
-   Guests
-   Taxes
-   Offers
-   Subscriptions
-   Users/Roles/Permissions
-   Future OTA entities

## B. Table Dictionary

For every table:

-   Table name
-   Purpose
-   Column
-   Data type
-   Nullable
-   Default
-   Primary key
-   Foreign key
-   Unique constraints
-   Indexes
-   Delete behavior

## C. Query Strategy

Document important queries and indexes for:

-   Availability
-   Calendar
-   Reservation list
-   Reservation search
-   Pricing
-   OTA synchronization

## D. Tenant Isolation

Explain exactly how API requests identify the current tenant/property
and how every query is protected.

## E. Reservation Concurrency

Explain how the system prevents double booking.

## F. Application Structure

Show backend and frontend folder structure.

## G. API Specification

Show:

-   Endpoint
-   HTTP method
-   Authentication
-   Permission
-   Request
-   Response
-   Validation
-   Error response

------------------------------------------------------------------------

# 67. DEVELOPMENT TEAM WORKING RULES

When working on this project:

### Rule 1

Do not start by generating the entire codebase.

### Rule 2

First analyze this specification.

### Rule 3

If something is ambiguous, identify it before coding.

### Rule 4

Do not silently choose an architecture that conflicts with this
document.

### Rule 5

If you recommend a change, explain why.

### Rule 6

Database design must come before application implementation.

### Rule 7

Do not create redundant tables merely because they are easy to code.

### Rule 8

Do not put unrelated data into giant tables.

### Rule 9

Do not duplicate business logic.

### Rule 10

Do not expose backend implementation.

### Rule 11

Every API endpoint must enforce authentication and authorization.

### Rule 12

Every tenant-scoped query must enforce tenant isolation.

### Rule 13

Every reservation operation must validate availability on the backend.

### Rule 14

Every financial calculation must use centralized pricing/tax services.

### Rule 15

Every major mutation should have appropriate audit logging.

### Rule 16

Do not build the Channel Manager prematurely, but preserve clean
extension points.

### Rule 17

Do not optimize based on assumptions. Analyze actual query patterns.

### Rule 18

Test before moving from one phase to another.

------------------------------------------------------------------------

# 68. FIRST DELIVERABLE FROM THE DEVELOPMENT TEAM

After reading this specification, do NOT write the application yet.

Respond first with:

## 1. Architecture Review

Summarize your understanding of OzePMS.

## 2. Risks

Identify:

-   Database risks
-   Inventory risks
-   Reservation concurrency risks
-   SaaS/tenant risks
-   Security risks
-   Performance risks
-   Future OTA risks

## 3. Proposed Architecture

Show the final architecture.

## 4. ERD

Produce the proposed ERD.

## 5. Database

Provide the complete proposed schema with:

-   Tables
-   Fields
-   Data types
-   Keys
-   Relationships
-   Indexes

## 6. Query Strategy

Explain how the most important operations will remain fast at scale.

## 7. Availability Model

Explain exactly how inventory and availability will work.

## 8. Reservation Model

Explain:

-   Room type reservation
-   Physical unit assignment
-   Multi-room reservations
-   Cancellation
-   Modification
-   Concurrency

## 9. Security

Explain:

-   Authentication
-   Authorization
-   Tenant isolation
-   API security
-   Secret protection

## 10. Application Structure

Show backend/frontend directory architecture.

## 11. API Structure

Show the major API modules.

## 12. Implementation Plan

Give a phase-by-phase implementation plan.

## 13. Questions / Decisions

List only decisions that genuinely require confirmation.

Do not ask unnecessary questions.

After presenting the architecture, wait for approval before starting
Phase 1.

------------------------------------------------------------------------

# 69. FINAL PRODUCT SUCCESS CRITERIA

A successful OzePMS architecture must allow:

``` text
E2X Super Admin
      ↓
Register Property
      ↓
Create Subscription
      ↓
Property Owner Login
      ↓
Configure Property
      ↓
Create Rate Plan
      ↓
Create Room Type
      ↓
Create Physical Units
      ↓
Map Room Type ↔ Rate Plan
      ↓
Configure Inventory / Rates / Restrictions
      ↓
Calendar
      ↓
Availability Search
      ↓
Reservation
      ↓
Guest Management
      ↓
Check-in
      ↓
Check-out
```

And later:

``` text
OzePMS
   |
   +-- Booking Engine
   |
   +-- Channel Manager
   |      |
   |      +-- Booking.com
   |      +-- Expedia
   |      +-- Agoda
   |      +-- Other OTAs
   |
   +-- Mobile Apps
   |
   +-- Revenue Intelligence
   |
   +-- Oze AI
```

The core system must remain the single reliable source of truth for
property inventory, availability, reservations and pricing.

------------------------------------------------------------------------

# 70. LONG-TERM BRAND / PRODUCT STRUCTURE

Potential future Oze ecosystem:

**OzePMS** Property Management

**Oze Booking** Direct Booking Engine

**Oze Channel** OTA Channel Manager

**Oze Revenue** Revenue and Pricing Intelligence

**Oze AI** AI Hospitality Assistant

The architecture should allow these products to share secure common
services without creating tightly coupled modules.

------------------------------------------------------------------------

# 71. FINAL INSTRUCTION

Build OzePMS with the mindset of a production SaaS platform.

Do not optimize only for the first demo.

Design for:

**Security + Speed + Scalability + Data Integrity + Maintainability +
Excellent UX + Future OTA Integration + Mobile + AI**

The most important foundation is:

**Property → Room Type → Physical Unit**

and independently:

**Property → Rate Plan**

connected through:

**Room Type ↔ Rate Plan**

with:

**Daily Inventory + Daily Rates + Daily Restrictions**

feeding a centralized:

**Availability Engine + Pricing Engine + Tax Engine + Offer Engine +
Reservation Engine**

The Booking Engine and future Channel Manager must consume these same
services.

Do not proceed to full implementation until the database and
architecture have been reviewed and approved.

# OzePMS — Project Status & Handoff

**Read this file first when continuing work (any account, any session).**
Last updated: 05 Oct 2026, 16:10 IST.

---

## 1. What this project is

OzePMS — multi-tenant SaaS hotel Property Management System by E2X Infotech Pvt Ltd.
Full product spec: `docs/00-master-spec.md` (the original master prompt).
Production domain: `ozepms.e2xinfotech.in` · Local: `http://ozepms.test`.

## 2. Rules from the client (must follow)

1. **Work phase by phase.** Finish a phase, test it, show the client, and **wait for the client's confirmation before the next phase.**
2. **No AI traces.** No mention of Claude/AI/assistants in code, comments, commits, docs or UI. No `CLAUDE.md`/`AGENTS.md` in the repo. Commits are authored as "E2X Infotech".
3. **Multi-page app (MPA), not SPA.** Each screen is a real server route; React renders inside the page.
4. **No `.php` or framework fingerprints visible** (clean URLs, headers removed).
5. **Everything centralised**: CSS tokens, components, fonts, translations, status colours, navigation, permissions, DB config, app settings.
6. **Follow the client's page designs** in `docs/design/*.png` exactly, applying the fixes listed in `docs/design/README.md` (aligned 40px inputs, labels above, fields grouped in titled blocks, one font).
7. **Fast search / fast database** is mandatory (see `docs/02-database.md` index plan).
8. **Every error is logged**; **password handling must be secure**.
9. Languages: English (default), French, Italian, German.

## 3. Locked decisions

| Area | Decision |
|---|---|
| Stack | Laravel 13 (PHP 8.3+), MySQL 8.4, Redis (prod), React 19 + TypeScript + Vite, Inter font, Lucide icons |
| Server | Ubuntu VPS, Nginx + PHP-FPM + OPcache, Let's Encrypt |
| Tenancy | One database; `property_id` on every tenant table; property code in URL `/p/P1001/...`; property switcher in top bar; codes `P1001, P1002…` |
| Inventory | PMS rooms are mandatory; total inventory = active PMS rooms (quantity-only input auto-creates rooms); calendar shows red when sold out; no overbooking (DB CHECK constraint) |
| Rooms/Rates | Rate plans independent; Room Type × Rate Plan = "Product"; manual or derived pricing; occupancy pricing; rates/restrictions per product per date |
| Calendar | Room type ▸ rate plans ▸ PMS rooms (collapsible); Inventory/Rates view + Reservations view with bars |
| Taxes | GST as data (slabs 0% ≤1000, 5% ≤7500, 18% above, per room-night), CGST+SGST, SAC 9963, invoice series per financial year |
| Payments | Razorpay (Phase 4) |
| Auth | Argon2id, lockout, rate limits, TOTP two-factor (mandatory for super admin & owners), CSRF, CSP |

## 4. Documents

| File | Content |
|---|---|
| `docs/01-architecture.md` | Architecture, MPA flow, tenancy, security, logging, availability & concurrency algorithms |
| `docs/02-database.md` | ERD, index plan, validation results (schema tested on MySQL) |
| `docs/03-structure-api-phases.md` | Folder structure, routes, phase plan |
| `docs/04-development-guide.md` | **Coding conventions, module ownership, cross-module contracts** — read before coding |
| `docs/design/README.md` + PNGs | Approved screen designs and required fixes |
| `database/schema/ozepms_schema_v1.sql` | Approved schema (71 tables) |

## 5. Phase status

| Phase | Status | Notes |
|---|---|---|
| 0 Architecture | ✅ Done, approved by client | |
| Foundation (shared) | ✅ Done | Config, all migrations, model stubs, tenancy (PropertyContext, BelongsToProperty, ResolveProperty), AccessService, LoginService, TwoFactorService/Totp, UserService, RoleService, PropertyService, SubscriptionService, AuditLogger, ErrorRecorder + logging channels, security headers, Page renderer, ShellData, Lookups, Listing, translations loader, seeders (reference data, permissions/roles, plans, super admin), test base + tenant helpers + arch test; frontend design system (tokens.css + component CSS), UI components, AppLayout (sidebar/top bar/property switcher), AuthLayout, boot, http/i18n/format/status libs, SVG charts; login page |
| 1 SaaS foundation screens | 🟡 **In progress (~35%)** | Done: WebApi controllers (Auth, Account, Locale, ClientError, Lookup, Onboarding, Property\Property/Users/Roles, Admin\Properties/Users/Plans/System), FormRequests, Resources, query objects, PasswordService, PlanService, DashboardService, SystemHealthService. **Missing:** Web page controllers (Property\Dashboard/Properties/Settings/Users, Admin\Dashboard/Properties/Users/Plans/Audit/System), all React pages except login, DemoSeeder, fr/it/de translations, local-setup + nginx deploy docs, Phase 1 tests |
| 2 Rooms, rate plans, taxes | 🟡 **In progress (~40%)** | Done: models with relations, Money helper, Accommodation services (RoomType, PhysicalUnit, UnitBlock, Amenity, images, unit names, plan limits, unit-night guard), Rates domain, TaxService + rule matcher, reference data, design-columns migration, PropertyCreated listener. **Missing:** controllers/routes (`routes/property/*.php`, `routes/property-api/*.php`), React pages (room types, PMS rooms, rate plans, taxes), demo seeder, translations, tests |
| 3 Calendar & engines | ⬜ Not started | |
| 4 Reservations & billing | ⬜ Not started | |
| 5 Offers · 6 Booking engine · 7 Reports · 8 Channel manager | ⬜ Not started | |

**Next step:** finish Phases 1 and 2 → run full tests, build, smoke test → show client → **wait for confirmation** → Phases 3 & 4.

## 6. How to continue (environment notes)

* Code repository: this folder (git history included). Latest commit is the partial Phase 1/2 work.
* On a Mac: see `docs/05-local-setup.md` when written; quick version: PHP 8.3+ (with bcmath, intl, pdo_mysql), Composer, MySQL 8.4, Node 22 → `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`, `npm install`, `npm run dev`.
* **Cloud sandbox note:** packagist.org is blocked in the Claude cloud sandbox. Dependencies are installed there by resolving each package from GitHub as a composer "vcs" repository (script `/home/claude/resolve/resolve.py`). Therefore `composer.json` in the repo may temporarily contain a long `repositories` list with `"packagist.org": false` — **remove that list before delivery**; on a normal machine plain `composer install` works.
* Tests need MySQL (not SQLite): database `ozepms_test`, run `php artisan test`.
* Unused packages to remove before delivery: `pragmarx/google2fa`, `bacon/bacon-qr-code` (two-factor uses the built-in `App\Domain\Auth\Totp`; QR is drawn in the browser with the `qrcode` npm package). Add `"ext-bcmath": "*"` and `"ext-intl": "*"` to `require`.
* Parallel agents: keep to 2 at a time (4 at once hit the usage limit). Each agent uses its own test DB (`ozepms_test_p1`, `ozepms_test_p2`…) and stays inside its ownership area from `docs/04-development-guide.md`.

## 7. Change log

| Date | Change |
|---|---|
| 05 Oct 2026 | Phase 0 approved; foundation built; designs received; parallel build started, stopped by usage limit; partial Phase 1/2 work saved |

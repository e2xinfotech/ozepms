-- =====================================================================
-- OzePMS  ·  Database Schema v1  (Phase 0 – architecture baseline)
-- Target  : MySQL 8.4 LTS (compatible with 8.0.16+), InnoDB, utf8mb4
-- Owner   : E2X Infotech Pvt Ltd.
--
-- Conventions
--   * id           BIGINT UNSIGNED surrogate PK, never exposed outside the backend
--   * public_id    ULID (26 chars) used in URLs / API responses
--   * property_id  present on every tenant-scoped table, always the first
--                  column of tenant indexes
--   * stay_date    DATE in the property's local calendar (never converted)
--   * timestamps   DATETIME stored in UTC
--   * money        DECIMAL(14,2) for amounts, DECIMAL(14,4) for unit rates
--   * Daily ARI tables use (entity_id, stay_date) as clustered PK so a date
--     range for one entity is a single contiguous read.
--   * Daily ARI tables intentionally carry no FKs so they can be partitioned
--     by stay_date later; integrity is enforced by the service layer and the
--     nightly reconciliation job.
--   * Laravel migrations are generated from this file; this file is the spec.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. REFERENCE DATA (global, not tenant scoped)
-- =====================================================================

CREATE TABLE currencies (
  code            CHAR(3)          NOT NULL,
  name            VARCHAR(64)      NOT NULL,
  symbol          VARCHAR(8)       NOT NULL,
  minor_units     TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- JPY 0, INR 2, KWD 3
  is_active       TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB;

CREATE TABLE countries (
  iso2              CHAR(2)      NOT NULL,
  iso3              CHAR(3)      NOT NULL,
  name              VARCHAR(100) NOT NULL,
  phone_code        VARCHAR(8)   NULL,
  currency_code     CHAR(3)      NULL,
  default_timezone  VARCHAR(64)  NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (iso2),
  UNIQUE KEY uq_countries_iso3 (iso3),
  CONSTRAINT fk_countries_currency FOREIGN KEY (currency_code) REFERENCES currencies (code)
) ENGINE=InnoDB;

CREATE TABLE states (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  country_iso2    CHAR(2)       NOT NULL,
  code            VARCHAR(10)   NOT NULL,              -- ISO 3166-2 subdivision
  name            VARCHAR(100)  NOT NULL,
  tax_region_code VARCHAR(10)   NULL,                  -- e.g. GST state code "27"
  PRIMARY KEY (id),
  UNIQUE KEY uq_states_country_code (country_iso2, code),
  CONSTRAINT fk_states_country FOREIGN KEY (country_iso2) REFERENCES countries (iso2)
) ENGINE=InnoDB;

CREATE TABLE languages (
  code        VARCHAR(10)  NOT NULL,                   -- en, fr, it, de
  name        VARCHAR(50)  NOT NULL,
  native_name VARCHAR(50)  NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB;

CREATE TABLE property_types (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)       NOT NULL,              -- hotel, resort, villa ...
  label_key   VARCHAR(80)       NOT NULL,              -- translation key
  sort_order  SMALLINT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_property_types_code (code)
) ENGINE=InnoDB;

CREATE TABLE bed_types (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)       NOT NULL,              -- king, queen, twin, sofa_bed
  label_key   VARCHAR(80)       NOT NULL,
  sleeps      TINYINT UNSIGNED  NOT NULL DEFAULT 2,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bed_types_code (code)
) ENGINE=InnoDB;

-- =====================================================================
-- 2. PLATFORM: users, roles, permissions, subscriptions
-- =====================================================================

CREATE TABLE users (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id              CHAR(26)        NOT NULL,
  name                   VARCHAR(120)    NOT NULL,
  email                  VARCHAR(190)    NOT NULL,
  phone_e164             VARCHAR(20)     NULL,
  password               VARCHAR(255)    NOT NULL,           -- Argon2id hash
  is_platform_user       TINYINT(1)      NOT NULL DEFAULT 0, -- E2X staff
  status                 ENUM('active','invited','disabled','locked') NOT NULL DEFAULT 'active',
  locale                 VARCHAR(10)     NOT NULL DEFAULT 'en',
  two_factor_secret      TEXT            NULL,               -- encrypted
  two_factor_recovery    TEXT            NULL,               -- encrypted
  two_factor_confirmed_at DATETIME       NULL,
  failed_login_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until           DATETIME        NULL,
  password_changed_at    DATETIME        NULL,
  last_login_at          DATETIME        NULL,
  last_login_ip          VARBINARY(16)   NULL,
  last_property_id       BIGINT UNSIGNED NULL,               -- reopen last property after login
  email_verified_at      DATETIME        NULL,
  remember_token         VARCHAR(100)    NULL,
  created_at             DATETIME        NOT NULL,
  updated_at             DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  email       VARCHAR(190) NOT NULL,
  token       VARCHAR(255) NOT NULL,                   -- hashed
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  email       VARCHAR(190)    NOT NULL,
  ip          VARBINARY(16)   NOT NULL,
  user_agent  VARCHAR(255)    NULL,
  succeeded   TINYINT(1)      NOT NULL,
  reason      VARCHAR(40)     NULL,                    -- bad_password, locked, 2fa_failed
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_attempts_user (user_id, created_at),
  KEY ix_login_attempts_ip (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`       VARCHAR(80)       NOT NULL,              -- reservations.create
  scope       ENUM('platform','property') NOT NULL,
  module      VARCHAR(40)       NOT NULL,              -- grouping in UI
  label_key   VARCHAR(120)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_key (`key`)
) ENGINE=InnoDB;

-- property_id NULL = system template role (Owner, Manager, Front Desk ...)
CREATE TABLE roles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED NULL,
  scope       ENUM('platform','property') NOT NULL,
  code        VARCHAR(40)     NOT NULL,
  name        VARCHAR(80)     NOT NULL,
  is_system   TINYINT(1)      NOT NULL DEFAULT 0,      -- system roles cannot be edited by tenants
  created_at  DATETIME        NOT NULL,
  updated_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_property_code (property_id, code),
  KEY ix_roles_scope (scope),
  CONSTRAINT fk_roles_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       BIGINT UNSIGNED   NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY ix_role_permissions_perm (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE platform_user_roles (
  user_id   BIGINT UNSIGNED NOT NULL,
  role_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_pur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pur_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subscription_plans (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30)       NOT NULL,
  name          VARCHAR(80)       NOT NULL,
  price         DECIMAL(14,2)     NOT NULL,
  currency_code CHAR(3)           NOT NULL,
  billing_cycle ENUM('monthly','quarterly','yearly') NOT NULL,
  trial_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grace_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_room_types SMALLINT UNSIGNED NULL,               -- NULL = unlimited
  max_units      SMALLINT UNSIGNED NULL,
  max_users      SMALLINT UNSIGNED NULL,
  features      JSON              NOT NULL,            -- {"booking_engine":true,"channel_manager":false}
  is_active     TINYINT(1)        NOT NULL DEFAULT 1,
  sort_order    SMALLINT          NOT NULL DEFAULT 0,
  created_at    DATETIME          NOT NULL,
  updated_at    DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_plans_code (code),
  CONSTRAINT fk_sp_currency FOREIGN KEY (currency_code) REFERENCES currencies (code)
) ENGINE=InnoDB;

-- one row per subscription period; the latest non-cancelled row is current
CREATE TABLE subscriptions (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  property_id     BIGINT UNSIGNED   NOT NULL,
  plan_id         SMALLINT UNSIGNED NOT NULL,
  status          ENUM('trial','active','grace','expired','suspended','cancelled') NOT NULL,
  starts_on       DATE              NOT NULL,
  ends_on         DATE              NOT NULL,
  grace_ends_on   DATE              NULL,
  price           DECIMAL(14,2)     NOT NULL,          -- snapshot of agreed price
  currency_code   CHAR(3)           NOT NULL,
  auto_renew      TINYINT(1)        NOT NULL DEFAULT 1,
  notes           VARCHAR(500)      NULL,
  created_by      BIGINT UNSIGNED   NULL,
  created_at      DATETIME          NOT NULL,
  updated_at      DATETIME          NOT NULL,
  PRIMARY KEY (id),
  KEY ix_subscriptions_property (property_id, status, ends_on),
  KEY ix_subscriptions_expiry (status, ends_on),
  CONSTRAINT fk_sub_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id)
) ENGINE=InnoDB;

-- append-only; no FKs so it never blocks writes and can be partitioned by month
CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NULL,
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(60)     NOT NULL,               -- reservation.created
  entity_type  VARCHAR(60)     NULL,
  entity_id    BIGINT UNSIGNED NULL,
  request_id   CHAR(26)        NULL,
  ip           VARBINARY(16)   NULL,
  user_agent   VARCHAR(255)    NULL,
  changes      JSON            NULL,                   -- {"before":{},"after":{}} – secrets redacted
  created_at   DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_property_time (property_id, created_at),
  KEY ix_audit_entity (entity_type, entity_id),
  KEY ix_audit_user_time (user_id, created_at)
) ENGINE=InnoDB;

-- grouped application errors for the Super Admin "System health" screen.
-- Full detail always goes to the log files; this table stores one row per fingerprint.
CREATE TABLE system_error_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fingerprint     BINARY(20)      NOT NULL,            -- sha1(class|file|line)
  level           ENUM('warning','error','critical') NOT NULL,
  source          ENUM('server','client','queue','scheduler') NOT NULL,
  exception_class VARCHAR(190)    NULL,
  message         VARCHAR(1000)   NOT NULL,
  location        VARCHAR(255)    NULL,                -- internal only, never sent to tenants
  last_request_id CHAR(26)        NULL,
  last_property_id BIGINT UNSIGNED NULL,
  last_user_id    BIGINT UNSIGNED NULL,
  occurrences     INT UNSIGNED    NOT NULL DEFAULT 1,
  first_seen_at   DATETIME        NOT NULL,
  last_seen_at    DATETIME        NOT NULL,
  resolved_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_error_fingerprint (fingerprint),
  KEY ix_error_last_seen (resolved_at, last_seen_at)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. PROPERTY
-- =====================================================================

CREATE TABLE properties (
  id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  code              VARCHAR(12)       NOT NULL,        -- OZ-7K4Q2M  (generated, shown to users, used in URLs)
  slug              VARCHAR(80)       NOT NULL,        -- booking engine URL
  name              VARCHAR(150)      NOT NULL,
  legal_name        VARCHAR(190)      NULL,
  property_type_id  SMALLINT UNSIGNED NOT NULL,
  status            ENUM('onboarding','active','suspended','inactive') NOT NULL DEFAULT 'onboarding',
  onboarding_step   VARCHAR(30)       NULL,            -- rate_plan, room_types, done
  phone             VARCHAR(30)       NULL,
  email             VARCHAR(190)      NULL,
  website           VARCHAR(255)      NULL,
  description       TEXT              NULL,
  logo_path         VARCHAR(255)      NULL,
  country_iso2      CHAR(2)           NOT NULL,
  state_id          INT UNSIGNED      NULL,
  city              VARCHAR(100)      NULL,
  postcode          VARCHAR(20)       NULL,
  address_line1     VARCHAR(190)      NULL,
  address_line2     VARCHAR(190)      NULL,
  latitude          DECIMAL(10,7)     NULL,
  longitude         DECIMAL(10,7)     NULL,
  maps_url          VARCHAR(500)      NULL,
  currency_code     CHAR(3)           NOT NULL,
  timezone          VARCHAR(64)       NOT NULL,        -- IANA, e.g. Asia/Kolkata
  default_language  VARCHAR(10)       NOT NULL DEFAULT 'en',
  date_format       VARCHAR(20)       NOT NULL DEFAULT 'DD MMM YYYY',
  number_format     VARCHAR(20)       NOT NULL DEFAULT 'en-IN',
  week_start        TINYINT UNSIGNED  NOT NULL DEFAULT 1,  -- 0=Sun 1=Mon
  check_in_time     TIME              NOT NULL DEFAULT '14:00:00',
  check_out_time    TIME              NOT NULL DEFAULT '11:00:00',
  tax_registration_no VARCHAR(30)     NULL,            -- GSTIN / VAT no
  business_date     DATE              NULL,            -- moved forward by night audit
  ari_version       INT UNSIGNED      NOT NULL DEFAULT 0,  -- bumped on any ARI change, used as cache key
  created_by        BIGINT UNSIGNED   NULL,
  created_at        DATETIME          NOT NULL,
  updated_at        DATETIME          NOT NULL,
  deleted_at        DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_properties_code (code),
  UNIQUE KEY uq_properties_slug (slug),
  KEY ix_properties_status (status),
  KEY ix_properties_type (property_type_id),
  CONSTRAINT fk_prop_type FOREIGN KEY (property_type_id) REFERENCES property_types (id),
  CONSTRAINT fk_prop_country FOREIGN KEY (country_iso2) REFERENCES countries (iso2),
  CONSTRAINT fk_prop_state FOREIGN KEY (state_id) REFERENCES states (id),
  CONSTRAINT fk_prop_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
  CONSTRAINT fk_prop_lang FOREIGN KEY (default_language) REFERENCES languages (code)
) ENGINE=InnoDB;

CREATE TABLE property_users (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  role_id      BIGINT UNSIGNED NOT NULL,
  is_owner     TINYINT(1)      NOT NULL DEFAULT 0,
  status       ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
  invited_by   BIGINT UNSIGNED NULL,
  joined_at    DATETIME        NULL,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_property_users (property_id, user_id),
  KEY ix_property_users_user (user_id, status),      -- property switcher list
  CONSTRAINT fk_pu_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_pu_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_pu_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

CREATE TABLE property_settings (
  property_id  BIGINT UNSIGNED NOT NULL,
  `key`        VARCHAR(80)     NOT NULL,               -- booking_engine.theme, invoice.footer ...
  value        JSON            NOT NULL,
  updated_at   DATETIME        NOT NULL,
  PRIMARY KEY (property_id, `key`),
  CONSTRAINT fk_ps_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE property_languages (
  property_id   BIGINT UNSIGNED NOT NULL,
  language_code VARCHAR(10)     NOT NULL,
  PRIMARY KEY (property_id, language_code),
  CONSTRAINT fk_pl_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_pl_language FOREIGN KEY (language_code) REFERENCES languages (code)
) ENGINE=InnoDB;

-- child / infant age definitions used by occupancy pricing
CREATE TABLE property_age_bands (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED  NOT NULL,
  code        ENUM('infant','child','teen') NOT NULL,
  min_age     TINYINT UNSIGNED NOT NULL,
  max_age     TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_age_bands (property_id, code),
  CONSTRAINT fk_ab_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT ck_age_band CHECK (min_age <= max_age)
) ENGINE=InnoDB;

-- per-property gap-free sequences (booking refs, folio nos, GST invoice series)
CREATE TABLE property_counters (
  property_id   BIGINT UNSIGNED NOT NULL,
  counter_key   VARCHAR(30)     NOT NULL,              -- RES, FOLIO, INV-2026-27
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, counter_key),
  CONSTRAINT fk_pc_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

-- translated guest-facing content (room type names, rate plan descriptions …)
CREATE TABLE content_translations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(40)     NOT NULL,                -- room_type, rate_plan, amenity
  entity_id   BIGINT UNSIGNED NOT NULL,
  locale      VARCHAR(10)     NOT NULL,
  field       VARCHAR(40)     NOT NULL,                -- name, description
  value       TEXT            NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_translation (entity_type, entity_id, locale, field),
  KEY ix_translation_property (property_id, locale),
  CONSTRAINT fk_ct_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. ACCOMMODATION: room types, PMS rooms (physical units), amenities
-- =====================================================================

CREATE TABLE room_types (
  id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  public_id      CHAR(26)         NOT NULL,
  property_id    BIGINT UNSIGNED  NOT NULL,
  code           VARCHAR(20)      NOT NULL,            -- DLX
  name           VARCHAR(120)     NOT NULL,
  description    TEXT             NULL,
  base_adults    TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- occupancy included in base price
  max_adults     TINYINT UNSIGNED NOT NULL,
  max_children   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_infants    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_occupancy  TINYINT UNSIGNED NOT NULL,            -- adults + children (infants excluded unless policy says)
  size_value     DECIMAL(7,2)     NULL,
  size_unit      ENUM('sqm','sqft') NULL,
  smoking_policy ENUM('non_smoking','smoking','both') NOT NULL DEFAULT 'non_smoking',
  view_label     VARCHAR(60)      NULL,
  sort_order     SMALLINT         NOT NULL DEFAULT 0,
  is_active      TINYINT(1)       NOT NULL DEFAULT 1,
  created_at     DATETIME         NOT NULL,
  updated_at     DATETIME         NOT NULL,
  deleted_at     DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_types_public (public_id),
  UNIQUE KEY uq_room_types_code (property_id, code),
  UNIQUE KEY uq_room_types_tenant (property_id, id),   -- target for tenant-safe composite FKs
  KEY ix_room_types_list (property_id, is_active, sort_order),
  CONSTRAINT fk_rt_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT ck_rt_occupancy CHECK (max_occupancy >= 1 AND base_adults <= max_adults)
) ENGINE=InnoDB;

CREATE TABLE room_type_beds (
  room_type_id BIGINT UNSIGNED   NOT NULL,
  bed_type_id  SMALLINT UNSIGNED NOT NULL,
  quantity     TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  PRIMARY KEY (room_type_id, bed_type_id),
  CONSTRAINT fk_rtb_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_rtb_bed FOREIGN KEY (bed_type_id) REFERENCES bed_types (id)
) ENGINE=InnoDB;

CREATE TABLE room_type_images (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NOT NULL,
  path         VARCHAR(255)    NOT NULL,
  alt_text     VARCHAR(190)    NULL,
  sort_order   SMALLINT        NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rti_room_type (room_type_id, sort_order),
  CONSTRAINT fk_rti_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- "PMS rooms": 101, 102, Villa A, Caravan 01
CREATE TABLE physical_units (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  property_id         BIGINT UNSIGNED NOT NULL,
  room_type_id        BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(30)     NOT NULL,        -- shown on calendar: 101
  floor               VARCHAR(10)     NULL,
  building            VARCHAR(40)     NULL,
  housekeeping_status ENUM('clean','dirty','inspected') NOT NULL DEFAULT 'clean',
  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  notes               VARCHAR(500)    NULL,
  sort_order          SMALLINT        NOT NULL DEFAULT 0,
  created_at          DATETIME        NOT NULL,
  updated_at          DATETIME        NOT NULL,
  deleted_at          DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_public (public_id),
  UNIQUE KEY uq_units_name (property_id, name),
  UNIQUE KEY uq_units_tenant (property_id, id),
  KEY ix_units_room_type (property_id, room_type_id, is_active, sort_order),
  CONSTRAINT fk_unit_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id)
) ENGINE=InnoDB;

-- out of order / maintenance / owner hold on a specific PMS room
CREATE TABLE unit_blocks (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  unit_id      BIGINT UNSIGNED NOT NULL,
  block_type   ENUM('out_of_order','maintenance','owner_hold') NOT NULL,
  start_date   DATE            NOT NULL,
  end_date     DATE            NOT NULL,               -- exclusive
  reason       VARCHAR(255)    NULL,
  created_by   BIGINT UNSIGNED NULL,
  released_at  DATETIME        NULL,
  released_by  BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_blocks_unit (unit_id, start_date),
  KEY ix_blocks_property_dates (property_id, start_date, end_date),
  CONSTRAINT fk_ub_unit FOREIGN KEY (property_id, unit_id) REFERENCES physical_units (property_id, id),
  CONSTRAINT ck_ub_dates CHECK (end_date > start_date)
) ENGINE=InnoDB;

-- property_id NULL = global catalogue maintained by E2X; non-null = custom amenity of a property
CREATE TABLE amenities (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED NULL,
  code        VARCHAR(40)     NOT NULL,
  name        VARCHAR(80)     NOT NULL,                -- for custom amenities
  label_key   VARCHAR(80)     NULL,                    -- for global amenities (translated)
  category    ENUM('room','bathroom','media','kitchen','property','service','other') NOT NULL DEFAULT 'room',
  icon        VARCHAR(40)     NULL,
  applies_to  SET('property','room_type','unit') NOT NULL DEFAULT 'room_type',
  ota_code    VARCHAR(20)     NULL,                    -- OpenTravel RMA / HAC code
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL,
  updated_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_amenities_code (property_id, code),
  KEY ix_amenities_category (category),
  CONSTRAINT fk_am_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE property_amenities (
  property_id BIGINT UNSIGNED NOT NULL,
  amenity_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (property_id, amenity_id),
  CONSTRAINT fk_pa_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_pa_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB;

CREATE TABLE room_type_amenities (
  room_type_id BIGINT UNSIGNED NOT NULL,
  amenity_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (room_type_id, amenity_id),
  KEY ix_rta_amenity (amenity_id),
  CONSTRAINT fk_rta_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_rta_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB;

-- exceptions only (e.g. only room 305 has a bathtub)
CREATE TABLE physical_unit_amenities (
  unit_id    BIGINT UNSIGNED NOT NULL,
  amenity_id BIGINT UNSIGNED NOT NULL,
  mode       ENUM('add','remove') NOT NULL DEFAULT 'add',
  PRIMARY KEY (unit_id, amenity_id),
  CONSTRAINT fk_pua_unit FOREIGN KEY (unit_id) REFERENCES physical_units (id) ON DELETE CASCADE,
  CONSTRAINT fk_pua_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 5. RATE PLANS & PRODUCTS (room type ↔ rate plan mapping)
-- =====================================================================

-- property_id NULL = standard meal plans (RO, BB, HB, FB, AI ...)
CREATE TABLE meal_plans (
  id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id        BIGINT UNSIGNED   NULL,
  code               VARCHAR(20)       NOT NULL,
  name               VARCHAR(80)       NOT NULL,
  includes_breakfast TINYINT(1)        NOT NULL DEFAULT 0,
  includes_lunch     TINYINT(1)        NOT NULL DEFAULT 0,
  includes_dinner    TINYINT(1)        NOT NULL DEFAULT 0,
  is_all_inclusive   TINYINT(1)        NOT NULL DEFAULT 0,
  ota_code           VARCHAR(10)       NULL,           -- OpenTravel MPT code
  is_active          TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meal_plans_code (property_id, code),
  CONSTRAINT fk_mp_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE cancellation_policies (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id   BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(30)     NOT NULL,
  name          VARCHAR(120)    NOT NULL,
  is_refundable TINYINT(1)      NOT NULL DEFAULT 1,
  description   TEXT            NULL,                  -- guest-facing text
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cp_code (property_id, code),
  UNIQUE KEY uq_cp_tenant (property_id, id),
  CONSTRAINT fk_cp_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

-- evaluated in order of hours_before_arrival DESC; first matching window applies
CREATE TABLE cancellation_policy_rules (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id            BIGINT UNSIGNED NOT NULL,
  applies_to           ENUM('cancellation','no_show') NOT NULL DEFAULT 'cancellation',
  hours_before_arrival INT UNSIGNED    NOT NULL,       -- window starts when less than this remains
  charge_type          ENUM('none','first_night','nights','percent','fixed','full') NOT NULL,
  charge_value         DECIMAL(14,4)   NULL,           -- nights count / percent / fixed amount
  PRIMARY KEY (id),
  KEY ix_cpr_policy (policy_id, applies_to, hours_before_arrival),
  CONSTRAINT fk_cpr_policy FOREIGN KEY (policy_id) REFERENCES cancellation_policies (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rate_plans (
  id                     BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  public_id              CHAR(26)          NOT NULL,
  property_id            BIGINT UNSIGNED   NOT NULL,
  code                   VARCHAR(20)       NOT NULL,   -- BAR, BB, NRF
  name                   VARCHAR(120)      NOT NULL,
  description            TEXT              NULL,
  meal_plan_id           SMALLINT UNSIGNED NOT NULL,
  cancellation_policy_id BIGINT UNSIGNED   NOT NULL,
  payment_type           ENUM('pay_at_property','prepay_full','deposit_percent','deposit_nights') NOT NULL DEFAULT 'pay_at_property',
  deposit_value          DECIMAL(14,4)     NULL,
  default_min_los        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  default_max_los        SMALLINT UNSIGNED NULL,
  min_advance_days       SMALLINT UNSIGNED NULL,     -- booking window
  max_advance_days       SMALLINT UNSIGNED NULL,
  sell_on_pms            TINYINT(1)        NOT NULL DEFAULT 1,
  sell_on_booking_engine TINYINT(1)        NOT NULL DEFAULT 1,
  sell_on_channels       TINYINT(1)        NOT NULL DEFAULT 1,
  sort_order             SMALLINT          NOT NULL DEFAULT 0,
  is_active              TINYINT(1)        NOT NULL DEFAULT 1,
  created_at             DATETIME          NOT NULL,
  updated_at             DATETIME          NOT NULL,
  deleted_at             DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_plans_public (public_id),
  UNIQUE KEY uq_rate_plans_code (property_id, code),
  UNIQUE KEY uq_rate_plans_tenant (property_id, id),
  KEY ix_rate_plans_list (property_id, is_active, sort_order),
  CONSTRAINT fk_rp_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_rp_meal FOREIGN KEY (meal_plan_id) REFERENCES meal_plans (id),
  CONSTRAINT fk_rp_cancel FOREIGN KEY (property_id, cancellation_policy_id) REFERENCES cancellation_policies (property_id, id)
) ENGINE=InnoDB;

-- PRODUCT = sellable combination "Deluxe – Breakfast". All daily rates, restrictions,
-- offers and OTA rate mappings attach to this id.
CREATE TABLE room_type_rate_plans (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)         NOT NULL,
  property_id         BIGINT UNSIGNED  NOT NULL,
  room_type_id        BIGINT UNSIGNED  NOT NULL,
  rate_plan_id        BIGINT UNSIGNED  NOT NULL,
  pricing_mode        ENUM('manual','derived') NOT NULL DEFAULT 'manual',
  parent_product_id   BIGINT UNSIGNED  NULL,           -- required when derived
  adjust_type         ENUM('percent','fixed','fixed_per_person') NULL,
  adjust_value        DECIMAL(14,4)    NULL,           -- -10.0000 = 10% cheaper
  inherit_restrictions TINYINT(1)      NOT NULL DEFAULT 1,
  default_price       DECIMAL(14,2)    NULL,           -- seeds new ari_daily rows (manual only)
  is_default          TINYINT(1)       NOT NULL DEFAULT 0,
  sort_order          SMALLINT         NOT NULL DEFAULT 0,
  is_active           TINYINT(1)       NOT NULL DEFAULT 1,
  created_at          DATETIME         NOT NULL,
  updated_at          DATETIME         NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_public (public_id),
  UNIQUE KEY uq_products_pair (room_type_id, rate_plan_id),
  UNIQUE KEY uq_products_tenant (property_id, id),
  KEY ix_products_property (property_id, is_active, room_type_id, sort_order),
  KEY ix_products_rate_plan (rate_plan_id),
  KEY ix_products_parent (parent_product_id),
  CONSTRAINT fk_prod_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id),
  CONSTRAINT fk_prod_rp FOREIGN KEY (property_id, rate_plan_id) REFERENCES rate_plans (property_id, id),
  CONSTRAINT fk_prod_parent FOREIGN KEY (property_id, parent_product_id) REFERENCES room_type_rate_plans (property_id, id),
  CONSTRAINT ck_prod_derived CHECK (
    (pricing_mode = 'manual'  AND parent_product_id IS NULL) OR
    (pricing_mode = 'derived' AND parent_product_id IS NOT NULL AND adjust_type IS NOT NULL AND adjust_value IS NOT NULL)
  )
) ENGINE=InnoDB;

-- occupancy-based price adjustments relative to the base price (base_adults of the room type)
--   guest_type=adult, guest_count=1  -> single-occupancy adjustment
--   guest_type=adult, guest_count=3  -> 3rd adult charge
--   guest_type=child + age_band_id    -> per child per night
CREATE TABLE product_occupancy_rules (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id   BIGINT UNSIGNED  NOT NULL,
  guest_type   ENUM('adult','child','infant') NOT NULL,
  guest_count  TINYINT UNSIGNED NOT NULL,              -- adult: total adults; child: nth child
  age_band_id  BIGINT UNSIGNED  NULL,
  adjust_type  ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  adjust_value DECIMAL(14,4)    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_occ_rule (product_id, guest_type, guest_count, age_band_id),
  CONSTRAINT fk_occ_product FOREIGN KEY (product_id) REFERENCES room_type_rate_plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_band FOREIGN KEY (age_band_id) REFERENCES property_age_bands (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 6. DAILY ARI  (Availability / Rates / Inventory)  – the hot tables
-- =====================================================================

-- One row per room type per night. total_units is maintained from the count of
-- active PMS rooms; ooo_units from unit_blocks(out_of_order/maintenance/owner_hold).
-- available = LEAST(total_units, COALESCE(sell_limit,total_units)) - ooo_units - sold - held
CREATE TABLE inventory_daily (
  room_type_id BIGINT UNSIGNED   NOT NULL,
  stay_date    DATE              NOT NULL,
  property_id  BIGINT UNSIGNED   NOT NULL,
  total_units  SMALLINT UNSIGNED NOT NULL,
  ooo_units    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sold         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  held         SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- booking-engine holds awaiting payment
  sell_limit   SMALLINT UNSIGNED NULL,                 -- optional cap, never above total_units
  stop_sell    TINYINT(1)        NOT NULL DEFAULT 0,   -- room-type level close-out
  updated_at   DATETIME          NOT NULL,
  PRIMARY KEY (room_type_id, stay_date),
  KEY ix_inv_property_date (property_id, stay_date),
  -- last line of defence: the database refuses any write that would overbook
  CONSTRAINT ck_inv_no_overbook CHECK (sold + held + ooo_units <= total_units),
  CONSTRAINT ck_inv_sell_limit CHECK (sell_limit IS NULL OR sell_limit <= total_units)
) ENGINE=InnoDB;

-- One row per product per night: price + restrictions read together.
-- Rows exist only for manual products and for derived products that override restrictions.
CREATE TABLE ari_daily (
  product_id   BIGINT UNSIGNED   NOT NULL,
  stay_date    DATE              NOT NULL,
  property_id  BIGINT UNSIGNED   NOT NULL,
  price        DECIMAL(14,2)     NULL,                 -- base-occupancy price; NULL on derived rows
  min_los      SMALLINT UNSIGNED NULL,
  max_los      SMALLINT UNSIGNED NULL,
  min_los_arrival SMALLINT UNSIGNED NULL,              -- MinLOS applied only on arrival date (OTA style)
  cta          TINYINT(1)        NOT NULL DEFAULT 0,   -- closed to arrival
  ctd          TINYINT(1)        NOT NULL DEFAULT 0,   -- closed to departure
  stop_sell    TINYINT(1)        NOT NULL DEFAULT 0,
  cutoff_days  SMALLINT UNSIGNED NULL,                 -- min days before arrival to book
  updated_at   DATETIME          NOT NULL,
  PRIMARY KEY (product_id, stay_date),
  KEY ix_ari_property_date (property_id, stay_date)
) ENGINE=InnoDB;

-- optional per-date price for a specific adult count (only written when used)
CREATE TABLE ari_daily_occupancy (
  product_id   BIGINT UNSIGNED  NOT NULL,
  stay_date    DATE             NOT NULL,
  adults       TINYINT UNSIGNED NOT NULL,
  property_id  BIGINT UNSIGNED  NOT NULL,
  price        DECIMAL(14,2)    NOT NULL,
  updated_at   DATETIME         NOT NULL,
  PRIMARY KEY (product_id, stay_date, adults),
  KEY ix_ario_property_date (property_id, stay_date)
) ENGINE=InnoDB;

-- range-level change journal: audit trail for calendar edits + delta feed for the channel manager
CREATE TABLE ari_change_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  scope        ENUM('inventory','rate','restriction') NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  product_id   BIGINT UNSIGNED NULL,
  date_from    DATE            NOT NULL,
  date_to      DATE            NOT NULL,               -- inclusive
  weekdays     TINYINT UNSIGNED NOT NULL DEFAULT 127,  -- bitmask Mon=1 … Sun=64
  payload      JSON            NOT NULL,               -- {"price":4500,"min_los":2}
  source       ENUM('user','reservation','system','channel') NOT NULL,
  user_id      BIGINT UNSIGNED NULL,
  created_at   DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_acl_property (property_id, id),               -- channel manager reads "everything after id X"
  KEY ix_acl_product (product_id, date_from)
) ENGINE=InnoDB;

-- =====================================================================
-- 7. GUESTS & RESERVATIONS
-- =====================================================================

CREATE TABLE booking_sources (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED   NULL,                  -- NULL = system source
  code        VARCHAR(30)       NOT NULL,              -- walk_in, phone, email, booking_engine, ota
  name        VARCHAR(80)       NOT NULL,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_sources (property_id, code),
  CONSTRAINT fk_bs_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE guests (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id        CHAR(26)        NOT NULL,
  property_id      BIGINT UNSIGNED NOT NULL,
  first_name       VARCHAR(80)     NOT NULL,
  last_name        VARCHAR(80)     NULL,
  email            VARCHAR(190)    NULL,
  email_lc         VARCHAR(190)    GENERATED ALWAYS AS (LOWER(email)) STORED,
  phone_e164       VARCHAR(20)     NULL,               -- +919876543210
  phone_rev        VARCHAR(20)     GENERATED ALWAYS AS (REVERSE(phone_e164)) STORED, -- "last digits" search
  country_iso2     CHAR(2)         NULL,
  nationality_iso2 CHAR(2)         NULL,
  date_of_birth    DATE            NULL,
  address_line1    VARCHAR(190)    NULL,
  address_line2    VARCHAR(190)    NULL,
  city             VARCHAR(100)    NULL,
  state_id         INT UNSIGNED    NULL,
  postcode         VARCHAR(20)     NULL,
  company_name     VARCHAR(190)    NULL,
  company_tax_no   VARCHAR(30)     NULL,               -- guest GSTIN for B2B invoice
  id_type          ENUM('passport','national_id','driving_licence','voter_id','other') NULL,
  id_number_enc    TEXT            NULL,               -- encrypted at rest
  id_number_hash   BINARY(32)      NULL,               -- HMAC blind index for exact lookup
  id_issuing_iso2  CHAR(2)         NULL,
  id_expiry        DATE            NULL,
  is_vip           TINYINT(1)      NOT NULL DEFAULT 0,
  marketing_consent TINYINT(1)     NOT NULL DEFAULT 0,
  notes            VARCHAR(1000)   NULL,
  created_at       DATETIME        NOT NULL,
  updated_at       DATETIME        NOT NULL,
  anonymized_at    DATETIME        NULL,               -- GDPR/DPDP erasure keeps the row, wipes PII
  PRIMARY KEY (id),
  UNIQUE KEY uq_guests_public (public_id),
  UNIQUE KEY uq_guests_tenant (property_id, id),
  KEY ix_guests_email (property_id, email_lc),
  KEY ix_guests_phone (property_id, phone_e164),
  KEY ix_guests_phone_rev (property_id, phone_rev),
  KEY ix_guests_name (property_id, last_name, first_name),
  KEY ix_guests_first (property_id, first_name),
  KEY ix_guests_idhash (property_id, id_number_hash),
  FULLTEXT KEY ft_guests_name (first_name, last_name, email) WITH PARSER ngram,
  CONSTRAINT fk_guest_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  public_id        CHAR(26)          NOT NULL,
  property_id      BIGINT UNSIGNED   NOT NULL,
  booking_ref      VARCHAR(20)       NOT NULL,         -- human reference shown everywhere
  status           ENUM('inquiry','hold','pending','confirmed','checked_in','checked_out','cancelled','no_show') NOT NULL,
  source_id        SMALLINT UNSIGNED NOT NULL,
  primary_guest_id BIGINT UNSIGNED   NULL,
  guest_name       VARCHAR(170)      NOT NULL,         -- snapshot for list/search without joins
  guest_phone      VARCHAR(20)       NULL,             -- snapshot
  check_in         DATE              NOT NULL,         -- earliest room arrival
  check_out        DATE              NOT NULL,         -- latest room departure
  nights           SMALLINT UNSIGNED NOT NULL,
  room_count       TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  adults           SMALLINT UNSIGNED NOT NULL,
  children         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  infants          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  currency_code    CHAR(3)           NOT NULL,
  room_total       DECIMAL(14,2)     NOT NULL DEFAULT 0,
  discount_total   DECIMAL(14,2)     NOT NULL DEFAULT 0,
  extras_total     DECIMAL(14,2)     NOT NULL DEFAULT 0,
  tax_total        DECIMAL(14,2)     NOT NULL DEFAULT 0,
  grand_total      DECIMAL(14,2)     NOT NULL DEFAULT 0,
  paid_total       DECIMAL(14,2)     NOT NULL DEFAULT 0,
  balance_due      DECIMAL(14,2)     GENERATED ALWAYS AS (grand_total - paid_total) STORED,
  payment_status   ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  hold_expires_at  DATETIME          NULL,
  idempotency_key  VARCHAR(64)       NULL,
  special_requests VARCHAR(1000)     NULL,
  internal_notes   TEXT              NULL,
  cancelled_at     DATETIME          NULL,
  cancel_reason    VARCHAR(255)      NULL,
  cancellation_fee DECIMAL(14,2)     NULL,
  confirmed_at     DATETIME          NULL,
  created_by       BIGINT UNSIGNED   NULL,
  created_at       DATETIME          NOT NULL,
  updated_at       DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_res_public (public_id),
  UNIQUE KEY uq_res_ref (property_id, booking_ref),
  UNIQUE KEY uq_res_idempotency (property_id, idempotency_key),
  UNIQUE KEY uq_res_tenant (property_id, id),
  KEY ix_res_arrivals (property_id, check_in, status),
  KEY ix_res_departures (property_id, check_out, status),
  KEY ix_res_status (property_id, status, check_in),
  KEY ix_res_created (property_id, created_at),
  KEY ix_res_guest (property_id, primary_guest_id),
  KEY ix_res_guest_name (property_id, guest_name),
  KEY ix_res_payment (property_id, payment_status, check_in),
  KEY ix_res_source (property_id, source_id, created_at),
  KEY ix_res_hold_expiry (status, hold_expires_at),
  CONSTRAINT fk_res_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_res_source FOREIGN KEY (source_id) REFERENCES booking_sources (id),
  CONSTRAINT fk_res_guest FOREIGN KEY (property_id, primary_guest_id) REFERENCES guests (property_id, id),
  CONSTRAINT ck_res_dates CHECK (check_out > check_in)
) ENGINE=InnoDB;

CREATE TABLE reservation_rooms (
  id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  property_id       BIGINT UNSIGNED   NOT NULL,
  reservation_id    BIGINT UNSIGNED   NOT NULL,
  room_type_id      BIGINT UNSIGNED   NOT NULL,
  rate_plan_id      BIGINT UNSIGNED   NOT NULL,
  product_id        BIGINT UNSIGNED   NOT NULL,
  status            ENUM('hold','pending','confirmed','checked_in','checked_out','cancelled','no_show') NOT NULL,
  check_in          DATE              NOT NULL,
  check_out         DATE              NOT NULL,
  adults            TINYINT UNSIGNED  NOT NULL,
  children          TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  infants           TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  child_ages        JSON              NULL,            -- [4, 9]
  rate_snapshot     JSON              NOT NULL,        -- frozen rate plan, meal plan, cancellation rules
  room_total        DECIMAL(14,2)     NOT NULL,
  discount_total    DECIMAL(14,2)     NOT NULL DEFAULT 0,
  tax_total         DECIMAL(14,2)     NOT NULL DEFAULT 0,
  grand_total       DECIMAL(14,2)     NOT NULL,
  checked_in_at     DATETIME          NULL,
  checked_out_at    DATETIME          NULL,
  checked_in_by     BIGINT UNSIGNED   NULL,
  checked_out_by    BIGINT UNSIGNED   NULL,
  sort_order        TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at        DATETIME          NOT NULL,
  updated_at        DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rr_tenant (property_id, id),
  KEY ix_rr_reservation (reservation_id, sort_order),
  KEY ix_rr_room_type_dates (property_id, room_type_id, check_in),
  KEY ix_rr_inhouse (property_id, status, check_out),
  CONSTRAINT fk_rr_res FOREIGN KEY (property_id, reservation_id) REFERENCES reservations (property_id, id),
  CONSTRAINT fk_rr_rt FOREIGN KEY (property_id, room_type_id) REFERENCES room_types (property_id, id),
  CONSTRAINT fk_rr_rp FOREIGN KEY (property_id, rate_plan_id) REFERENCES rate_plans (property_id, id),
  CONSTRAINT fk_rr_product FOREIGN KEY (property_id, product_id) REFERENCES room_type_rate_plans (property_id, id),
  CONSTRAINT ck_rr_dates CHECK (check_out > check_in)
) ENGINE=InnoDB;

-- source of truth for "what is sold": one row per reserved room per night, with price snapshot
CREATE TABLE reservation_room_nights (
  reservation_room_id BIGINT UNSIGNED NOT NULL,
  stay_date           DATE            NOT NULL,
  property_id         BIGINT UNSIGNED NOT NULL,
  room_type_id        BIGINT UNSIGNED NOT NULL,
  product_id          BIGINT UNSIGNED NOT NULL,
  base_price          DECIMAL(14,2)   NOT NULL,
  occupancy_adjust    DECIMAL(14,2)   NOT NULL DEFAULT 0,
  discount            DECIMAL(14,2)   NOT NULL DEFAULT 0,
  net_price           DECIMAL(14,2)   NOT NULL,         -- taxable value for the night
  tax_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  is_active           TINYINT(1)      NOT NULL DEFAULT 1, -- 0 after cancellation (row kept for history)
  PRIMARY KEY (reservation_room_id, stay_date),
  KEY ix_rrn_inventory (property_id, room_type_id, stay_date, is_active),  -- reconciliation + reports
  CONSTRAINT fk_rrn_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id)
) ENGINE=InnoDB;

-- PMS room occupation per night. PK (unit_id, stay_date) makes it impossible
-- for two reservations (or a reservation and a block) to hold the same room on the same night.
CREATE TABLE unit_nights (
  unit_id             BIGINT UNSIGNED NOT NULL,
  stay_date           DATE            NOT NULL,
  property_id         BIGINT UNSIGNED NOT NULL,
  kind                ENUM('reservation','block') NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  unit_block_id       BIGINT UNSIGNED NULL,
  created_at          DATETIME        NOT NULL,
  PRIMARY KEY (unit_id, stay_date),
  KEY ix_un_tapechart (property_id, stay_date),
  KEY ix_un_rr (reservation_room_id),
  KEY ix_un_block (unit_block_id),
  CONSTRAINT fk_un_unit FOREIGN KEY (property_id, unit_id) REFERENCES physical_units (property_id, id),
  CONSTRAINT fk_un_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id),
  CONSTRAINT fk_un_block FOREIGN KEY (unit_block_id) REFERENCES unit_blocks (id),
  CONSTRAINT ck_un_kind CHECK (
    (kind = 'reservation' AND reservation_room_id IS NOT NULL AND unit_block_id IS NULL) OR
    (kind = 'block' AND unit_block_id IS NOT NULL AND reservation_room_id IS NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE reservation_guests (
  reservation_id      BIGINT UNSIGNED NOT NULL,
  guest_id            BIGINT UNSIGNED NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  is_primary          TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (reservation_id, guest_id),
  KEY ix_rg_guest (guest_id),
  CONSTRAINT fk_rg_res FOREIGN KEY (reservation_id) REFERENCES reservations (id),
  CONSTRAINT fk_rg_guest FOREIGN KEY (guest_id) REFERENCES guests (id),
  CONSTRAINT fk_rg_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id)
) ENGINE=InnoDB;

CREATE TABLE reservation_status_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  from_status    VARCHAR(20)     NULL,
  to_status      VARCHAR(20)     NOT NULL,
  user_id        BIGINT UNSIGNED NULL,
  note           VARCHAR(255)    NULL,
  created_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rsh_res (reservation_id, created_at),
  CONSTRAINT fk_rsh_res FOREIGN KEY (reservation_id) REFERENCES reservations (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 8. TAXES
-- =====================================================================

CREATE TABLE tax_categories (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30)       NOT NULL,            -- accommodation, food, service
  name          VARCHAR(80)       NOT NULL,
  default_sac_hsn VARCHAR(10)     NULL,                -- 9963 for accommodation (India)
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_categories (code)
) ENGINE=InnoDB;

-- property_id NULL = country template maintained by E2X; copied/linked to properties.
-- Slab rules (India room GST) use slab_min/slab_max on the per-unit-per-night tariff.
CREATE TABLE tax_rules (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  country_iso2   CHAR(2)           NOT NULL,
  property_id    BIGINT UNSIGNED   NULL,
  tax_category_id SMALLINT UNSIGNED NOT NULL,
  code           VARCHAR(30)       NOT NULL,
  name           VARCHAR(80)       NOT NULL,           -- shown on invoice
  tax_type       ENUM('gst','vat','sales','tourism','city','local','service_charge','other') NOT NULL,
  calc_type      ENUM('percent','fixed_per_night','fixed_per_person_night','fixed_per_stay') NOT NULL,
  rate           DECIMAL(9,4)      NOT NULL,           -- 18.0000 or fixed amount
  slab_basis     ENUM('none','unit_night_tariff') NOT NULL DEFAULT 'none',
  slab_min       DECIMAL(14,2)     NULL,               -- inclusive
  slab_max       DECIMAL(14,2)     NULL,               -- inclusive, NULL = no upper bound
  component_mode ENUM('single','gst_split') NOT NULL DEFAULT 'single', -- gst_split => CGST+SGST / IGST
  is_inclusive   TINYINT(1)        NOT NULL DEFAULT 0, -- price already includes this tax
  is_compound    TINYINT(1)        NOT NULL DEFAULT 0, -- applied on (price + previous taxes)
  priority       SMALLINT          NOT NULL DEFAULT 0,
  effective_from DATE              NOT NULL,
  effective_to   DATE              NULL,
  is_active      TINYINT(1)        NOT NULL DEFAULT 1,
  created_at     DATETIME          NOT NULL,
  updated_at     DATETIME          NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tax_lookup (property_id, tax_category_id, is_active, effective_from),
  KEY ix_tax_country (country_iso2, property_id),
  CONSTRAINT fk_tax_country FOREIGN KEY (country_iso2) REFERENCES countries (iso2),
  CONSTRAINT fk_tax_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_tax_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories (id)
) ENGINE=InnoDB;

-- optional narrowing of a rule to specific room types / rate plans
CREATE TABLE tax_rule_scopes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tax_rule_id  BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  rate_plan_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_trs_rule (tax_rule_id),
  CONSTRAINT fk_trs_rule FOREIGN KEY (tax_rule_id) REFERENCES tax_rules (id) ON DELETE CASCADE,
  CONSTRAINT fk_trs_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id),
  CONSTRAINT fk_trs_rp FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 9. OFFERS
-- =====================================================================

CREATE TABLE offers (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  public_id        CHAR(26)          NOT NULL,
  property_id      BIGINT UNSIGNED   NOT NULL,
  name             VARCHAR(120)      NOT NULL,
  code             VARCHAR(30)       NOT NULL,         -- internal code
  promo_code       VARCHAR(30)       NULL,             -- NULL = automatic offer
  discount_type    ENUM('percent','fixed_per_night','fixed_per_stay') NOT NULL,
  discount_value   DECIMAL(14,4)     NOT NULL,
  min_nights       SMALLINT UNSIGNED NULL,
  max_nights       SMALLINT UNSIGNED NULL,
  min_amount       DECIMAL(14,2)     NULL,
  booking_from     DATE              NULL,
  booking_to       DATE              NULL,
  stay_from        DATE              NULL,
  stay_to          DATE              NULL,
  weekdays         TINYINT UNSIGNED  NOT NULL DEFAULT 127, -- bitmask Mon=1 … Sun=64
  min_advance_days SMALLINT UNSIGNED NULL,             -- early bird
  max_advance_days SMALLINT UNSIGNED NULL,             -- last minute
  priority         SMALLINT          NOT NULL DEFAULT 0,
  is_stackable     TINYINT(1)        NOT NULL DEFAULT 0,
  max_redemptions  INT UNSIGNED      NULL,
  redemptions      INT UNSIGNED      NOT NULL DEFAULT 0,
  on_pms           TINYINT(1)        NOT NULL DEFAULT 1,
  on_booking_engine TINYINT(1)       NOT NULL DEFAULT 1,
  is_active        TINYINT(1)        NOT NULL DEFAULT 1,
  created_at       DATETIME          NOT NULL,
  updated_at       DATETIME          NOT NULL,
  deleted_at       DATETIME          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offers_public (public_id),
  UNIQUE KEY uq_offers_code (property_id, code),
  UNIQUE KEY uq_offers_promo (property_id, promo_code),
  KEY ix_offers_active (property_id, is_active, stay_from, stay_to),
  CONSTRAINT fk_offer_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

-- no rows = applies to every product
CREATE TABLE offer_scopes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id     BIGINT UNSIGNED NOT NULL,
  room_type_id BIGINT UNSIGNED NULL,
  rate_plan_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_os_offer (offer_id),
  CONSTRAINT fk_os_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_os_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id),
  CONSTRAINT fk_os_rp FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB;

-- extensible conditions beyond the common columns above
CREATE TABLE offer_conditions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id       BIGINT UNSIGNED NOT NULL,
  condition_type VARCHAR(40)     NOT NULL,             -- min_adults, guest_country, source ...
  operator       ENUM('eq','neq','gte','lte','in','not_in') NOT NULL,
  value          JSON            NOT NULL,
  PRIMARY KEY (id),
  KEY ix_oc_offer (offer_id),
  CONSTRAINT fk_oc_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE offer_applications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id         BIGINT UNSIGNED NOT NULL,
  offer_id            BIGINT UNSIGNED NOT NULL,
  reservation_id      BIGINT UNSIGNED NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  discount_amount     DECIMAL(14,2)   NOT NULL,
  snapshot            JSON            NOT NULL,        -- offer terms at time of booking
  created_at          DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_oa_offer (offer_id, created_at),
  KEY ix_oa_res (reservation_id),
  CONSTRAINT fk_oa_offer FOREIGN KEY (offer_id) REFERENCES offers (id),
  CONSTRAINT fk_oa_res FOREIGN KEY (property_id, reservation_id) REFERENCES reservations (property_id, id),
  CONSTRAINT fk_oa_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 10. FOLIO, PAYMENTS, GST INVOICES
-- =====================================================================

CREATE TABLE services (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  property_id     BIGINT UNSIGNED   NOT NULL,
  code            VARCHAR(30)       NOT NULL,
  name            VARCHAR(120)      NOT NULL,          -- Extra bed, Airport pickup
  price           DECIMAL(14,2)     NOT NULL,
  tax_category_id SMALLINT UNSIGNED NOT NULL,
  sac_hsn_code    VARCHAR(10)       NULL,
  posting_rule    ENUM('once','per_night','per_person','per_person_night') NOT NULL DEFAULT 'once',
  is_active       TINYINT(1)        NOT NULL DEFAULT 1,
  created_at      DATETIME          NOT NULL,
  updated_at      DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_code (property_id, code),
  CONSTRAINT fk_svc_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_svc_taxcat FOREIGN KEY (tax_category_id) REFERENCES tax_categories (id)
) ENGINE=InnoDB;

CREATE TABLE folios (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id    BIGINT UNSIGNED NOT NULL,
  reservation_id BIGINT UNSIGNED NOT NULL,
  folio_no       VARCHAR(20)     NOT NULL,
  bill_to        ENUM('guest','company','agent') NOT NULL DEFAULT 'guest',
  bill_to_guest_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  currency_code  CHAR(3)         NOT NULL,
  charges_total  DECIMAL(14,2)   NOT NULL DEFAULT 0,
  tax_total      DECIMAL(14,2)   NOT NULL DEFAULT 0,
  payments_total DECIMAL(14,2)   NOT NULL DEFAULT 0,
  closed_at      DATETIME        NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_folio_no (property_id, folio_no),
  UNIQUE KEY uq_folio_tenant (property_id, id),
  KEY ix_folio_res (reservation_id),
  CONSTRAINT fk_folio_res FOREIGN KEY (property_id, reservation_id) REFERENCES reservations (property_id, id)
) ENGINE=InnoDB;

-- immutable: corrections are voids + new lines, never updates/deletes
CREATE TABLE folio_lines (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id         BIGINT UNSIGNED NOT NULL,
  folio_id            BIGINT UNSIGNED NOT NULL,
  reservation_room_id BIGINT UNSIGNED NULL,
  business_date       DATE            NOT NULL,
  line_type           ENUM('room','service','discount','adjustment','cancellation_fee') NOT NULL,
  service_id          BIGINT UNSIGNED NULL,
  description         VARCHAR(190)    NOT NULL,
  sac_hsn_code        VARCHAR(10)     NULL,
  quantity            DECIMAL(10,2)   NOT NULL DEFAULT 1,
  unit_price          DECIMAL(14,4)   NOT NULL,
  amount              DECIMAL(14,2)   NOT NULL,        -- taxable value
  tax_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  is_void             TINYINT(1)      NOT NULL DEFAULT 0,
  void_of_line_id     BIGINT UNSIGNED NULL,
  void_reason         VARCHAR(255)    NULL,
  posted_by           BIGINT UNSIGNED NULL,
  created_at          DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_fl_folio (folio_id, business_date),
  KEY ix_fl_revenue (property_id, business_date, line_type),
  CONSTRAINT fk_fl_folio FOREIGN KEY (property_id, folio_id) REFERENCES folios (property_id, id),
  CONSTRAINT fk_fl_rr FOREIGN KEY (reservation_room_id) REFERENCES reservation_rooms (id),
  CONSTRAINT fk_fl_service FOREIGN KEY (service_id) REFERENCES services (id),
  CONSTRAINT fk_fl_void FOREIGN KEY (void_of_line_id) REFERENCES folio_lines (id)
) ENGINE=InnoDB;

-- tax breakdown per folio line (CGST / SGST / IGST / city tax ...), snapshotted
CREATE TABLE folio_line_taxes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio_line_id   BIGINT UNSIGNED NOT NULL,
  tax_rule_id     BIGINT UNSIGNED NULL,
  component       VARCHAR(20)     NOT NULL,            -- CGST, SGST, IGST, VAT, CITY
  tax_name        VARCHAR(80)     NOT NULL,
  rate            DECIMAL(9,4)    NOT NULL,
  taxable_amount  DECIMAL(14,2)   NOT NULL,
  tax_amount      DECIMAL(14,2)   NOT NULL,
  PRIMARY KEY (id),
  KEY ix_flt_line (folio_line_id),
  CONSTRAINT fk_flt_line FOREIGN KEY (folio_line_id) REFERENCES folio_lines (id),
  CONSTRAINT fk_flt_rule FOREIGN KEY (tax_rule_id) REFERENCES tax_rules (id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id          CHAR(26)        NOT NULL,
  property_id        BIGINT UNSIGNED NOT NULL,
  reservation_id     BIGINT UNSIGNED NOT NULL,
  folio_id           BIGINT UNSIGNED NULL,
  kind               ENUM('payment','refund') NOT NULL DEFAULT 'payment',
  parent_payment_id  BIGINT UNSIGNED NULL,             -- refund -> original payment
  method             ENUM('cash','card','upi','bank_transfer','gateway','other') NOT NULL,
  gateway            VARCHAR(20)     NULL,             -- razorpay
  gateway_order_id   VARCHAR(64)     NULL,
  gateway_payment_id VARCHAR(64)     NULL,
  amount             DECIMAL(14,2)   NOT NULL,
  currency_code      CHAR(3)         NOT NULL,
  status             ENUM('pending','captured','failed','refunded','partially_refunded') NOT NULL,
  reference          VARCHAR(100)    NULL,             -- cheque / UTR / card last4
  received_by        BIGINT UNSIGNED NULL,
  received_at        DATETIME        NULL,
  created_at         DATETIME        NOT NULL,
  updated_at         DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_public (public_id),
  UNIQUE KEY uq_pay_gateway (gateway, gateway_payment_id),
  KEY ix_pay_res (reservation_id),
  KEY ix_pay_property_time (property_id, received_at),
  KEY ix_pay_order (gateway_order_id),
  CONSTRAINT fk_pay_res FOREIGN KEY (property_id, reservation_id) REFERENCES reservations (property_id, id),
  CONSTRAINT fk_pay_folio FOREIGN KEY (folio_id) REFERENCES folios (id),
  CONSTRAINT fk_pay_parent FOREIGN KEY (parent_payment_id) REFERENCES payments (id)
) ENGINE=InnoDB;

-- raw Razorpay webhooks; event_id unique => processed exactly once
CREATE TABLE payment_gateway_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway         VARCHAR(20)     NOT NULL,
  event_id        VARCHAR(64)     NOT NULL,
  event_type      VARCHAR(60)     NOT NULL,
  property_id     BIGINT UNSIGNED NULL,
  signature_valid TINYINT(1)      NOT NULL,
  payload         JSON            NOT NULL,
  processed_at    DATETIME        NULL,
  error           VARCHAR(500)    NULL,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pge_event (gateway, event_id),
  KEY ix_pge_pending (processed_at, created_at)
) ENGINE=InnoDB;

-- GST tax invoice / credit note. Immutable once issued; series per financial year.
CREATE TABLE invoices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  property_id         BIGINT UNSIGNED NOT NULL,
  folio_id            BIGINT UNSIGNED NOT NULL,
  invoice_type        ENUM('tax_invoice','credit_note') NOT NULL DEFAULT 'tax_invoice',
  original_invoice_id BIGINT UNSIGNED NULL,            -- for credit notes
  invoice_no          VARCHAR(16)     NOT NULL,        -- GST: max 16 chars, unique per FY
  financial_year      CHAR(7)         NOT NULL,        -- 2026-27
  invoice_date        DATE            NOT NULL,
  supplier_tax_no     VARCHAR(30)     NULL,            -- property GSTIN
  supplier_state_code VARCHAR(10)     NULL,
  bill_to_name        VARCHAR(190)    NOT NULL,
  bill_to_tax_no      VARCHAR(30)     NULL,            -- guest/company GSTIN (B2B)
  bill_to_address     VARCHAR(500)    NULL,
  place_of_supply     VARCHAR(10)     NULL,            -- state code; accommodation = property state
  currency_code       CHAR(3)         NOT NULL,
  taxable_total       DECIMAL(14,2)   NOT NULL,
  cgst_total          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  sgst_total          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  igst_total          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  other_tax_total     DECIMAL(14,2)   NOT NULL DEFAULT 0,
  round_off           DECIMAL(6,2)    NOT NULL DEFAULT 0,
  grand_total         DECIMAL(14,2)   NOT NULL,
  snapshot            JSON            NOT NULL,        -- full frozen invoice (lines, taxes, addresses)
  pdf_path            VARCHAR(255)    NULL,
  issued_by           BIGINT UNSIGNED NULL,
  cancelled_at        DATETIME        NULL,
  created_at          DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_public (public_id),
  UNIQUE KEY uq_inv_no (property_id, financial_year, invoice_no),
  KEY ix_inv_date (property_id, invoice_date),
  KEY ix_inv_folio (folio_id),
  CONSTRAINT fk_inv_folio FOREIGN KEY (property_id, folio_id) REFERENCES folios (property_id, id),
  CONSTRAINT fk_inv_original FOREIGN KEY (original_invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB;

-- =====================================================================
-- 11. REPORTING ROLLUP (built nightly / on change, never queried live tables)
-- =====================================================================

CREATE TABLE stats_daily (
  property_id     BIGINT UNSIGNED   NOT NULL,
  stay_date       DATE              NOT NULL,
  room_type_id    BIGINT UNSIGNED   NOT NULL,
  units_total     SMALLINT UNSIGNED NOT NULL,
  units_ooo       SMALLINT UNSIGNED NOT NULL,
  rooms_sold      SMALLINT UNSIGNED NOT NULL,
  room_revenue    DECIMAL(14,2)     NOT NULL,
  arrivals        SMALLINT UNSIGNED NOT NULL,
  departures      SMALLINT UNSIGNED NOT NULL,
  cancellations   SMALLINT UNSIGNED NOT NULL,
  no_shows        SMALLINT UNSIGNED NOT NULL,
  refreshed_at    DATETIME          NOT NULL,
  PRIMARY KEY (property_id, stay_date, room_type_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 12. CHANNEL MANAGER (Phase 8 – defined now so core tables never change)
-- =====================================================================

CREATE TABLE channel_connections (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id   BIGINT UNSIGNED NOT NULL,
  provider      VARCHAR(30)     NOT NULL,              -- booking_com, expedia, agoda, airbnb
  external_hotel_id VARCHAR(64) NOT NULL,
  credentials   TEXT            NULL,                  -- encrypted
  status        ENUM('pending','active','paused','error','disconnected') NOT NULL DEFAULT 'pending',
  last_ari_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- high-water mark in ari_change_log
  settings      JSON            NULL,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cc (property_id, provider),
  CONSTRAINT fk_cc_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB;

CREATE TABLE channel_room_mappings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id    BIGINT UNSIGNED NOT NULL,
  room_type_id     BIGINT UNSIGNED NOT NULL,
  external_room_id VARCHAR(64)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_ext (connection_id, external_room_id),
  UNIQUE KEY uq_crm_rt (connection_id, room_type_id),
  CONSTRAINT fk_crm_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_crm_rt FOREIGN KEY (room_type_id) REFERENCES room_types (id)
) ENGINE=InnoDB;

CREATE TABLE channel_rate_plan_mappings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id    BIGINT UNSIGNED NOT NULL,
  product_id       BIGINT UNSIGNED NOT NULL,           -- maps a PRODUCT, not a bare rate plan
  external_rate_id VARCHAR(64)     NOT NULL,
  markup_type      ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  markup_value     DECIMAL(14,4)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crpm_ext (connection_id, external_rate_id),
  UNIQUE KEY uq_crpm_prod (connection_id, product_id),
  CONSTRAINT fk_crpm_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_crpm_prod FOREIGN KEY (product_id) REFERENCES room_type_rate_plans (id)
) ENGINE=InnoDB;

CREATE TABLE channel_sync_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  direction     ENUM('outbound','inbound') NOT NULL,
  message_type  VARCHAR(40)     NOT NULL,              -- ari_update, reservation_new ...
  status        ENUM('queued','sent','success','failed','retrying') NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  request_body  MEDIUMTEXT      NULL,
  response_body MEDIUMTEXT      NULL,
  error         VARCHAR(1000)   NULL,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_csl_conn (connection_id, created_at),
  KEY ix_csl_retry (status, updated_at),
  CONSTRAINT fk_csl_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id)
) ENGINE=InnoDB;

CREATE TABLE channel_reservations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id  BIGINT UNSIGNED NOT NULL,
  external_ref   VARCHAR(64)     NOT NULL,
  reservation_id BIGINT UNSIGNED NULL,
  version        INT UNSIGNED    NOT NULL DEFAULT 1,   -- modification counter from OTA
  status         ENUM('new','modified','cancelled','failed') NOT NULL,
  payload        JSON            NOT NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cr_ext (connection_id, external_ref),
  KEY ix_cr_res (reservation_id),
  CONSTRAINT fk_cr_conn FOREIGN KEY (connection_id) REFERENCES channel_connections (id),
  CONSTRAINT fk_cr_res FOREIGN KEY (reservation_id) REFERENCES reservations (id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 4 — guests, reservations, folios, payments, invoices.
| Table definitions are taken verbatim from database/schema/ozepms_schema_v1.sql
| (the approved Phase 0 schema), so keys, indexes and CHECK constraints match it exactly.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement(<<<'SQL'
CREATE TABLE booking_sources (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id BIGINT UNSIGNED   NULL,                  -- NULL = system source
  code        VARCHAR(30)       NOT NULL,              -- walk_in, phone, email, booking_engine, ota
  name        VARCHAR(80)       NOT NULL,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_sources (property_id, code),
  CONSTRAINT fk_bs_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_gateway_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('folio_line_taxes');
        Schema::dropIfExists('folio_lines');
        Schema::dropIfExists('folios');
        Schema::dropIfExists('services');
        Schema::dropIfExists('reservation_status_history');
        Schema::dropIfExists('reservation_guests');
        Schema::dropIfExists('unit_nights');
        Schema::dropIfExists('reservation_room_nights');
        Schema::dropIfExists('reservation_rooms');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('booking_sources');
        Schema::enableForeignKeyConstraints();
    }
};

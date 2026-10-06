<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 4 (billing) — columns and tables the billing screens and safety rules need on top of
| the Phase 0 schema:
|  * folio_lines.posting_key + live_key: one live line per posting (room night, retried charge),
|    so a retry or a second night-audit run never posts twice; a voided line frees its key.
|  * folio_lines.public_id: lines are addressed from pages (void) without internal ids.
|  * folio_lines.invoice_id: which tax invoice / credit note a line was billed on.
|  * folio_lines.tax_category_id: the category the taxes were calculated for (re-calculation, reports).
|  * payments.idempotency_key: a double click or retry records one payment.
|  * payments.is_deposit, refunded_amount, notes, failure_reason.
|  * services.description / sort_order for the services setup page.
|  * night_audit_runs: one row per property and business date (idempotent night audit).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE folio_lines
  ADD COLUMN public_id       CHAR(26)          NULL AFTER id,
  ADD COLUMN tax_category_id SMALLINT UNSIGNED NULL AFTER service_id,
  ADD COLUMN posting_key     VARCHAR(80)       NULL AFTER void_reason,
  ADD COLUMN live_key        VARCHAR(80)       GENERATED ALWAYS AS (IF(is_void = 0, posting_key, NULL)) STORED,
  ADD COLUMN invoice_id      BIGINT UNSIGNED   NULL AFTER posted_by,
  ADD UNIQUE KEY uq_fl_public (public_id),
  ADD UNIQUE KEY uq_fl_live_key (property_id, live_key),
  ADD KEY ix_fl_invoice (invoice_id)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE payments
  ADD COLUMN is_deposit      TINYINT(1)    NOT NULL DEFAULT 0 AFTER method,
  ADD COLUMN refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount,
  ADD COLUMN notes           VARCHAR(255)  NULL AFTER reference,
  ADD COLUMN failure_reason  VARCHAR(255)  NULL AFTER notes,
  ADD COLUMN idempotency_key VARCHAR(64)   NULL AFTER failure_reason,
  ADD UNIQUE KEY uq_pay_idempotency (property_id, idempotency_key)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE services
  ADD COLUMN public_id   CHAR(26)          NULL AFTER id,
  ADD COLUMN description VARCHAR(255)      NULL AFTER name,
  ADD COLUMN sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active,
  ADD UNIQUE KEY uq_services_public (public_id),
  ADD KEY ix_services_active (property_id, is_active, sort_order)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE night_audit_runs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id    BIGINT UNSIGNED NOT NULL,
  business_date  DATE            NOT NULL,
  status         ENUM('running','completed','failed') NOT NULL,
  stats          JSON            NULL,
  error          VARCHAR(500)    NULL,
  run_by         BIGINT UNSIGNED NULL,
  started_at     DATETIME        NOT NULL,
  finished_at    DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nar_day (property_id, business_date),
  CONSTRAINT fk_nar_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audit_runs');
        DB::statement('ALTER TABLE services DROP INDEX ix_services_active, DROP INDEX uq_services_public, DROP COLUMN sort_order, DROP COLUMN description, DROP COLUMN public_id');
        DB::statement('ALTER TABLE payments DROP INDEX uq_pay_idempotency, DROP COLUMN idempotency_key, DROP COLUMN failure_reason, DROP COLUMN notes, DROP COLUMN refunded_amount, DROP COLUMN is_deposit');
        DB::statement('ALTER TABLE folio_lines DROP INDEX ix_fl_invoice, DROP INDEX uq_fl_live_key, DROP INDEX uq_fl_public, DROP COLUMN invoice_id, DROP COLUMN live_key, DROP COLUMN posting_key, DROP COLUMN tax_category_id, DROP COLUMN public_id');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 4 (reservations) — columns and tables needed by the approved screens that the
| Phase 0 schema did not have:
|  * guests: per-property guest number (G-000001), title, guest type, tags, preferences
|  * reservations: arrival / departure time, purpose, market, travel agent, company,
|    channel reference, last modifier; index for the "Group" tab
|  * notes (reservation and guest notes, history tab) and guest_documents (metadata of
|    uploaded ID scans etc.)
|  * system booking sources
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE guests
  ADD COLUMN guest_no    INT UNSIGNED NULL AFTER property_id,
  ADD COLUMN title       VARCHAR(10)  NULL AFTER guest_no,
  ADD COLUMN guest_type  ENUM('individual','corporate','group','travel_agent') NOT NULL DEFAULT 'individual' AFTER title,
  ADD COLUMN tags        JSON         NULL AFTER notes,
  ADD COLUMN preferences VARCHAR(1000) NULL AFTER tags,
  ADD UNIQUE KEY uq_guests_no (property_id, guest_no),
  ADD KEY ix_guests_created (property_id, created_at)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE reservations
  ADD COLUMN arrival_time   TIME         NULL AFTER check_out,
  ADD COLUMN departure_time TIME         NULL AFTER arrival_time,
  ADD COLUMN purpose        VARCHAR(30)  NULL AFTER special_requests,
  ADD COLUMN market         VARCHAR(30)  NULL AFTER purpose,
  ADD COLUMN travel_agent   VARCHAR(120) NULL AFTER market,
  ADD COLUMN company_name   VARCHAR(190) NULL AFTER travel_agent,
  ADD COLUMN channel_ref    VARCHAR(64)  NULL AFTER company_name,
  ADD COLUMN updated_by     BIGINT UNSIGNED NULL AFTER created_by,
  ADD KEY ix_res_rooms (property_id, room_count, check_in),
  ADD KEY ix_res_checkin_date (property_id, check_in, check_out)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE notes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  BIGINT UNSIGNED NOT NULL,
  subject_type ENUM('reservation','guest') NOT NULL,
  subject_id   BIGINT UNSIGNED NOT NULL,
  body         VARCHAR(2000)   NOT NULL,
  user_id      BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notes_subject (property_id, subject_type, subject_id, created_at),
  CONSTRAINT fk_notes_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE guest_documents (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id      CHAR(26)        NOT NULL,
  property_id    BIGINT UNSIGNED NOT NULL,
  guest_id       BIGINT UNSIGNED NOT NULL,
  reservation_id BIGINT UNSIGNED NULL,
  doc_type       ENUM('id_front','id_back','passport','visa','registration_card','other') NOT NULL DEFAULT 'other',
  file_name      VARCHAR(190)    NOT NULL,
  path           VARCHAR(255)    NOT NULL,             -- private disk, never public
  mime           VARCHAR(100)    NOT NULL,
  size_bytes     INT UNSIGNED    NOT NULL,
  uploaded_by    BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gdoc_public (public_id),
  KEY ix_gdoc_guest (property_id, guest_id, created_at),
  KEY ix_gdoc_res (property_id, reservation_id),
  CONSTRAINT fk_gdoc_guest FOREIGN KEY (property_id, guest_id) REFERENCES guests (property_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $sources = [
            ['direct', 'Direct'], ['walk_in', 'Walk-in'], ['phone', 'Phone'], ['email', 'Email'],
            ['booking_engine', 'Booking Engine'], ['ota', 'OTA'], ['travel_agent', 'Travel Agent'], ['corporate', 'Corporate'],
        ];
        foreach ($sources as [$code, $name]) {
            // property_id NULL = system source; NULL is not unique in MySQL, so check first.
            if (! DB::table('booking_sources')->whereNull('property_id')->where('code', $code)->exists()) {
                DB::table('booking_sources')->insert(['property_id' => null, 'code' => $code, 'name' => $name, 'is_active' => 1]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_documents');
        Schema::dropIfExists('notes');
        DB::statement('ALTER TABLE reservations DROP KEY ix_res_rooms, DROP KEY ix_res_checkin_date, DROP COLUMN arrival_time, DROP COLUMN departure_time, DROP COLUMN purpose, DROP COLUMN market, DROP COLUMN travel_agent, DROP COLUMN company_name, DROP COLUMN channel_ref, DROP COLUMN updated_by');
        DB::statement('ALTER TABLE guests DROP KEY uq_guests_no, DROP KEY ix_guests_created, DROP COLUMN guest_no, DROP COLUMN title, DROP COLUMN guest_type, DROP COLUMN tags, DROP COLUMN preferences');
    }
};

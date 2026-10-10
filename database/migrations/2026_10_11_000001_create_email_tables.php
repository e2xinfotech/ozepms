<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| E-mail: the SMTP account used to send (one row per property, and one row with no property for the
| platform default) and a log of every e-mail the application sent or tried to send.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE mail_configs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id   BIGINT UNSIGNED NULL,
  host          VARCHAR(190)    NULL,
  port          SMALLINT UNSIGNED NULL,
  encryption    VARCHAR(10)     NOT NULL DEFAULT 'tls',
  username      VARCHAR(190)    NULL,
  password      TEXT            NULL,
  from_address  VARCHAR(190)    NULL,
  from_name     VARCHAR(120)    NULL,
  reply_to      VARCHAR(190)    NULL,
  events        JSON            NULL,
  send_for_channels TINYINT(1)  NOT NULL DEFAULT 0,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mail_configs_property (property_id),
  CONSTRAINT fk_mail_configs_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::statement(<<<'SQL'
CREATE TABLE email_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id      CHAR(26)        NOT NULL,
  property_id    BIGINT UNSIGNED NULL,
  reservation_id BIGINT UNSIGNED NULL,
  event          VARCHAR(40)     NOT NULL,
  to_email       VARCHAR(190)    NOT NULL,
  to_name        VARCHAR(190)    NULL,
  subject        VARCHAR(255)    NOT NULL,
  body_html      MEDIUMTEXT      NOT NULL,
  status         VARCHAR(10)     NOT NULL DEFAULT 'queued',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error          VARCHAR(500)    NULL,
  sent_at        DATETIME        NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_logs_public (public_id),
  KEY ix_email_logs_property (property_id, created_at),
  KEY ix_email_logs_reservation (reservation_id, event),
  CONSTRAINT fk_email_logs_property FOREIGN KEY (property_id) REFERENCES properties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS email_logs');
        DB::statement('DROP TABLE IF EXISTS mail_configs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Phase 6 — property API keys for the versioned API (/api/v1): the hotel's website, apps and
| partners. The key itself is shown once; only its SHA-256 hash is stored. Looked up by prefix.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE api_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id     CHAR(26)        NOT NULL,
  property_id   BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(80)     NOT NULL,
  prefix        CHAR(12)        NOT NULL,
  key_hash      CHAR(64)        NOT NULL,
  abilities     JSON            NOT NULL,
  last_used_at  DATETIME        NULL,
  last_used_ip  VARCHAR(45)     NULL,
  expires_at    DATETIME        NULL,
  revoked_at    DATETIME        NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME        NOT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_keys_public (public_id),
  UNIQUE KEY uq_api_keys_prefix (prefix),
  KEY ix_api_keys_property (property_id, revoked_at),
  CONSTRAINT fk_api_keys_property FOREIGN KEY (property_id) REFERENCES properties (id),
  CONSTRAINT fk_api_keys_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS api_keys');
    }
};

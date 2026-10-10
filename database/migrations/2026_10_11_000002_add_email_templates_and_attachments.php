<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| E-mail: the hotel's own wording per e-mail (templates), how many days before arrival the pre-arrival
| e-mail goes, whether guest e-mails are sent on behalf of the hotel's own address, and the files
| attached to a logged e-mail (the invoice PDF is rebuilt from the frozen invoice when it is sent).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE mail_configs ADD COLUMN templates JSON NULL AFTER events, ADD COLUMN pre_arrival_days TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER templates, ADD COLUMN on_behalf TINYINT(1) NOT NULL DEFAULT 1 AFTER pre_arrival_days");
        DB::statement('ALTER TABLE email_logs ADD COLUMN attachments JSON NULL AFTER body_html');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE email_logs DROP COLUMN attachments');
        DB::statement('ALTER TABLE mail_configs DROP COLUMN on_behalf, DROP COLUMN pre_arrival_days, DROP COLUMN templates');
    }
};

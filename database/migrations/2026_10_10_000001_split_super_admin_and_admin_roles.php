<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The platform role that used to be called "Super Admin" becomes "Admin"; its users keep their link.
 * A new, higher "Super Admin" role is created by the permission seeder (run it after migrating).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->whereNull('property_id')->where('scope', 'platform')->where('code', 'super_admin')->first();
        if ($role === null || DB::table('roles')->whereNull('property_id')->where('code', 'admin')->exists()) {
            return;
        }

        DB::table('roles')->where('id', $role->id)->update([
            'code' => 'admin',
            'name' => 'Admin',
            'color' => 'blue',
            'description' => 'roles.descriptions.admin',
        ]);
        Cache::forget('role_permissions:'.$role->id);
    }

    public function down(): void
    {
        // The two levels cannot be merged back safely; nothing to undo.
    }
};

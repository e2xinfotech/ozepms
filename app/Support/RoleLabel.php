<?php

namespace App\Support;

/**
 * Display name of a role in the current language. System roles keep their English name in the
 * database; it is translated with lang/{locale}/roles.php "names". Custom roles show their own name.
 */
final class RoleLabel
{
    private const SYSTEM_NAMES = [
        'super_admin' => 'Super Admin', 'it_support' => 'IT Support', 'owner' => 'Owner', 'hotel_manager' => 'Hotel Manager',
        'front_desk' => 'Front Desk', 'reservations' => 'Reservations', 'housekeeping' => 'Housekeeping', 'accounts' => 'Accounts',
        'revenue_manager' => 'Revenue Manager', 'guest_relations' => 'Guest Relations', 'sales_marketing' => 'Sales & Marketing',
    ];

    public static function name(?string $code, ?string $name): ?string
    {
        if ($code !== null && isset(self::SYSTEM_NAMES[$code]) && $name === self::SYSTEM_NAMES[$code]) {
            return __('roles.names.'.$code);
        }

        return $name;
    }
}

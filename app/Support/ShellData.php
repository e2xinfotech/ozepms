<?php

namespace App\Support;

use App\Domain\Access\AccessService;
use App\Domain\Subscription\SubscriptionService;
use App\Models\PropertyUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the sidebar and top bar need: menu (filtered by permission),
 * current property, property switcher list, user menu, languages.
 */
final class ShellData
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AccessService $access,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public static function guest(): array
    {
        return [
            'brand' => config('ozepms.brand'),
            'locales' => config('ozepms.locales.available'),
            'sso' => config('ozepms.sso'),
        ];
    }

    public function build(): array
    {
        $user = auth()->user();
        if (! $user) {
            return self::guest();
        }

        $platformPerms = $this->access->platformPermissions($user);
        $inProperty = $this->context->has();
        $property = $inProperty ? $this->context->property() : null;

        $menu = $inProperty
            ? $this->menu('property', fn ($perm) => $this->access->allows($user, $perm), ['property' => $property->code])
            : $this->menu('platform', fn ($perm) => in_array($perm, $platformPerms, true));

        $role = $inProperty
            ? ($this->context->membership()?->role?->name ?? __('roles.support_mode'))
            : ($user->platformRoles->first()?->name ?? null);

        return self::guest() + [
            'menu' => $menu,
            'is_platform' => count($platformPerms) > 0,
            'admin_url' => in_array('platform.dashboard', $platformPerms, true) ? route('admin.dashboard') : null,
            'support_mode' => $this->context->isSupportMode(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials(),
                'avatar' => $user->avatar_path ? Storage::url($user->avatar_path) : null,
                'role' => $role,
                'two_factor' => $user->hasTwoFactorEnabled(),
            ],
            'property' => $property ? [
                'code' => $property->code,
                'name' => $property->name,
                'location' => $property->locationLabel(),
                'image' => $property->cover_image_path ? Storage::url($property->cover_image_path) : null,
                'currency' => $property->currency_code,
                'timezone' => $property->timezone,
                // Regional settings of the property, applied by resources/js/lib/format.ts.
                'date_format' => $property->date_format,
                'number_format' => $property->number_format,
                'week_start' => (int) $property->week_start,
                'subscription' => $this->subscriptions->state($property),
            ] : null,
            'properties' => $this->switcher($user->id),
            'current_route' => Route::currentRouteName(),
        ];
    }

    private function menu(string $group, callable $allowed, array $params = []): array
    {
        $current = Route::currentRouteName();
        $items = [];

        foreach (config('navigation.'.$group) as $item) {
            if (! $allowed($item['permission'])) {
                continue;
            }
            $route = $item['route'] ?? null;
            $exists = $route !== null && Route::has($route);
            $items[] = [
                'key' => $item['key'],
                'label' => __($item['label']),
                'icon' => $item['icon'],
                'url' => $exists ? route($route, $params) : null,
                'active' => $exists && $current !== null && ($current === $route || str_starts_with($current, $route.'.')),
                'phase' => $exists ? null : 1,
            ];
        }

        return $items;
    }

    private function switcher(int $userId): array
    {
        return PropertyUser::query()->withoutGlobalScope('property')
            ->join('properties', 'properties.id', '=', 'property_users.property_id')
            ->leftJoin('countries', 'countries.iso2', '=', 'properties.country_iso2')
            ->where('property_users.user_id', $userId)
            ->where('property_users.status', 'active')
            ->whereNull('properties.deleted_at')
            ->whereIn('properties.status', ['onboarding', 'active'])
            ->orderBy('properties.name')
            ->limit(200)
            ->get(['properties.code', 'properties.name', 'properties.city', 'countries.name as country'])
            ->map(fn ($p) => [
                'code' => $p->code,
                'name' => $p->name,
                'location' => collect([$p->city, $p->country])->filter()->implode(', '),
            ])->all();
    }
}

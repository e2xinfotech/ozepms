<?php

namespace App\Support;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PropertyType;
use App\Models\SubscriptionPlan;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;

/**
 * Option lists for forms (cached; reference data rarely changes).
 */
final class Lookups
{
    public static function countries(): array
    {
        return Cache::remember('lookups:countries', 86400, fn () => Country::query()->where('is_active', true)->orderBy('name')
            ->get(['iso2', 'name', 'phone_code', 'currency_code', 'default_timezone'])
            ->map(fn ($c) => ['value' => $c->iso2, 'label' => $c->name, 'phone' => $c->phone_code, 'currency' => $c->currency_code, 'timezone' => $c->default_timezone])
            ->all());
    }

    public static function currencies(): array
    {
        return Cache::remember('lookups:currencies', 86400, fn () => Currency::query()->where('is_active', true)->orderBy('code')
            ->get(['code', 'name'])->map(fn ($c) => ['value' => $c->code, 'label' => $c->code.' — '.$c->name])->all());
    }

    public static function timezones(): array
    {
        return Cache::remember('lookups:timezones', 86400, fn () => collect(DateTimeZone::listIdentifiers())
            ->map(function ($tz) {
                $offset = (new \DateTime('now', new DateTimeZone($tz)))->format('P');

                return ['value' => $tz, 'label' => "(GMT{$offset}) ".str_replace('_', ' ', $tz)];
            })->all());
    }

    public static function propertyTypes(): array
    {
        return PropertyType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'label_key'])
            ->map(fn ($t) => ['value' => $t->id, 'code' => $t->code, 'label' => __($t->label_key)])->all();
    }

    public static function languages(): array
    {
        return Language::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'native_name'])
            ->map(fn ($l) => ['value' => $l->code, 'label' => $l->native_name])->all();
    }

    public static function plans(): array
    {
        return SubscriptionPlan::query()->where('is_active', true)->where('approval_status', 'approved')->orderBy('sort_order')->get(['id', 'name', 'price', 'currency_code', 'billing_cycle', 'trial_days'])
            ->map(fn ($p) => ['value' => $p->id, 'label' => $p->name, 'price' => $p->price, 'currency' => $p->currency_code, 'cycle' => $p->billing_cycle, 'trial_days' => $p->trial_days])->all();
    }

    /** Everything the property create/edit form needs. */
    /**
     * Owners to choose from when registering a property: people who already own a property and
     * people created as owners who have no property yet.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function owners(): array
    {
        return \App\Models\User::query()->where('is_platform_user', false)->whereIn('status', ['active', 'invited'])
            ->where(fn ($q) => $q->whereIn('id', \Illuminate\Support\Facades\DB::table('property_users')->where('is_owner', true)->select('user_id'))
                ->orWhereNotIn('id', \Illuminate\Support\Facades\DB::table('property_users')->select('user_id')))
            ->orderBy('name')->limit(500)->get(['public_id', 'name', 'email'])
            ->map(fn ($u) => ['value' => $u->public_id, 'label' => $u->name.' ('.$u->email.')'])->all();
    }

    public static function propertyForm(): array
    {
        return [
            'countries' => self::countries(),
            'currencies' => self::currencies(),
            'timezones' => self::timezones(),
            'types' => self::propertyTypes(),
            'languages' => self::languages(),
            'date_formats' => config('ozepms.property.date_formats'),
            'number_formats' => config('ozepms.property.number_formats'),
            'defaults' => [
                'country' => config('ozepms.property.default_country'),
                'currency' => config('ozepms.property.default_currency'),
                'timezone' => config('ozepms.property.default_timezone'),
                'check_in' => config('ozepms.property.default_check_in'),
                'check_out' => config('ozepms.property.default_check_out'),
            ],
        ];
    }
}

<?php

namespace App\Domain\Offers\Queries;

use App\Domain\Accommodation\Queries\HistoryQuery;
use App\Models\Offer;
use App\Support\Money;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Offer rows, the side panel and the edit form — labels are written here once for every screen. */
class OfferPresenter
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<int, string>|null */
    private ?array $roomTypes = null;

    /** @var array<int, string>|null */
    private ?array $ratePlans = null;

    /** @var array<string, string>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly PropertyContext $context,
        private readonly HistoryQuery $history,
    ) {}

    /** @return array<string, mixed> */
    public function row(Offer $o): array
    {
        $status = $o->getAttributes()['derived_status'] ?? $this->status($o);

        return [
            'id' => $o->public_id, 'code' => $o->code, 'name' => $o->name, 'offer_type' => $o->offer_type,
            'type_label' => __('offers.types.'.$o->offer_type), 'promo_code' => $o->promo_code,
            'discount_type' => $o->discount_type, 'discount_value' => Money::normalize((string) $o->discount_value),
            'discount_label' => $this->discountLabel($o), 'validity_label' => $this->validityLabel($o),
            'stay_from' => $o->stay_from?->toDateString(), 'stay_to' => $o->stay_to?->toDateString(),
            'status' => $status, 'is_active' => (bool) $o->is_active, 'channels_label' => $this->channelsLabel($o),
            'priority' => $o->priority, 'redemptions' => (int) $o->redemptions, 'max_redemptions' => $o->max_redemptions,
        ];
    }

    /** Side panel. @return array<string, mixed> */
    public function detail(Offer $o): array
    {
        $o->loadMissing(['scopes', 'conditions']);
        $stats = DB::table('offer_applications as a')->join('reservations as r', 'r.id', '=', 'a.reservation_id')
            ->where('a.offer_id', $o->id)->whereNotIn('r.status', ['cancelled', 'no_show'])
            ->selectRaw('COUNT(DISTINCT a.reservation_id) AS bookings, COALESCE(SUM(a.discount_amount), 0) AS discount, COALESCE(SUM(r.grand_total), 0) AS revenue')->first();
        $places = Money::minorUnits($this->currency());

        return $this->row($o) + [
            'description' => $o->description,
            'image_url' => $o->image_path ? Storage::disk('public')->url($o->image_path) : null,
            'highlights' => $this->highlights($o),
            'booking_window' => $this->range($o->booking_from, $o->booking_to),
            'weekdays_label' => $this->weekdaysLabel((int) $o->weekdays),
            'room_types' => $this->scopeNames($o, 'room_type_id'),
            'rate_plans' => $this->scopeNames($o, 'rate_plan_id'),
            'conditions' => $this->conditionLabels($o),
            'channels' => ['pms' => (bool) $o->on_pms, 'booking_engine' => (bool) $o->on_booking_engine, 'sources' => $this->sourceNames($o)],
            'is_stackable' => (bool) $o->is_stackable,
            'stats' => [
                'bookings' => (int) ($stats->bookings ?? 0),
                'discount' => Money::round((string) ($stats->discount ?? '0'), $places),
                'revenue' => Money::round((string) ($stats->revenue ?? '0'), $places),
            ],
            'history' => $this->history->for($o),
        ];
    }

    /** Values for the edit form. @return array<string, mixed> */
    public function form(Offer $o): array
    {
        $o->loadMissing(['scopes', 'conditions']);
        $cond = fn (string $type) => $o->conditions->firstWhere('condition_type', $type);
        $publicIds = fn (string $table, string $col) => DB::table($table)->whereIn('id', $o->scopes->pluck($col)->filter()->unique())->pluck('public_id')->values()->all();

        return [
            'id' => $o->public_id, 'name' => $o->name, 'code' => $o->code, 'offer_type' => $o->offer_type, 'description' => $o->description,
            'promo_code' => $o->promo_code, 'discount_type' => $o->discount_type, 'discount_value' => Money::normalize((string) $o->discount_value),
            'min_nights' => $o->min_nights, 'max_nights' => $o->max_nights, 'min_amount' => $o->min_amount !== null ? (string) $o->min_amount : null,
            'booking_from' => $o->booking_from?->toDateString(), 'booking_to' => $o->booking_to?->toDateString(),
            'stay_from' => $o->stay_from?->toDateString(), 'stay_to' => $o->stay_to?->toDateString(),
            'weekdays' => (int) $o->weekdays, 'min_advance_days' => $o->min_advance_days, 'max_advance_days' => $o->max_advance_days,
            'priority' => (int) $o->priority, 'is_stackable' => (bool) $o->is_stackable, 'max_redemptions' => $o->max_redemptions,
            'redemptions' => (int) $o->redemptions, 'on_pms' => (bool) $o->on_pms, 'on_booking_engine' => (bool) $o->on_booking_engine,
            'is_active' => (bool) $o->is_active,
            'room_types' => $publicIds('room_types', 'room_type_id'), 'rate_plans' => $publicIds('rate_plans', 'rate_plan_id'),
            'sources' => $cond('source')?->value ?? [], 'countries' => $cond('guest_country')?->value ?? [],
            'country_mode' => $cond('guest_country')?->operator === 'not_in' ? 'not_in' : 'in',
            'min_adults' => $cond('min_adults')?->value[0] ?? null, 'min_rooms' => $cond('min_rooms')?->value[0] ?? null,
            'image_url' => $o->image_path ? Storage::disk('public')->url($o->image_path) : null,
        ];
    }

    public function status(Offer $o): string
    {
        $today = CarbonImmutable::now($this->context->property()->timezone ?: config('app.timezone'))->toDateString();

        return match (true) {
            ! $o->is_active => 'inactive',
            ($o->stay_to && $o->stay_to->toDateString() < $today) || ($o->booking_to && $o->booking_to->toDateString() < $today)
                || ($o->max_redemptions !== null && $o->redemptions >= $o->max_redemptions) => 'expired',
            $o->booking_from && $o->booking_from->toDateString() > $today => 'scheduled',
            default => 'active',
        };
    }

    public function discountLabel(Offer $o): string
    {
        $v = (string) $o->discount_value;

        return match ($o->discount_type) {
            'percent' => __('offers.discount.percent', ['value' => $this->num($v)]),
            'fixed_per_night' => __('offers.discount.per_night', ['amount' => Money::display($v, $this->currency())]),
            'fixed_per_stay' => __('offers.discount.per_stay', ['amount' => Money::display($v, $this->currency())]),
            'free_nights' => __('offers.discount.free_nights', ['stay' => (int) $o->min_nights, 'pay' => (int) $o->min_nights - (int) $v]),
            default => $v,
        };
    }

    public function validityLabel(Offer $o): string
    {
        if ($o->stay_from || $o->stay_to) {
            return $this->range($o->stay_from, $o->stay_to);
        }
        if ((int) $o->weekdays !== Offer::ALL_DAYS) {
            return __('offers.validity.every', ['days' => $this->weekdaysLabel((int) $o->weekdays)]);
        }

        return __('offers.validity.always');
    }

    public function channelsLabel(Offer $o): string
    {
        $sources = $this->sourceNames($o);
        if ($sources !== []) {
            return implode(', ', $sources);
        }
        if ($o->on_pms && $o->on_booking_engine) {
            return __('offers.channels.all');
        }

        return $o->on_pms ? __('offers.channels.pms') : __('offers.channels.booking_engine');
    }

    public function weekdaysLabel(int $mask): string
    {
        if (($mask & Offer::ALL_DAYS) === Offer::ALL_DAYS) {
            return __('offers.days.every_day');
        }
        $on = array_values(array_filter(range(0, 6), fn ($i) => ($mask & (1 << $i)) !== 0));
        // A single run (e.g. Fri – Sun) reads better as a range.
        if (count($on) >= 3 && $on === range($on[0], $on[0] + count($on) - 1)) {
            return __('offers.days.'.self::DAYS[$on[0]]).' – '.__('offers.days.'.self::DAYS[end($on)]);
        }

        return implode(', ', array_map(fn ($i) => __('offers.days.'.self::DAYS[$i]), $on));
    }

    /** Plain-language summary of the terms (panel "Highlights"). @return list<string> */
    public function highlights(Offer $o): array
    {
        $o->loadMissing(['scopes', 'conditions']);
        $out = [__('offers.highlights.discount', ['discount' => $this->discountLabel($o)])];
        $out[] = $o->stay_from || $o->stay_to ? __('offers.highlights.stay', ['range' => $this->range($o->stay_from, $o->stay_to)]) : __('offers.highlights.any_stay');
        if ($o->booking_from || $o->booking_to) {
            $out[] = __('offers.highlights.booking', ['range' => $this->range($o->booking_from, $o->booking_to)]);
        }
        if ((int) $o->weekdays !== Offer::ALL_DAYS) {
            $out[] = __('offers.highlights.weekdays', ['days' => $this->weekdaysLabel((int) $o->weekdays)]);
        }
        if ($o->min_nights && $o->discount_type !== 'free_nights') {
            $out[] = __('offers.highlights.min_nights', ['n' => $o->min_nights]);
        }
        if ($o->max_nights) {
            $out[] = __('offers.highlights.max_nights', ['n' => $o->max_nights]);
        }
        if ($o->min_advance_days) {
            $out[] = __('offers.highlights.min_advance', ['n' => $o->min_advance_days]);
        }
        if ($o->max_advance_days !== null) {
            $out[] = __('offers.highlights.max_advance', ['n' => $o->max_advance_days]);
        }
        if ($o->min_amount !== null) {
            $out[] = __('offers.highlights.min_amount', ['amount' => Money::display((string) $o->min_amount, $this->currency())]);
        }
        if ($o->promo_code) {
            $out[] = __('offers.highlights.promo', ['code' => $o->promo_code]);
        }
        $rooms = $this->scopeNames($o, 'room_type_id');
        $out[] = $rooms === [] ? __('offers.highlights.all_rooms') : __('offers.highlights.rooms', ['rooms' => implode(', ', $rooms)]);

        return $out;
    }

    /** @return list<string> */
    public function conditionLabels(Offer $o): array
    {
        $out = [];
        foreach ($o->conditions as $c) {
            $values = (array) $c->value;
            $out[] = match ($c->condition_type) {
                'min_adults' => __('offers.conditions.min_adults', ['n' => (int) ($values[0] ?? 0)]),
                'min_rooms' => __('offers.conditions.min_rooms', ['n' => (int) ($values[0] ?? 0)]),
                'source' => __('offers.conditions.source', ['list' => implode(', ', $this->sourceNames($o))]),
                'guest_country' => __($c->operator === 'not_in' ? 'offers.conditions.country_not' : 'offers.conditions.country', ['list' => implode(', ', $values)]),
                default => $c->condition_type,
            };
        }
        if ($o->is_stackable) {
            $out[] = __('offers.conditions.stackable');
        }
        if ($o->max_redemptions !== null) {
            $out[] = __('offers.conditions.redemptions', ['used' => (int) $o->redemptions, 'max' => $o->max_redemptions]);
        }

        return $out;
    }

    /** @return list<string> */
    private function scopeNames(Offer $o, string $column): array
    {
        $ids = $o->scopes->pluck($column)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        if ($column === 'room_type_id') {
            $this->roomTypes ??= DB::table('room_types')->where('property_id', $this->context->id())->pluck('name', 'id')->all();
            $names = $this->roomTypes;
        } else {
            $this->ratePlans ??= DB::table('rate_plans')->where('property_id', $this->context->id())->pluck('name', 'id')->all();
            $names = $this->ratePlans;
        }

        return $ids->map(fn ($id) => $names[$id] ?? '?')->all();
    }

    /** @return list<string> */
    private function sourceNames(Offer $o): array
    {
        $codes = (array) ($o->conditions->firstWhere('condition_type', 'source')?->value ?? []);
        if ($codes === []) {
            return [];
        }
        $this->sources ??= DB::table('booking_sources')->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $this->context->id()))->pluck('name', 'code')->all();

        return array_map(fn ($c) => __('reservations.sources.'.$c) !== 'reservations.sources.'.$c ? __('reservations.sources.'.$c) : ($this->sources[$c] ?? $c), $codes);
    }

    private function range(?\DateTimeInterface $from, ?\DateTimeInterface $to): string
    {
        $fmt = fn (?\DateTimeInterface $d) => $d ? CarbonImmutable::instance($d)->locale(app()->getLocale())->translatedFormat('d M Y') : null;

        return match (true) {
            $from !== null && $to !== null => $fmt($from).' – '.$fmt($to),
            $from !== null => __('offers.validity.from', ['date' => $fmt($from)]),
            $to !== null => __('offers.validity.until', ['date' => $fmt($to)]),
            default => __('offers.validity.always'),
        };
    }

    private function num(string $v): string
    {
        return rtrim(rtrim(Money::normalize($v), '0'), '.');
    }

    private function currency(): string
    {
        return (string) $this->context->property()->currency_code;
    }
}

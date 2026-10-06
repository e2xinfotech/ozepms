<?php

namespace App\Domain\Offers\Queries;

use App\Models\Offer;
use App\Support\Listing;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Offers list (design offers.png). Status is derived for the property's today:
 *   inactive  = switched off;
 *   expired   = booking or stay window over, or every redemption used;
 *   scheduled = booking window not open yet;
 *   active    = everything else (bookable now).
 * Tabs: all, active, scheduled, expired, room_discount, package, last_minute, early_bird.
 */
class OfferQuery
{
    public const TABS = ['all', 'active', 'scheduled', 'expired', 'room_discount', 'package', 'last_minute', 'early_bird'];

    public const SORTS = ['code' => 'offers.code', 'name' => 'offers.name', 'priority' => 'offers.priority', 'stay_from' => 'offers.stay_from', 'status' => 'offers.is_active'];

    public function __construct(
        private readonly PropertyContext $context,
        private readonly OfferPresenter $presenter,
    ) {}

    public function today(): string
    {
        $tz = $this->context->property()->timezone ?: config('app.timezone');

        return CarbonImmutable::now($tz)->toDateString();
    }

    /** SQL for the derived status (bound to today). @return array{0: string, 1: list<string>} */
    public static function statusSql(string $today): array
    {
        return ["CASE WHEN offers.is_active = 0 THEN 'inactive'
            WHEN (offers.stay_to IS NOT NULL AND offers.stay_to < ?) OR (offers.booking_to IS NOT NULL AND offers.booking_to < ?)
              OR (offers.max_redemptions IS NOT NULL AND offers.redemptions >= offers.max_redemptions) THEN 'expired'
            WHEN offers.booking_from IS NOT NULL AND offers.booking_from > ? THEN 'scheduled'
            ELSE 'active' END", [$today, $today, $today]];
    }

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $f = Listing::filters($request, ['q', 'status', 'type', 'room_type', 'channel', 'tab']);
        $tab = in_array($f['tab'], self::TABS, true) ? $f['tab'] : 'all';
        $today = $this->today();
        [$status, $bind] = self::statusSql($today);

        $filtered = Offer::query()
            ->when($f['q'] !== '', function (Builder $q) use ($f) {
                $term = '%'.$f['q'].'%';
                $q->where(fn (Builder $w) => $w->where('offers.name', 'like', $term)->orWhere('offers.code', 'like', $term)->orWhere('offers.promo_code', 'like', $term));
            })
            ->when(in_array($f['status'], ['active', 'inactive', 'scheduled', 'expired'], true), fn (Builder $q) => $q->whereRaw("($status) = ?", [...$bind, $f['status']]))
            ->when(in_array($f['type'], Offer::TYPES, true), fn (Builder $q) => $q->where('offers.offer_type', $f['type']))
            ->when($f['room_type'] !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereDoesntHave('scopes', fn ($s) => $s->whereNotNull('room_type_id'))
                ->orWhereHas('scopes', fn ($s) => $s->whereIn('room_type_id', fn ($r) => $r->from('room_types')->select('id')->where('public_id', $f['room_type'])))))
            ->when($f['channel'] === 'pms', fn (Builder $q) => $q->where('offers.on_pms', true))
            ->when($f['channel'] === 'booking_engine', fn (Builder $q) => $q->where('offers.on_booking_engine', true));

        $c = (clone $filtered)->toBase()->selectRaw("COUNT(*) AS total,
            SUM(($status) = 'active') AS active, SUM(($status) = 'scheduled') AS scheduled, SUM(($status) = 'expired') AS expired,
            SUM(offer_type = 'room_discount') AS room_discount, SUM(offer_type = 'package') AS package,
            SUM(offer_type = 'last_minute') AS last_minute, SUM(offer_type = 'early_bird') AS early_bird", [...$bind, ...$bind, ...$bind])->first();

        $rows = (clone $filtered)->with(['scopes', 'conditions'])->select('offers.*')->selectRaw("($status) AS derived_status", $bind);
        match ($tab) {
            'active', 'scheduled', 'expired' => $rows->whereRaw("($status) = ?", [...$bind, $tab]),
            'room_discount', 'package', 'last_minute', 'early_bird' => $rows->where('offers.offer_type', $tab),
            default => null,
        };
        Listing::sort($rows, $request, self::SORTS, 'offers.code');
        $rows->orderBy('offers.id');

        $page = Listing::paginate($rows, $request, fn (Offer $o) => $this->presenter->row($o));
        $counts = ['all' => (int) ($c->total ?? 0)];
        foreach (array_slice(self::TABS, 1) as $k) {
            $counts[$k] = (int) ($c->{$k} ?? 0);
        }

        return $page + ['counts' => $counts];
    }

    /** Rows for the CSV export (same filters, no paging). @return iterable<array<string, string>> */
    public function export(Request $request): iterable
    {
        $request = $request->duplicate(array_merge($request->query(), ['per_page' => 1000, 'page' => 1]));
        foreach ($this->list($request)['rows'] as $r) {
            yield [
                __('offers.columns.id') => $r['code'], __('offers.columns.name') => $r['name'], __('offers.columns.type') => $r['type_label'],
                __('offers.columns.discount') => $r['discount_label'], __('offers.columns.promo_code') => (string) $r['promo_code'],
                __('offers.columns.validity') => $r['validity_label'], __('offers.columns.status') => __('offers.status.'.$r['status']),
                __('offers.columns.channels') => $r['channels_label'], __('offers.columns.redemptions') => (string) $r['redemptions'],
            ];
        }
    }
}

<?php

namespace App\Domain\Reservations\Queries;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Models\Guest;
use App\Support\Lookups;
use Illuminate\Support\Facades\DB;

/** Option lists for the reservation pages, built once per request. */
class ReservationOptions
{
    public const PURPOSES = ['leisure', 'business', 'conference', 'wedding', 'transit', 'other'];

    public const MARKETS = ['fit', 'corporate', 'group', 'government', 'long_stay', 'mice'];

    private ?array $sources = null;

    public function __construct(
        private readonly FormOptions $options,
        private readonly ReservationPresenter $presenter,
    ) {}

    /** Booking sources (system + property) as code / label. */
    public function sources(): array
    {
        return $this->sources ??= DB::table('booking_sources')->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', app(\App\Support\PropertyContext::class)->id()))
            ->orderBy('id')->get(['code', 'name'])
            ->map(fn ($s) => ['value' => $s->code, 'label' => $this->presenter->sourceName($s->code, $s->name)])->all();
    }

    /** Filters of the reservations list. */
    public function listFilters(): array
    {
        return [
            'sources' => $this->sources(),
            'room_types' => $this->options->roomTypes(),
            'rate_plans' => $this->options->ratePlans(),
        ];
    }

    /** Everything the create / edit form needs on first paint. */
    public function form(): array
    {
        $products = DB::table('room_type_rate_plans as p')
            ->join('room_types as rt', 'rt.id', '=', 'p.room_type_id')
            ->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->where('p.property_id', app(\App\Support\PropertyContext::class)->id())
            ->where('p.is_active', 1)->where('rp.is_active', 1)->where('rp.sell_on_pms', 1)->whereNull('rp.deleted_at')->whereNull('rt.deleted_at')
            ->orderBy('rp.sort_order')->orderBy('rp.name')
            ->get(['rt.public_id as room_type', 'rp.public_id as rate_plan'])
            ->groupBy('room_type')->map(fn ($rows) => $rows->pluck('rate_plan')->values()->all())->all();

        return [
            'sources' => $this->sources(),
            'room_types' => $this->options->roomTypes(true),
            'rate_plans' => $this->options->ratePlans(true),
            'products' => $products,
            'countries' => array_map(fn ($c) => ['value' => $c['value'], 'label' => $c['label'], 'phone' => $c['phone']], Lookups::countries()),
            'purposes' => $this->enum('purposes', self::PURPOSES),
            'markets' => $this->enum('markets', self::MARKETS),
            'titles' => $this->enum('titles', Guest::TITLES, 'guests'),
            'guest_types' => $this->enum('types', Guest::TYPES, 'guests'),
            'id_types' => $this->enum('id_types', Guest::ID_TYPES, 'guests'),
        ];
    }

    private function enum(string $group, array $values, string $file = 'reservations'): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => __("{$file}.{$group}.{$v}")], $values);
    }
}

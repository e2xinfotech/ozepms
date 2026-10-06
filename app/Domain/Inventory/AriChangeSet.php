<?php

namespace App\Domain\Inventory;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * One single-date, range or bulk edit of availability, rates and restrictions (ARI).
 *
 * Targets
 *   roomTypeIds  room types (internal ids) for room-type level fields: stopSell, sellLimit
 *   productIds   products (room_type_rate_plans ids) for product level fields: price,
 *                occupancyPrices, minLos, maxLos, cta, ctd, minAdvance, maxAdvance, closed, stopSell
 *
 * Fields: null = leave unchanged.
 *   stopSell        bool  close / open the room type (inventory_daily.stop_sell) for every targeted
 *                         room type, and the product (ari_daily.stop_sell) for every targeted product
 *                         unless `closed` is given as well (then `closed` decides for products)
 *   sellLimit       int|false  cap on rooms to sell (≤ total rooms); false removes the cap
 *   price           decimal string, product price for 1 … base occupancy
 *   occupancyPrices array<int persons, decimal string|null>  fixed price for that number of adults;
 *                         a null value removes the override for that occupancy
 *   minLos / maxLos int   minimum / maximum nights (0 removes the restriction)
 *   cta / ctd       bool  closed to arrival / departure
 *   minAdvance      int   days the stay must be booked in advance (cutoff); 0 removes it
 *   maxAdvance      int   days at most between booking and arrival; 0 removes it
 *   closed          bool  product stop sell
 *
 * Dates are stay dates, inclusive on both ends. weekdays filters the range by ISO day
 * (1 = Monday … 7 = Sunday); empty = every day. Validation happens in the constructor and
 * throws ValidationException with field keys matching the names above (snake_case).
 */
final class AriChangeSet
{
    public const MAX_DAYS = 731;

    public const ROOM_TYPE_FIELDS = ['stopSell', 'sellLimit'];

    public const PRODUCT_FIELDS = ['price', 'occupancyPrices', 'minLos', 'maxLos', 'cta', 'ctd', 'minAdvance', 'maxAdvance', 'closed', 'stopSell'];

    public const RESTRICTION_FIELDS = ['minLos', 'maxLos', 'cta', 'ctd', 'minAdvance', 'maxAdvance', 'closed', 'stopSell'];

    public readonly CarbonImmutable $dateFrom;

    public readonly CarbonImmutable $dateTo;

    /** @var list<int> */
    public readonly array $weekdays;

    /** @var list<int> */
    public readonly array $roomTypeIds;

    /** @var list<int> */
    public readonly array $productIds;

    public readonly ?string $price;

    /** @var array<int, ?string>|null */
    public readonly ?array $occupancyPrices;

    /**
     * @param  list<int>  $weekdays
     * @param  list<int>  $roomTypeIds
     * @param  list<int>  $productIds
     * @param  array<int|string, string|int|null>|null  $occupancyPrices
     */
    public function __construct(
        public readonly int $propertyId,
        CarbonImmutable|string $dateFrom,
        CarbonImmutable|string $dateTo,
        array $weekdays = [],
        array $roomTypeIds = [],
        array $productIds = [],
        public readonly ?bool $stopSell = null,
        public readonly int|false|null $sellLimit = null,
        string|int|null $price = null,
        ?array $occupancyPrices = null,
        public readonly ?int $minLos = null,
        public readonly ?int $maxLos = null,
        public readonly ?bool $cta = null,
        public readonly ?bool $ctd = null,
        public readonly ?int $minAdvance = null,
        public readonly ?int $maxAdvance = null,
        public readonly ?bool $closed = null,
    ) {
        $errors = [];

        try {
            $this->dateFrom = $dateFrom instanceof CarbonImmutable ? $dateFrom->startOfDay() : CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom);
            $this->dateTo = $dateTo instanceof CarbonImmutable ? $dateTo->startOfDay() : CarbonImmutable::createFromFormat('!Y-m-d', $dateTo);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date_from' => __('inventory.errors.dates')]);
        }
        if ($this->dateTo->lessThan($this->dateFrom)) {
            $errors['date_to'] = __('inventory.errors.date_order');
        } elseif ($this->days() > self::MAX_DAYS) {
            $errors['date_to'] = __('inventory.errors.range_too_long', ['max' => self::MAX_DAYS]);
        }

        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays);
        foreach ($weekdays as $day) {
            if ($day < 1 || $day > 7) {
                $errors['weekdays'] = __('inventory.errors.weekdays');
            }
        }
        $this->weekdays = count($weekdays) === 7 ? [] : $weekdays;
        $this->roomTypeIds = array_values(array_unique(array_map('intval', $roomTypeIds)));
        $this->productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($price !== null) {
            if (! Money::isDecimal($price) || Money::isNegative((string) $price)) {
                $errors['price'] = __('inventory.errors.price');
                $price = null;
            } else {
                $price = Money::round((string) $price, 2);
            }
        }
        $this->price = $price;

        if ($occupancyPrices !== null) {
            $clean = [];
            foreach ($occupancyPrices as $persons => $value) {
                if (! is_numeric($persons) || (int) $persons < 1 || (int) $persons > 99) {
                    $errors['occupancy_prices'] = __('inventory.errors.occupancy');

                    continue;
                }
                if ($value === null || $value === '') {
                    $clean[(int) $persons] = null;
                } elseif (! Money::isDecimal($value) || Money::isNegative((string) $value)) {
                    $errors["occupancy_prices.$persons"] = __('inventory.errors.price');
                } else {
                    $clean[(int) $persons] = Money::round((string) $value, 2);
                }
            }
            ksort($clean);
            $occupancyPrices = $clean === [] ? null : $clean;
        }
        $this->occupancyPrices = $occupancyPrices;

        foreach (['sellLimit' => $sellLimit, 'minLos' => $minLos, 'maxLos' => $maxLos, 'minAdvance' => $minAdvance, 'maxAdvance' => $maxAdvance] as $name => $value) {
            if (is_int($value) && ($value < 0 || $value > 65535)) {
                $errors[self::snake($name)] = __('inventory.errors.number');
            }
        }
        if (($minLos ?? 0) > 0 && ($maxLos ?? 0) > 0 && $maxLos < $minLos) {
            $errors['max_los'] = __('inventory.errors.los_order');
        }
        if (($minAdvance ?? 0) > 0 && ($maxAdvance ?? 0) > 0 && $maxAdvance < $minAdvance) {
            $errors['max_advance'] = __('inventory.errors.advance_order');
        }

        if ($this->roomTypeIds === [] && $this->productIds === []) {
            $errors['targets'] = __('inventory.errors.no_target');
        }
        if ($errors === [] && ! $this->hasRoomTypeChanges() && ! $this->hasProductChanges()) {
            $errors['fields'] = __('inventory.errors.no_change');
        }
        $hasTarget = $this->roomTypeIds !== [] || $this->productIds !== [];
        if ($hasTarget && $sellLimit !== null && $this->roomTypeIds === []) {
            $errors['sell_limit'] = __('inventory.errors.needs_room_types');
        }
        $productOnly = array_filter(['price', 'occupancyPrices', 'minLos', 'maxLos', 'cta', 'ctd', 'minAdvance', 'maxAdvance', 'closed'], fn ($f) => $this->{$f} !== null);
        if ($hasTarget && $productOnly !== [] && $this->productIds === []) {
            $errors['products'] = __('inventory.errors.needs_products');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Builds a change set from validated request data (snake_case keys):
     * date_from, date_to, weekdays[], room_type_ids[], product_ids[], stop_sell, sell_limit,
     * price, occupancy_prices{persons: price}, min_los, max_los, cta, ctd, min_advance, max_advance, closed.
     * Keys that are missing or null leave the value unchanged.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(int $propertyId, array $data): self
    {
        $int = fn (string $k) => array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '' ? (int) $data[$k] : null;
        $bool = fn (string $k) => array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '' ? filter_var($data[$k], FILTER_VALIDATE_BOOLEAN) : null;
        $sellLimit = array_key_exists('sell_limit', $data)
            ? ($data['sell_limit'] === false || $data['sell_limit'] === 'none' ? false : $int('sell_limit'))
            : null;

        return new self(
            propertyId: $propertyId,
            dateFrom: (string) ($data['date_from'] ?? ''),
            dateTo: (string) ($data['date_to'] ?? $data['date_from'] ?? ''),
            weekdays: (array) ($data['weekdays'] ?? []),
            roomTypeIds: (array) ($data['room_type_ids'] ?? []),
            productIds: (array) ($data['product_ids'] ?? []),
            stopSell: $bool('stop_sell'),
            sellLimit: $sellLimit,
            price: isset($data['price']) && $data['price'] !== '' ? (string) $data['price'] : null,
            occupancyPrices: isset($data['occupancy_prices']) ? (array) $data['occupancy_prices'] : null,
            minLos: $int('min_los'),
            maxLos: $int('max_los'),
            cta: $bool('cta'),
            ctd: $bool('ctd'),
            minAdvance: $int('min_advance'),
            maxAdvance: $int('max_advance'),
            closed: $bool('closed'),
        );
    }

    /** Number of calendar days in the range (before the weekday filter). */
    public function days(): int
    {
        return (int) $this->dateFrom->diffInDays($this->dateTo) + 1;
    }

    /** @return list<string> Y-m-d dates in the range that pass the weekday filter */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->dateFrom; $d->lessThanOrEqualTo($this->dateTo); $d = $d->addDay()) {
            if ($this->weekdays === [] || in_array($d->dayOfWeekIso, $this->weekdays, true)) {
                $dates[] = $d->toDateString();
            }
        }

        return $dates;
    }

    /** Weekday filter as the ari_change_log bitmask (Mon = 1 … Sun = 64; 127 = every day). */
    public function weekdayMask(): int
    {
        if ($this->weekdays === []) {
            return 127;
        }

        return array_sum(array_map(fn (int $d) => 1 << ($d - 1), $this->weekdays));
    }

    public function hasRoomTypeChanges(): bool
    {
        return $this->stopSell !== null || $this->sellLimit !== null;
    }

    public function hasProductChanges(): bool
    {
        return $this->hasPriceChanges() || $this->hasRestrictionChanges();
    }

    public function hasPriceChanges(): bool
    {
        return $this->price !== null || $this->occupancyPrices !== null;
    }

    public function hasRestrictionChanges(): bool
    {
        foreach (self::RESTRICTION_FIELDS as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return false;
    }

    /** Product stop sell to write, if any (`closed` wins over `stopSell`). */
    public function productStopSell(): ?bool
    {
        return $this->closed ?? $this->stopSell;
    }

    /** Changed fields as snake_case => value, for logs and audit. */
    public function payload(): array
    {
        $out = [];
        foreach (['stopSell', 'sellLimit', 'price', 'occupancyPrices', 'minLos', 'maxLos', 'cta', 'ctd', 'minAdvance', 'maxAdvance', 'closed'] as $field) {
            if ($this->{$field} !== null) {
                $out[self::snake($field)] = $this->{$field} === false && $field === 'sellLimit' ? null : $this->{$field};
            }
        }

        return $out;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $name));
    }
}

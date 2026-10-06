<?php

namespace App\Domain\Offers;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Offer;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Money;
use App\Support\PropertyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Offers set-up (create, edit, activate, copy, delete, image). Scope and conditions are stored as
 * rows (offer_scopes, offer_conditions); the form sends them as simple lists:
 *   room_types / rate_plans (models; empty = all), sources (booking source codes; empty = all),
 *   countries + country_mode (in|not_in), min_adults, min_rooms.
 */
class OfferAdminService
{
    public const DISK = 'public';

    private const FIELDS = [
        'name', 'code', 'offer_type', 'description', 'promo_code', 'discount_type', 'discount_value', 'min_nights', 'max_nights',
        'min_amount', 'booking_from', 'booking_to', 'stay_from', 'stay_to', 'weekdays', 'min_advance_days', 'max_advance_days',
        'priority', 'is_stackable', 'max_redemptions', 'on_pms', 'on_booking_engine', 'is_active',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PropertyContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Offer
    {
        return Tx::run(function () use ($data) {
            $offer = new Offer;
            $this->fill($offer, $data);
            $offer->code = $offer->code ?: $this->nextCode();
            $this->assertUnique($offer);
            $offer->save();
            $this->syncRules($offer, $data);
            $this->audit->log('offer.created', $offer, ['after' => $offer->only(self::FIELDS)]);

            return $offer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Offer $offer, array $data): Offer
    {
        return Tx::run(function () use ($offer, $data) {
            $before = $offer->only(self::FIELDS);
            $this->fill($offer, $data);
            $offer->code = $offer->code ?: $this->nextCode();
            $this->assertUnique($offer);
            $offer->save();
            $this->syncRules($offer, $data);
            $after = $offer->only(self::FIELDS);
            $this->audit->log('offer.updated', $offer, ['before' => array_diff_assoc(array_map('strval', $before), array_map('strval', $after)), 'after' => array_diff_assoc(array_map('strval', $after), array_map('strval', $before))]);

            return $offer;
        });
    }

    public function setActive(Offer $offer, bool $active): Offer
    {
        $offer->is_active = $active;
        $offer->save();
        $this->audit->log($active ? 'offer.activated' : 'offer.deactivated', $offer);

        return $offer;
    }

    public function copy(Offer $offer): Offer
    {
        return Tx::run(function () use ($offer) {
            $offer->loadMissing(['scopes', 'conditions']);
            $copy = $offer->replicate(['public_id', 'redemptions', 'image_path']);
            $copy->name = mb_substr(__('offers.copy_of', ['name' => $offer->name]), 0, 120);
            $copy->code = $this->nextCode();
            $copy->promo_code = null;
            $copy->is_active = false;
            $copy->redemptions = 0;
            $copy->save();
            foreach ($offer->scopes as $s) {
                $copy->scopes()->create($s->only(['room_type_id', 'rate_plan_id']));
            }
            foreach ($offer->conditions as $c) {
                $copy->conditions()->create($c->only(['condition_type', 'operator', 'value']));
            }
            $this->audit->log('offer.created', $copy, ['after' => ['copied_from' => $offer->code]]);

            return $copy;
        });
    }

    /** Never-used offers are deleted; used ones keep their history and are only deactivated. */
    public function delete(Offer $offer): string
    {
        if (DB::table('offer_applications')->where('offer_id', $offer->id)->exists()) {
            $this->setActive($offer, false);

            return 'deactivated';
        }
        $offer->delete();
        $this->audit->log('offer.deleted', $offer, ['before' => ['code' => $offer->code, 'name' => $offer->name]]);

        return 'deleted';
    }

    public function storeImage(Offer $offer, UploadedFile $file): Offer
    {
        $dir = 'properties/'.$this->context->property()->code.'/offers/'.$offer->public_id;
        $path = $file->storeAs($dir, Str::lower((string) Str::ulid()).'.'.($file->guessExtension() ?: 'jpg'), self::DISK);
        if ($offer->image_path) {
            Storage::disk(self::DISK)->delete($offer->image_path);
        }
        $offer->image_path = $path;
        $offer->save();
        $this->audit->log('offer.image_changed', $offer, ['after' => ['path' => $path]]);

        return $offer;
    }

    public function removeImage(Offer $offer): Offer
    {
        if ($offer->image_path) {
            Storage::disk(self::DISK)->delete($offer->image_path);
            $offer->image_path = null;
            $offer->save();
            $this->audit->log('offer.image_changed', $offer, ['after' => ['path' => null]]);
        }

        return $offer;
    }

    /** P-00001, P-00002 … per property. */
    public function nextCode(): string
    {
        $max = Offer::query()->withTrashed()->where('code', 'like', 'P-%')->pluck('code')
            ->map(fn ($c) => (int) substr((string) $c, 2))->max() ?? 0;

        return sprintf('P-%05d', $max + 1);
    }

    /** @param array<string, mixed> $data */
    private function fill(Offer $offer, array $data): void
    {
        $offer->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $offer->code = strtoupper(trim((string) $offer->code));
        $promo = strtoupper(trim((string) ($offer->promo_code ?? '')));
        $offer->promo_code = $promo === '' ? null : $promo;
        $offer->offer_type ??= 'room_discount';
        $offer->weekdays = (int) ($offer->weekdays ?: Offer::ALL_DAYS);
        $offer->priority = (int) ($offer->priority ?? 0);
        foreach (['is_stackable' => false, 'on_pms' => true, 'on_booking_engine' => true, 'is_active' => true] as $k => $default) {
            $offer->{$k} = (bool) ($offer->{$k} ?? $default);
        }
        $this->assertRules($offer);
    }

    private function assertRules(Offer $offer): void
    {
        $errors = [];
        $value = (string) $offer->discount_value;
        if (! Money::isPositive($value)) {
            $errors['discount_value'] = __('offers.errors.value_positive');
        } elseif ($offer->discount_type === 'percent' && Money::compare($value, '100') > 0) {
            $errors['discount_value'] = __('offers.errors.percent_max');
        } elseif ($offer->discount_type === 'free_nights') {
            if ((int) $offer->min_nights < 2) {
                $errors['min_nights'] = __('offers.errors.free_nights_block');
            } elseif (bccomp($value, (string) (int) $value, 4) !== 0 || (int) $value >= (int) $offer->min_nights) {
                $errors['discount_value'] = __('offers.errors.free_nights_value');
            }
        }
        foreach ([['min_nights', 'max_nights'], ['booking_from', 'booking_to'], ['stay_from', 'stay_to'], ['min_advance_days', 'max_advance_days']] as [$a, $b]) {
            $x = $offer->{$a};
            $y = $offer->{$b};
            if ($x !== null && $y !== null && (($x instanceof \DateTimeInterface ? $x->format('Y-m-d') : (int) $x) > ($y instanceof \DateTimeInterface ? $y->format('Y-m-d') : (int) $y))) {
                $errors[$b] = __('offers.errors.range_order');
            }
        }
        if (($offer->weekdays & Offer::ALL_DAYS) === 0) {
            $errors['weekdays'] = __('offers.errors.weekdays');
        }
        if (! $offer->on_pms && ! $offer->on_booking_engine) {
            $errors['on_pms'] = __('offers.errors.channel');
        }
        if ($offer->max_redemptions !== null && $offer->exists && (int) $offer->max_redemptions < (int) $offer->redemptions) {
            $errors['max_redemptions'] = __('offers.errors.below_used', ['used' => (int) $offer->redemptions]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertUnique(Offer $offer): void
    {
        $base = Offer::query()->withTrashed()->when($offer->exists, fn ($q) => $q->whereKeyNot($offer->id));
        if ((clone $base)->where('code', $offer->code)->exists()) {
            throw ValidationException::withMessages(['code' => __('offers.errors.code_taken')]);
        }
        if ($offer->promo_code !== null && (clone $base)->where('promo_code', $offer->promo_code)->exists()) {
            throw ValidationException::withMessages(['promo_code' => __('offers.errors.promo_taken')]);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncRules(Offer $offer, array $data): void
    {
        if (array_key_exists('room_types', $data) || array_key_exists('rate_plans', $data)) {
            /** @var list<RoomType> $types */
            $types = $data['room_types'] ?? [];
            /** @var list<RatePlan> $plans */
            $plans = $data['rate_plans'] ?? [];
            $offer->scopes()->delete();
            $rows = [];
            foreach ($types ?: [null] as $t) {
                foreach ($plans ?: [null] as $p) {
                    if ($t !== null || $p !== null) {
                        $rows[] = ['offer_id' => $offer->id, 'room_type_id' => $t?->id, 'rate_plan_id' => $p?->id];
                    }
                }
            }
            if ($rows !== []) {
                DB::table('offer_scopes')->insert($rows);
            }
        }

        $keys = ['sources', 'countries', 'country_mode', 'min_adults', 'min_rooms'];
        if (array_intersect_key($data, array_flip($keys)) !== []) {
            // Keys that were not sent keep their current value (partial updates).
            $current = $offer->conditions()->get();
            $was = fn (string $type) => $current->firstWhere('condition_type', $type);
            $merged = [
                'sources' => array_key_exists('sources', $data) ? $data['sources'] : ($was('source')?->value ?? []),
                'countries' => array_key_exists('countries', $data) ? $data['countries'] : ($was('guest_country')?->value ?? []),
                'country_mode' => $data['country_mode'] ?? ($was('guest_country')?->operator === 'not_in' ? 'not_in' : 'in'),
                'min_adults' => array_key_exists('min_adults', $data) ? $data['min_adults'] : ($was('min_adults')?->value[0] ?? null),
                'min_rooms' => array_key_exists('min_rooms', $data) ? $data['min_rooms'] : ($was('min_rooms')?->value[0] ?? null),
            ];
            $offer->conditions()->delete();
            $conditions = [];
            if (! empty($merged['sources'])) {
                $conditions[] = ['condition_type' => 'source', 'operator' => 'in', 'value' => array_values(array_unique($merged['sources']))];
            }
            if (! empty($merged['countries'])) {
                $conditions[] = ['condition_type' => 'guest_country', 'operator' => $merged['country_mode'] === 'not_in' ? 'not_in' : 'in',
                    'value' => array_values(array_unique(array_map('strtoupper', $merged['countries'])))];
            }
            foreach (['min_adults', 'min_rooms'] as $k) {
                if (! empty($merged[$k])) {
                    $conditions[] = ['condition_type' => $k, 'operator' => 'gte', 'value' => [(int) $merged[$k]]];
                }
            }
            foreach ($conditions as $c) {
                $offer->conditions()->create($c);
            }
        }
        $offer->unsetRelation('scopes');
        $offer->unsetRelation('conditions');
    }
}

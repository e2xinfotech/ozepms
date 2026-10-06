<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditLogger;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * First-time setup of a property: property details → rate plan → room types with PMS rooms.
 * A property stays in "onboarding" until the required steps are done, then becomes active.
 */
class OnboardingService
{
    /** Steps that must be done before the property can take reservations. */
    private const REQUIRED = ['step_rate_plan', 'step_room_types'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Setup steps with a link to the page where each is done (null when that module is not installed yet).
     *
     * @return array<int, array{key: string, done: bool, url: ?string, required: bool}>
     */
    public function steps(Property $property): array
    {
        $count = fn (string $table) => DB::table($table)->where('property_id', $property->id)->whereNull('deleted_at')->count();
        $link = fn (string $route, array $query = []) => Route::has($route) ? route($route, ['property' => $property->code] + $query) : null;

        $ratePlans = $count('rate_plans');
        $roomsReady = $count('room_types') > 0 && $count('physical_units') > 0;

        return [
            ['key' => 'step_property', 'done' => true, 'url' => $link('property.settings'), 'required' => false],
            ['key' => 'step_rate_plan', 'done' => $ratePlans > 0, 'url' => $link($ratePlans > 0 ? 'property.rate-plans' : 'property.rate-plans.create', ['onboarding' => 1]), 'required' => true],
            ['key' => 'step_room_types', 'done' => $roomsReady, 'url' => $link($count('room_types') > 0 ? 'property.room-types' : 'property.room-types.create', ['onboarding' => 1]), 'required' => true],
            ['key' => 'step_rates', 'done' => Schema::hasTable('ari_daily') && DB::table('ari_daily')->where('property_id', $property->id)->exists(), 'url' => $link('property.calendar'), 'required' => false],
            ['key' => 'step_users', 'done' => DB::table('property_users')->where('property_id', $property->id)->count() > 1, 'url' => $link('property.users'), 'required' => false],
        ];
    }

    /** Page of the first required step that is still open, or null when setup is complete. */
    public function nextUrl(Property $property): ?string
    {
        foreach ($this->steps($property) as $step) {
            if ($step['required'] && ! $step['done'] && $step['url']) {
                return $step['url'];
            }
        }

        return null;
    }

    /**
     * Records the current step and activates the property once every required step is done.
     * Only properties in "onboarding" change; suspended or inactive ones are left alone.
     */
    public function sync(Property $property): Property
    {
        if ($property->status !== 'onboarding') {
            return $property;
        }

        $open = collect($this->steps($property))->first(fn ($s) => $s['required'] && ! $s['done']);
        $step = match ($open['key'] ?? null) {
            'step_rate_plan' => 'rate_plan',
            'step_room_types' => 'room_types',
            default => 'done',
        };

        if ($step === 'done') {
            $property->forceFill(['status' => 'active', 'onboarding_step' => 'done'])->save();
            $this->audit->log('property.onboarding_completed', $property, [
                'before' => ['status' => 'onboarding'], 'after' => ['status' => 'active'],
            ], $property->id);
        } elseif ($property->onboarding_step !== $step) {
            $property->forceFill(['onboarding_step' => $step])->save();
        }

        return $property;
    }

    /** True while required setup steps remain. */
    public function pending(Property $property): bool
    {
        return collect(self::REQUIRED)->contains(
            fn ($key) => ! collect($this->steps($property))->firstWhere('key', $key)['done'],
        );
    }
}

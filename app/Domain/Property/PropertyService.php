<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditLogger;
use App\Domain\Subscription\SubscriptionService;
use App\Domain\Users\UserService;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubscriptionService $subscriptions,
        private readonly UserService $users,
    ) {}

    /**
     * Platform registration: the owner account is found or invited, then the
     * property, owner membership and trial are created together.
     *
     * @param  array<string, mixed>  $data  validated property fields (may include 'languages')
     * @param  array{name: string, email: string, phone_e164?: ?string}  $owner
     */
    public function register(array $data, array $owner, ?SubscriptionPlan $plan, User $createdBy): Property
    {
        return Tx::run(function () use ($data, $owner, $plan, $createdBy) {
            $ownerUser = $this->users->findOrInvite($owner);

            return $this->createWithLanguages($data, $ownerUser, $plan, $createdBy);
        });
    }

    /**
     * Same as create() but also stores the extra languages chosen on the form.
     *
     * @param  array<string, mixed>  $data  validated property fields (may include 'languages')
     */
    public function createWithLanguages(array $data, User $owner, ?SubscriptionPlan $plan, User $createdBy): Property
    {
        return Tx::run(function () use ($data, $owner, $plan, $createdBy) {
            $languages = $data['languages'] ?? [];
            unset($data['languages']);

            $property = $this->create($data, $owner, $plan, $createdBy);
            $this->syncLanguages($property, $languages);

            return $property;
        });
    }

    /**
     * Languages offered by the property in addition to its default language.
     *
     * @param  array<int, string>  $codes
     */
    public function syncLanguages(Property $property, array $codes): void
    {
        $wanted = collect($codes)->push($property->default_language)->filter()->unique()->values();
        $current = DB::table('property_languages')->where('property_id', $property->id)->pluck('language_code');

        if ($wanted->sort()->values()->all() === $current->sort()->values()->all()) {
            return;
        }

        DB::table('property_languages')->where('property_id', $property->id)
            ->whereNotIn('language_code', $wanted->all())->delete();
        DB::table('property_languages')->insertOrIgnore($wanted->diff($current)
            ->map(fn ($code) => ['property_id' => $property->id, 'language_code' => $code])->values()->all());

        $this->audit->log('property.languages_changed', $property, [
            'before' => $current->values()->all(), 'after' => $wanted->all(),
        ], $property->id);
    }

    /** @return array<int, string> */
    public function languages(Property $property): array
    {
        return DB::table('property_languages')->where('property_id', $property->id)
            ->orderBy('language_code')->pluck('language_code')->all();
    }

    /**
     * Registers a property, its owner membership and its first subscription.
     *
     * @param  array<string, mixed>  $data  validated property fields
     */
    public function create(array $data, User $owner, ?SubscriptionPlan $plan, User $createdBy): Property
    {
        return Tx::run(function () use ($data, $owner, $plan, $createdBy) {
            $property = new Property($data);
            $property->slug = $this->uniqueSlug($data['name']);
            $property->status = $data['status'] ?? 'onboarding';
            $property->onboarding_step = 'rate_plan';
            $property->created_by = $createdBy->id;
            $property->business_date = now($property->timezone)->toDateString();
            $property->save();

            $property->code = PropertyCodeGenerator::for($property);
            $property->save();

            DB::table('property_languages')->insert([
                'property_id' => $property->id,
                'language_code' => $property->default_language,
            ]);

            DB::table('property_age_bands')->insert([
                ['property_id' => $property->id, 'code' => 'infant', 'min_age' => 0, 'max_age' => 2],
                ['property_id' => $property->id, 'code' => 'child', 'min_age' => 3, 'max_age' => 12],
            ]);

            $ownerRole = Role::query()->whereNull('property_id')->where('code', 'owner')->firstOrFail();
            PropertyUser::query()->withoutGlobalScope('property')->create([
                'property_id' => $property->id,
                'user_id' => $owner->id,
                'role_id' => $ownerRole->id,
                'is_owner' => true,
                'status' => 'active',
                'invited_by' => $createdBy->id,
                'joined_at' => now(),
            ]);

            if ($plan) {
                $this->subscriptions->startTrial($property, $plan, $createdBy);
            }

            \App\Domain\Property\Events\PropertyCreated::dispatch($property);

            $this->audit->log('property.created', $property, ['after' => $property->only([
                'code', 'name', 'property_type_id', 'country_iso2', 'currency_code', 'timezone', 'status',
            ])], $property->id);

            return $property;
        });
    }

    /** @param  array<string, mixed>  $data  validated fields; 'languages' syncs the extra languages */
    public function update(Property $property, array $data): Property
    {
        return Tx::run(function () use ($property, $data) {
            $languages = $data['languages'] ?? null;
            unset($data['languages']);

            $property->fill($data);
            if ($property->isDirty()) {
                $diff = $this->audit->diff($property);
                $property->save();
                $this->audit->log('property.updated', $property, $diff, $property->id);
            }

            if (is_array($languages)) {
                $this->syncLanguages($property, $languages);
            }

            return $property;
        });
    }

    public function changeStatus(Property $property, string $status, ?string $reason = null): Property
    {
        $before = $property->status;
        if ($before === $status) {
            return $property;
        }
        $property->status = $status;
        $property->save();
        $this->audit->log('property.status_changed', $property, [
            'before' => ['status' => $before], 'after' => ['status' => $status], 'reason' => $reason,
        ], $property->id);

        return $property;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'property';
        $slug = $base;
        $i = 2;
        while (Property::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

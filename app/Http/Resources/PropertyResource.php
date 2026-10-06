<?php

namespace App\Http\Resources;

use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Full property details for settings forms and detail panels.
 *
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Property $p */
        $p = $this->resource;
        $p->loadMissing(['type', 'country', 'state', 'owner.user', 'currentSubscription.plan']);
        $owner = $p->owner?->user;

        return [
            'code' => $p->code,
            'name' => $p->name,
            'tagline' => $p->tagline,
            'legal_name' => $p->legal_name,
            'property_type_id' => $p->property_type_id,
            'type' => $p->type?->code,
            'type_label' => $p->type ? __($p->type->label_key) : null,
            'star_rating' => $p->star_rating,
            'status' => $p->status,
            'phone' => $p->phone,
            'email' => $p->email,
            'website' => $p->website,
            'description' => $p->description,
            'contact_person' => $p->contact_person,
            'image' => $p->cover_image_path ? Storage::url($p->cover_image_path) : null,
            'logo' => $p->logo_path ? Storage::url($p->logo_path) : null,
            'country_iso2' => $p->country_iso2,
            'country' => $p->country?->name,
            'state_id' => $p->state_id,
            'state' => $p->state?->name,
            'city' => $p->city,
            'postcode' => $p->postcode,
            'address_line1' => $p->address_line1,
            'address_line2' => $p->address_line2,
            'address' => collect([$p->address_line1, $p->city, $p->country?->name])->filter()->implode(', '),
            'location' => $p->locationLabel(),
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'maps_url' => $p->maps_url,
            'currency_code' => $p->currency_code,
            'timezone' => $p->timezone,
            'timezone_label' => $this->timezoneLabel($p->timezone),
            'default_language' => $p->default_language,
            'languages' => array_values(array_diff(app(PropertyService::class)->languages($p), [$p->default_language])),
            'date_format' => $p->date_format,
            'number_format' => $p->number_format,
            'week_start' => (int) $p->week_start,
            'check_in_time' => substr((string) $p->check_in_time, 0, 5),
            'check_out_time' => substr((string) $p->check_out_time, 0, 5),
            'tax_registration_no' => $p->tax_registration_no,
            'business_date' => $p->business_date?->toDateString(),
            'rooms' => (int) DB::table('physical_units')->where('property_id', $p->id)
                ->whereNull('deleted_at')->where('is_active', true)->count(),
            'rate_plans' => (int) DB::table('rate_plans')->where('property_id', $p->id)
                ->whereNull('deleted_at')->where('is_active', true)->count(),
            'facilities' => Amenity::query()->join('property_amenities', 'property_amenities.amenity_id', '=', 'amenities.id')
                ->where('property_amenities.property_id', $p->id)->where('amenities.is_active', true)
                ->orderBy('amenities.name')->get(['amenities.*'])
                ->map(fn (Amenity $a) => ['code' => $a->code, 'name' => $a->label(), 'icon' => $a->icon])->values()->all(),
            'users' => (int) DB::table('property_users')->where('property_id', $p->id)->where('status', 'active')->count(),
            'owner' => $owner ? [
                'id' => $owner->public_id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone_e164,
            ] : null,
            'subscription' => app(SubscriptionService::class)->state($p),
            'plan_id' => $p->currentSubscription?->plan_id,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function timezoneLabel(?string $tz): ?string
    {
        if (! $tz) {
            return null;
        }
        try {
            $offset = (new \DateTime('now', new \DateTimeZone($tz)))->format('P');
        } catch (\Throwable) {
            return $tz;
        }

        return '(GMT'.$offset.') '.str_replace('_', ' ', $tz);
    }
}

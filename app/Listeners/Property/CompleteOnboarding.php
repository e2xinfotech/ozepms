<?php

namespace App\Listeners\Property;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Property\OnboardingService;
use App\Models\Property;

/** Activates a property in setup as soon as it has a rate plan and room types with PMS rooms. */
class CompleteOnboarding
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function handle(RoomTypeUnitsChanged $event): void
    {
        $property = Property::query()->find($event->propertyId);
        if ($property && $property->status === 'onboarding') {
            $this->onboarding->sync($property);
        }
    }
}

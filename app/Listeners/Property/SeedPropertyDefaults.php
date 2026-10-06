<?php

namespace App\Listeners\Property;

use App\Domain\Property\Events\PropertyCreated;
use App\Domain\Rates\PropertyDefaultsService;

/** Seeds cancellation policies, the BAR rate plan and local tax rules for a new property. */
class SeedPropertyDefaults
{
    public function __construct(private readonly PropertyDefaultsService $defaults) {}

    public function handle(PropertyCreated $event): void
    {
        // A copied property receives the source property's policies, rate plans and taxes instead.
        if ($event->copiedFromId !== null) {
            return;
        }
        $this->defaults->seed($event->property);
    }
}

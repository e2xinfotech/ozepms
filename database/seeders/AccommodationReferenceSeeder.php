<?php

namespace Database\Seeders;

use App\Domain\Accommodation\Reference\AccommodationReference;
use App\Domain\Rates\Reference\MealPlans;
use App\Domain\Tax\Reference\TaxReference;
use Illuminate\Database\Seeder;

/** Global data of the rooms, rates and taxes module: bed types, amenities, meal plans, tax categories and country tax templates. Idempotent. */
class AccommodationReferenceSeeder extends Seeder
{
    public function run(): void
    {
        AccommodationReference::seedBedTypes();
        AccommodationReference::seedAmenities();
        MealPlans::seed();
        TaxReference::seedCategories();
        TaxReference::seedCountryTemplates();
    }
}

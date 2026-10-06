<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Accommodation\Queries\RoomTypeQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Http\Resources\Accommodation\RoomTypeResource;
use App\Models\RoomType;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class RoomTypesController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, RoomTypeQuery $query, FormOptions $options): View
    {
        return Page::render('property/room-types/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'status', 'category', 'meal_plan', 'sort', 'dir']),
            'options' => ['categories' => $options->categories(), 'meal_plans' => $options->mealPlans()],
            'can' => $this->can(['create' => 'rooms.create', 'update' => 'rooms.update']),
        ], __('nav.room_types'));
    }

    public function create(Request $request, FormOptions $options): View
    {
        return Page::render('property/room-types/form', [
            'room_type' => null,
            'options' => $this->formOptions($options),
            'can' => $this->can(['create_rate_plan' => 'rate_plans.create', 'add_amenity' => 'rooms.update']),
        ], __('rooms.add_room_type'));
    }

    public function edit(Request $request, FormOptions $options, mixed $property, string $roomType): View
    {
        $model = RoomType::query()->where('public_id', $roomType)->firstOrFail();

        return Page::render('property/room-types/form', [
            'room_type' => (new RoomTypeResource($model))->resolve($request),
            'options' => $this->formOptions($options),
            'can' => $this->can(['create_rate_plan' => 'rate_plans.create', 'add_amenity' => 'rooms.update']),
        ], __('rooms.edit_room_type'));
    }

    /** @return array<string, mixed> */
    private function formOptions(FormOptions $options): array
    {
        return [
            'categories' => $options->categories(),
            'bed_types' => $options->bedTypes(),
            'amenities' => $options->amenities(),
            'amenity_categories' => $options->amenityCategories(),
            'rate_plans' => $options->ratePlans(),
            'age_bands' => $options->ageBands(),
            // For "+ Create Rate Plan" inside the form (no rate plan yet, or one more is needed).
            'meal_plans' => $options->mealPlans(),
            'policies' => array_map(fn (array $p) => ['value' => $p['value'], 'label' => $p['label'], 'refundable' => $p['refundable']], $options->cancellationPolicies()),
            'usage' => $options->usage(),
        ];
    }
}

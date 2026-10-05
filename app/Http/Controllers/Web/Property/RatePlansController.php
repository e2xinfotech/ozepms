<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Rates\Queries\RatePlanQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Http\Resources\Accommodation\RatePlanResource;
use App\Models\RatePlan;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class RatePlansController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, RatePlanQuery $query, FormOptions $options): View
    {
        return Page::render('property/rate-plans/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'room_type', 'meal_plan', 'status', 'tab', 'sort', 'dir', 'selected']),
            'options' => ['room_types' => $options->roomTypes(), 'meal_plans' => $options->mealPlans()],
            'can' => $this->can(['create' => 'rate_plans.create', 'update' => 'rate_plans.update', 'calendar' => 'calendar.view']),
        ], __('nav.rate_plans'));
    }

    public function create(FormOptions $options): View
    {
        return Page::render('property/rate-plans/form', [
            'rate_plan' => null,
            'options' => $this->formOptions($options),
        ], __('rates.add_rate_plan'));
    }

    public function edit(Request $request, FormOptions $options, mixed $property, string $ratePlan): View
    {
        $plan = RatePlan::query()->where('public_id', $ratePlan)->firstOrFail();

        return Page::render('property/rate-plans/form', [
            'rate_plan' => (new RatePlanResource($plan))->resolve($request),
            'options' => $this->formOptions($options),
        ], __('rates.edit_rate_plan'));
    }

    /** @return array<string, mixed> */
    private function formOptions(FormOptions $options): array
    {
        return [
            'meal_plans' => $options->mealPlans(),
            'policies' => $options->cancellationPolicies(),
            'payment_types' => $options->paymentTypes(),
            'room_types' => $options->roomTypes(),
            'rate_plans' => $options->ratePlans(),
            'age_bands' => $options->ageBands(),
        ];
    }
}

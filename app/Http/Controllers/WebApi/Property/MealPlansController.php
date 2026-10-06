<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Rates\MealPlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\Accommodation\SaveMealPlanRequest;
use Illuminate\Http\JsonResponse;

/** Custom meal plans, created from the rate plan form. */
class MealPlansController extends Controller
{
    public function store(SaveMealPlanRequest $request, MealPlanService $mealPlans, FormOptions $options): JsonResponse
    {
        $plan = $mealPlans->createCustom($request->validated());

        return response()->json([
            'message' => __('rates.meal_plan_custom.saved'),
            'meal_plan' => $plan->code,
            'meal_plans' => $options->mealPlans(),
        ], 201);
    }
}

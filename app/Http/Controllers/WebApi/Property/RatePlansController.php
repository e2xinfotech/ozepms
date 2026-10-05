<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\Queries\HistoryQuery;
use App\Domain\Rates\RatePlanService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\SaveRatePlanRequest;
use App\Http\Requests\Property\Accommodation\StatusRequest;
use App\Http\Resources\Accommodation\RatePlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatePlansController extends Controller
{
    use FindsAccommodation;

    public function __construct(private readonly RatePlanService $plans) {}

    public function show(Request $request, HistoryQuery $history, mixed $property, string $ratePlan): JsonResponse
    {
        $plan = $this->ratePlanOr404($ratePlan);

        return response()->json(['rate_plan' => (new RatePlanResource($plan))->resolve($request) + ['history' => $history->for($plan)]]);
    }

    public function store(SaveRatePlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($this->payload($request));

        return response()->json([
            'message' => __('rates.messages.created'),
            'rate_plan' => (new RatePlanResource($plan->refresh()))->resolve($request),
        ], 201);
    }

    public function update(SaveRatePlanRequest $request, mixed $property, string $ratePlan): JsonResponse
    {
        $plan = $this->ratePlanOr404($ratePlan);
        $this->plans->update($plan, $this->payload($request));

        return response()->json([
            'message' => __('rates.messages.updated'),
            'rate_plan' => (new RatePlanResource($plan->refresh()))->resolve($request),
        ]);
    }

    public function status(StatusRequest $request, mixed $property, string $ratePlan): JsonResponse
    {
        $plan = $this->plans->setActive($this->ratePlanOr404($ratePlan), (bool) $request->validated('is_active'));

        return response()->json([
            'message' => $plan->is_active ? __('rates.messages.activated') : __('rates.messages.deactivated'),
            'is_active' => $plan->is_active,
        ]);
    }

    public function copy(Request $request, mixed $property, string $ratePlan): JsonResponse
    {
        $copy = $this->plans->copy($this->ratePlanOr404($ratePlan));

        return response()->json([
            'message' => __('rates.messages.copied', ['code' => $copy->code]),
            'rate_plan' => (new RatePlanResource($copy))->resolve($request),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function payload(SaveRatePlanRequest $request): array
    {
        $data = $request->validated();
        if (isset($data['meal_plan'])) {
            $data['meal_plan'] = $this->mealPlanField($data['meal_plan'], 'meal_plan');
        }
        if (isset($data['cancellation_policy'])) {
            $data['cancellation_policy'] = $this->policyField($data['cancellation_policy'], 'cancellation_policy');
        }
        if (isset($data['deposit_value'])) {
            $data['deposit_value'] = (string) $data['deposit_value'];
        }

        if (isset($data['room_types'])) {
            $data['room_types'] = array_map(function (array $entry, int $i) {
                $entry['room_type'] = $this->roomTypeField($entry['room_type_id'], "room_types.$i.room_type_id");
                $entry['parent_rate_plan'] = ! empty($entry['parent_rate_plan_id'])
                    ? $this->ratePlanField($entry['parent_rate_plan_id'], "room_types.$i.parent_rate_plan_id")
                    : null;
                foreach (['default_price', 'adjust_value'] as $key) {
                    if (isset($entry[$key])) {
                        $entry[$key] = (string) $entry[$key];
                    }
                }

                return $entry;
            }, $data['room_types'], array_keys($data['room_types']));
        }

        return $data;
    }
}

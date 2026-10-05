<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\Queries\HistoryQuery;
use App\Domain\Tax\TaxRuleService;
use App\Domain\Tax\TaxService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\SaveTaxRuleRequest;
use App\Http\Requests\Property\Accommodation\StatusRequest;
use App\Http\Requests\Property\Accommodation\TaxPreviewRequest;
use App\Http\Resources\Accommodation\TaxRuleResource;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Money;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxesController extends Controller
{
    use FindsAccommodation;

    public function __construct(private readonly TaxRuleService $rules) {}

    public function show(Request $request, HistoryQuery $history, mixed $property, string $tax): JsonResponse
    {
        $rule = $this->taxRuleOr404($tax);

        return response()->json(['tax' => (new TaxRuleResource($rule))->resolve($request) + ['history' => $history->for($rule)]]);
    }

    public function store(SaveTaxRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($this->payload($request));

        return response()->json(['message' => __('taxes.messages.created'), 'tax' => (new TaxRuleResource($rule->refresh()))->resolve($request)], 201);
    }

    public function update(SaveTaxRuleRequest $request, mixed $property, string $tax): JsonResponse
    {
        $rule = $this->rules->update($this->taxRuleOr404($tax), $this->payload($request));

        return response()->json(['message' => __('taxes.messages.updated'), 'tax' => (new TaxRuleResource($rule->refresh()))->resolve($request)]);
    }

    public function status(StatusRequest $request, mixed $property, string $tax): JsonResponse
    {
        $rule = $this->rules->setActive($this->taxRuleOr404($tax), (bool) $request->validated('is_active'));

        return response()->json([
            'message' => $rule->is_active ? __('taxes.messages.activated') : __('taxes.messages.deactivated'),
            'is_active' => $rule->is_active,
        ]);
    }

    public function default(Request $request, mixed $property, string $tax): JsonResponse
    {
        $request->validate(['default' => ['required', 'boolean']]);
        $rule = $this->rules->setDefaultForNewRoomTypes($this->taxRuleOr404($tax), $request->boolean('default'));

        return response()->json(['message' => __('taxes.messages.updated'), 'is_default_for_new_room_types' => $rule->is_default_for_new_room_types]);
    }

    public function destroy(mixed $property, string $tax): JsonResponse
    {
        $this->rules->delete($this->taxRuleOr404($tax));

        return response()->json(['message' => __('taxes.messages.deleted')]);
    }

    public function copyTemplates(): JsonResponse
    {
        $count = $this->rules->copyTemplates();

        return response()->json(['message' => trans_choice('taxes.messages.templates_copied', $count, ['count' => $count]), 'copied' => $count]);
    }

    /** Calculates the taxes on one room charge with the property's current rules. */
    public function preview(TaxPreviewRequest $request, TaxService $taxes, PropertyContext $context): JsonResponse
    {
        $property = $context->property();
        $data = $request->validated();
        $nights = (int) $data['nights'];
        $tariff = Money::round((string) $data['tariff'], 2);
        $amount = Money::round(Money::mul($tariff, $nights), 2);

        $result = $taxes->calculate($property, [[
            'category' => 'accommodation',
            'amount' => $amount,
            'unit_night_tariff' => $tariff,
            'date' => $data['date'] ?? now($property->timezone ?: config('app.timezone'))->toDateString(),
            'nights' => $nights,
            'persons' => (int) ($data['persons'] ?? 1),
        ]], $data['guest_state'] ?? null);

        return response()->json([
            'currency' => $result->currency,
            'taxable' => $result->taxableTotal,
            'tax_total' => $result->taxTotal,
            'total' => Money::round(Money::add($result->taxableTotal, $result->taxTotal), 2),
            'components' => $result->lines[0]['components'] ?? [],
            'by_component' => $result->byComponent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(SaveTaxRuleRequest $request): array
    {
        $data = $request->validated();
        foreach (['rate', 'slab_min', 'slab_max'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('room_types', $data)) {
            $data['room_type_ids'] = $this->internalIds(RoomType::query(), $data['room_types'] ?? [], 'room_types');
            unset($data['room_types']);
        }
        if (array_key_exists('rate_plans', $data)) {
            $data['rate_plan_ids'] = $this->internalIds(RatePlan::query(), $data['rate_plans'] ?? [], 'rate_plans');
            unset($data['rate_plans']);
        }

        return $data;
    }
}

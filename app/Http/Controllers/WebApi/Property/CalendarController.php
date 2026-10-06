<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Inventory\Calendar\CalendarEditService;
use App\Domain\Inventory\Queries\CalendarQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\Calendar\BulkUpdateCalendarRequest;
use App\Http\Requests\Property\Calendar\CalendarGridRequest;
use App\Http\Requests\Property\Calendar\UpdateCalendarCellRequest;
use App\Http\Requests\Property\Calendar\UpdateCalendarRangeRequest;
use App\Http\Resources\Calendar\AriChangeResource;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;

/** Calendar data (one window per request) and the three kinds of edit: cell, range, bulk. */
class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarQuery $query,
        private readonly CalendarEditService $edits,
        private readonly PropertyContext $context,
    ) {}

    public function grid(CalendarGridRequest $request): JsonResponse
    {
        $property = $this->context->property();
        [$from, $days] = CalendarQuery::resolveWindow($request->validated('range') ?? 'month', $request->validated('from'), $property);

        return response()->json(['grid' => $this->query->window($property, $from, $days, $request->filters())]);
    }

    public function cell(UpdateCalendarCellRequest $request): JsonResponse
    {
        $date = $request->validated('date');
        $roomType = $request->validated('room_type_id');
        $product = $request->validated('product_id');

        return $this->respond($this->edits->apply(
            $this->context->property(), $date, $date, $request->ariValues(),
            $roomType ? [$roomType] : [], $product ? [$product] : [], [], [], $request->user(),
        ));
    }

    public function range(UpdateCalendarRangeRequest $request): JsonResponse
    {
        return $this->respond($this->edits->apply(
            $this->context->property(), $request->validated('date_from'), $request->validated('date_to'), $request->ariValues(),
            $request->validated('room_type_ids') ?? [], $request->validated('product_ids') ?? [], [], [], $request->user(),
        ));
    }

    public function bulk(BulkUpdateCalendarRequest $request): JsonResponse
    {
        return $this->respond($this->edits->apply(
            $this->context->property(), $request->validated('date_from'), $request->validated('date_to'), $request->ariValues(),
            $request->validated('room_type_ids') ?? [], $request->validated('product_ids') ?? [], $request->validated('rate_plan_ids') ?? [],
            array_map('intval', $request->validated('weekdays') ?? []), $request->user(),
        ));
    }

    /** @param  array<string, mixed>  $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json(['message' => __('calendar.messages.saved'), 'result' => (new AriChangeResource($result))->resolve()]);
    }
}

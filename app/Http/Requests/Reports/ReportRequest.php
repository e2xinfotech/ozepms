<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportFilter;
use App\Models\BookingSource;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Filters of a report page / JSON / CSV request. Missing dates take the report's default period. */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'group' => ['nullable', Rule::in(ReportFilter::GROUPS)],
            'room_type' => ['nullable', 'string', 'max:26'],
            'rate_plan' => ['nullable', 'string', 'max:26'],
            'source' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['inquiry', 'hold', 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'])],
            'basis' => ['nullable', Rule::in(['booked', 'arrival', 'stay'])],
            'pickup_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function attributes(): array
    {
        return ['from' => __('reports.filters.from'), 'to' => __('reports.filters.to'), 'date' => __('reports.filters.date')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $from = $this->input('from');
            $to = $this->input('to');
            if ($from && $to && $to < $from) {
                $v->errors()->add('to', __('reports.errors.order'));
            } elseif ($from && $to && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > (int) config('ozepms.reports.max_days', 400)) {
                $v->errors()->add('to', __('reports.errors.too_long', ['days' => (int) config('ozepms.reports.max_days', 400)]));
            }
        });
    }

    /** Filter for $key, with the report's default period when no dates were given. */
    public function filter(string $key, PropertyContext $context): ReportFilter
    {
        $property = $context->property();
        $today = CarbonImmutable::parse(app(\App\Domain\Inventory\InventoryService::class)->today($property->id)->toDateString());
        $range = ReportCatalog::REPORTS[$key]['range'];
        if ($range === 'day') {
            $from = $to = $this->validated('date') ? CarbonImmutable::parse($this->validated('date')) : $today;
        } else {
            [$dFrom, $dTo] = match ($range) {
                'past' => [$today->subDays(29), $today],
                'future' => [$today, $today->addDays(29)],
                default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            };
            $from = $this->validated('from') ? CarbonImmutable::parse($this->validated('from')) : $dFrom;
            $to = $this->validated('to') ? CarbonImmutable::parse($this->validated('to')) : ($this->validated('from') ? $from->addDays((int) $dFrom->diffInDays($dTo)) : $dTo);
        }
        $id = fn (string $model, ?string $public) => $public ? (int) ($model::query()->where('public_id', $public)->value('id') ?? -1) : null;

        return new ReportFilter(
            property: $property,
            from: $from,
            to: $to,
            group: (string) ($this->validated('group') ?? ($from->diffInDays($to) > 92 ? 'month' : 'day')),
            roomTypeId: $id(RoomType::class, $this->validated('room_type')),
            ratePlanId: $id(RatePlan::class, $this->validated('rate_plan')),
            sourceId: $this->validated('source') ? (int) (BookingSource::query()->where('code', $this->validated('source'))->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $property->id))->value('id') ?? -1) : null,
            status: $this->validated('status'),
            basis: (string) ($this->validated('basis') ?? 'booked'),
            pickupDays: (int) ($this->validated('pickup_days') ?? 7),
            page: (int) ($this->validated('page') ?? 1),
        );
    }
}

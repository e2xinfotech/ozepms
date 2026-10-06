<?php

namespace App\Http\Requests\Property\Calendar;

use App\Domain\Inventory\AriCopyBuilder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Copy Values": copy rates and/or restrictions from a source date range (of the same rate plan or
 * of another one) onto a target date range, for the chosen rate plans and room types.
 * Range lengths, "rates or restrictions" and the result of the date mapping are checked by
 * App\Domain\Inventory\AriCopyBuilder so preview and copy give the same messages.
 */
class CopyCalendarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source_from' => ['required', 'date_format:Y-m-d'],
            'source_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:source_from'],
            'target_from' => ['required', 'date_format:Y-m-d'],
            'target_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:target_from'],
            'source_rate_plan_id' => ['nullable', 'string', 'max:26'],
            'rate_plan_ids' => ['required', 'array', 'min:1', 'max:200'],
            'rate_plan_ids.*' => ['string', 'max:26', 'distinct'],
            'room_type_ids' => ['nullable', 'array', 'max:200'],
            'room_type_ids.*' => ['string', 'max:26', 'distinct'],
            'copy_rates' => ['required', 'boolean'],
            'copy_restrictions' => ['required', 'boolean'],
            'align_weekdays' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'source_from' => __('calendar.copy.source_from'),
            'source_to' => __('calendar.copy.source_to'),
            'target_from' => __('calendar.copy.target_from'),
            'target_to' => __('calendar.copy.target_to'),
            'rate_plan_ids' => __('calendar.copy.target_rate_plans'),
            'room_type_ids' => __('calendar.bulk.room_types'),
            'source_rate_plan_id' => __('calendar.copy.source_rate_plan'),
        ];
    }

    /** @return array<string, mixed> */
    public function copyInput(): array
    {
        $v = $this->validated();

        return [
            'source_from' => $v['source_from'], 'source_to' => $v['source_to'],
            'target_from' => $v['target_from'], 'target_to' => $v['target_to'],
            'source_rate_plan_id' => $v['source_rate_plan_id'] ?? null,
            'rate_plan_ids' => array_values($v['rate_plan_ids']),
            'room_type_ids' => array_values($v['room_type_ids'] ?? []),
            'copy_rates' => (bool) $v['copy_rates'],
            'copy_restrictions' => (bool) $v['copy_restrictions'],
            'align_weekdays' => (bool) ($v['align_weekdays'] ?? false),
        ];
    }

    /** Longest ranges, for the page. */
    public static function limits(): array
    {
        return ['source_days' => AriCopyBuilder::MAX_SOURCE_DAYS, 'target_days' => AriCopyBuilder::MAX_TARGET_DAYS];
    }
}

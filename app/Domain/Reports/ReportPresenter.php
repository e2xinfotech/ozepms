<?php

namespace App\Domain\Reports;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Reports\Queries\BookingReports;
use App\Models\BookingSource;
use App\Models\Property;

/** Page data of the reports: catalog cards, filter values and the options of the filter controls. */
class ReportPresenter
{
    public function __construct(private readonly FormOptions $options) {}

    public function catalog(Property $property): array
    {
        return collect(ReportCatalog::REPORTS)->map(fn (array $r, string $key) => [
            'key' => $key, 'slug' => ReportCatalog::slug($key), 'icon' => $r['icon'], 'group' => $r['group'],
            'title' => __("reports.{$key}.title"), 'description' => __("reports.{$key}.description"),
            'url' => route('property.reports.show', ['property' => $property->code, 'report' => ReportCatalog::slug($key)]),
        ])->values()->all();
    }

    public function filters(ReportFilter $f): array
    {
        return [
            'from' => $f->fromDate(), 'to' => $f->toDate(), 'date' => $f->fromDate(), 'group' => $f->group,
            'room_type' => request()->query('room_type'), 'source' => request()->query('source'), 'status' => $f->status,
            'basis' => $f->basis, 'pickup_days' => $f->pickupDays, 'page' => $f->page,
        ];
    }

    public function options(Property $property): array
    {
        return [
            'room_types' => collect($this->options->roomTypes())->map(fn ($r) => ['value' => $r['value'], 'label' => $r['name']])->all(),
            'sources' => BookingSource::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $property->id))
                ->orderBy('id')->get(['code', 'name'])->map(fn ($s) => ['value' => $s->code, 'label' => BookingReports::sourceName($s->code, $s->name)])->all(),
            'statuses' => collect(['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show', 'hold', 'inquiry'])
                ->map(fn ($s) => ['value' => $s, 'label' => __('reservations.status.'.$s)])->all(),
            'max_days' => (int) config('ozepms.reports.max_days', 400),
            'currency' => (string) $property->currency_code,
            'today' => app(\App\Domain\Inventory\InventoryService::class)->today($property->id)->toDateString(),
        ];
    }
}

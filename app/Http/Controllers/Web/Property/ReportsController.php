<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportPresenter;
use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;

/** Reports hub and one report page (data of the first view included; filter changes use the JSON endpoint). */
class ReportsController extends Controller
{
    public function index(PropertyContext $context, ReportPresenter $presenter): View
    {
        return Page::render('property/reports/index', ['reports' => $presenter->catalog($context->property())], __('reports.title'));
    }

    public function show(ReportRequest $request, PropertyContext $context, ReportService $reports, ReportPresenter $presenter, mixed $property, string $report): View
    {
        $key = ReportCatalog::key($report);
        abort_unless(ReportCatalog::exists($key), 404);
        $filter = $request->filter($key, $context);

        return Page::render('property/reports/show', [
            'report' => ['key' => $key, 'slug' => $report, 'title' => __("reports.{$key}.title"), 'description' => __("reports.{$key}.description")] + ReportCatalog::REPORTS[$key],
            'catalog' => $presenter->catalog($context->property()),
            'filters' => $presenter->filters($filter),
            'data' => $reports->run($key, $filter),
            'options' => $presenter->options($context->property()),
        ], __("reports.{$key}.title"));
    }
}

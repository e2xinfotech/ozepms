<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportPresenter;
use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Report data as JSON (filter changes) and as CSV download. */
class ReportsController extends Controller
{
    public function show(ReportRequest $request, PropertyContext $context, ReportService $reports, ReportPresenter $presenter, mixed $property, string $report): JsonResponse
    {
        $key = $this->key($report);
        $filter = $request->filter($key, $context);

        return response()->json(['filters' => $presenter->filters($filter), 'data' => $reports->run($key, $filter)]);
    }

    public function export(ReportRequest $request, PropertyContext $context, ReportService $reports, mixed $property, string $report): StreamedResponse
    {
        $key = $this->key($report);
        $filter = $request->filter($key, $context);
        $p = $context->property();
        $name = $report.'-'.$p->code.'-'.$filter->fromDate().($filter->toDate() !== $filter->fromDate() ? '_'.$filter->toDate() : '').'.csv';

        return response()->streamDownload(function () use ($reports, $key, $filter) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps show accents correctly
            foreach ($reports->csv($key, $filter) as $row) {
                \App\Support\Csv::put($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function key(string $slug): string
    {
        $key = ReportCatalog::key($slug);
        abort_unless(ReportCatalog::exists($key), 404);

        return $key;
    }
}

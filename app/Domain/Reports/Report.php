<?php

namespace App\Domain\Reports;

use App\Support\Money;

/**
 * Small helpers that give every report the same shape:
 *
 *   summary: [{key, label, type, value, previous?}]          KPI tiles (previous = same length before)
 *   chart:   {labels, bars, line, bar_label, line_label, bar_type, line_type} | null
 *   tables:  [{key, title, columns: [{key, label, type}], rows: [...], totals?: {...}, pagination?: {...}}]
 *
 * Column / value types: text, period, date, datetime, int, money, percent, status, ref (booking
 * reference with link). Money values are decimal strings; percentages are numbers with one decimal.
 */
final class Report
{
    public static function col(string $key, string $type, ?string $label = null): array
    {
        return ['key' => $key, 'label' => $label ?? __('reports.col.'.$key), 'type' => $type];
    }

    public static function kpi(string $key, string $type, mixed $value, mixed $previous = null): array
    {
        return ['key' => $key, 'label' => __('reports.kpi.'.$key), 'type' => $type, 'value' => $value, 'previous' => $previous];
    }

    public static function table(string $key, array $columns, array $rows, ?array $totals = null, ?array $pagination = null): array
    {
        return ['key' => $key, 'title' => __('reports.table.'.$key), 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'pagination' => $pagination];
    }

    public static function money(mixed $value): string
    {
        return Money::round((string) ($value ?? '0'), 2);
    }

    public static function pct(float|int|string|null $part, float|int|string|null $whole): float
    {
        return (float) $whole > 0 ? round((float) $part / (float) $whole * 100, 1) : 0.0;
    }

    public static function ratio(mixed $amount, float|int|string|null $count): string
    {
        return (float) $count > 0 ? Money::round(Money::div((string) ($amount ?? '0'), (string) $count), 2) : '0.00';
    }

    /** Occupancy, ADR and RevPAR from rooms available / sold and room revenue. */
    public static function performance(int $available, int $sold, mixed $revenue): array
    {
        return [
            'available' => $available,
            'sold' => $sold,
            'occupancy' => self::pct($sold, $available),
            'revenue' => self::money($revenue),
            'adr' => self::ratio($revenue, $sold),
            'revpar' => self::ratio($revenue, $available),
        ];
    }
}

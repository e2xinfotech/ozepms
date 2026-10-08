<?php

namespace App\Support;

/** CSV rows for downloads. A cell that starts with = + - @ (or a tab / carriage return) would run as a formula in Excel, so it is marked as plain text. */
final class Csv
{
    /** @param  array<int, mixed>  $row */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $row));
    }

    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) && ! preg_match('/^-?\d+([.,]\d+)?$/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}

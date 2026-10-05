<?php

namespace App\Domain\Accommodation;

use Illuminate\Validation\ValidationException;

/**
 * Turns the bulk "add rooms" input into room names:
 *   range     "101-120" or "A01-A10"     → 101 … 120
 *   sequence  prefix "DLX-", start 1, count 5 → DLX-1 … DLX-5 (zero padded to the start's width)
 *   quantity  5 with code "DLX" and 2 existing  → DLX-03 … DLX-07
 */
final class UnitNameGenerator
{
    public const MAX_PER_REQUEST = 500;

    /**
     * @param  array{mode: string, range?: string, prefix?: string, start?: int|string, count?: int|string, quantity?: int|string}  $spec
     * @param  list<string>  $existingNames  names already used by the room type (for "quantity")
     * @return list<string>
     */
    public function generate(array $spec, string $roomTypeCode, array $existingNames = []): array
    {
        $names = match ($spec['mode'] ?? '') {
            'range' => $this->range((string) ($spec['range'] ?? '')),
            'sequence' => $this->sequence((string) ($spec['prefix'] ?? ''), (string) ($spec['start'] ?? '1'), (int) ($spec['count'] ?? 0)),
            'quantity' => $this->quantity($roomTypeCode, (int) ($spec['quantity'] ?? 0), $existingNames),
            default => throw ValidationException::withMessages(['mode' => __('rooms.errors.bulk_mode')]),
        };

        if (count($names) > self::MAX_PER_REQUEST) {
            throw ValidationException::withMessages(['count' => __('rooms.errors.bulk_too_many', ['max' => self::MAX_PER_REQUEST])]);
        }

        return $names;
    }

    /** @return list<string> */
    private function range(string $range): array
    {
        if (! preg_match('/^\s*([A-Za-z]*[-\s]?)(\d+)\s*-\s*(?:\1)?(\d+)\s*$/', $range, $m)) {
            throw ValidationException::withMessages(['range' => __('rooms.errors.bulk_range_format')]);
        }
        [$prefix, $from, $to] = [$m[1], $m[2], $m[3]];
        if ((int) $to < (int) $from) {
            throw ValidationException::withMessages(['range' => __('rooms.errors.bulk_range_order')]);
        }
        $this->assertCount((int) $to - (int) $from + 1, 'range');

        $width = strlen($from);

        return array_map(fn (int $n) => $prefix.str_pad((string) $n, $width, '0', STR_PAD_LEFT), range((int) $from, (int) $to));
    }

    /** @return list<string> */
    private function sequence(string $prefix, string $start, int $count): array
    {
        if (! ctype_digit($start)) {
            throw ValidationException::withMessages(['start' => __('rooms.errors.bulk_start')]);
        }
        $this->assertCount($count, 'count');
        $width = strlen($start);

        return array_map(fn (int $i) => $prefix.str_pad((string) ((int) $start + $i), $width, '0', STR_PAD_LEFT), range(0, $count - 1));
    }

    /** @param  list<string>  $existing
     *  @return list<string> */
    private function quantity(string $code, int $quantity, array $existing): array
    {
        $this->assertCount($quantity, 'quantity');
        $prefix = strtoupper($code).'-';

        $highest = 0;
        foreach ($existing as $name) {
            if (str_starts_with($name, $prefix) && ctype_digit(substr($name, strlen($prefix)))) {
                $highest = max($highest, (int) substr($name, strlen($prefix)));
            }
        }

        $width = max(2, strlen((string) ($highest + $quantity)));

        return array_map(fn (int $i) => $prefix.str_pad((string) ($highest + $i), $width, '0', STR_PAD_LEFT), range(1, $quantity));
    }

    private function assertCount(int $count, string $field): void
    {
        if ($count < 1) {
            throw ValidationException::withMessages([$field => __('rooms.errors.bulk_empty')]);
        }
        if ($count > self::MAX_PER_REQUEST) {
            throw ValidationException::withMessages([$field => __('rooms.errors.bulk_too_many', ['max' => self::MAX_PER_REQUEST])]);
        }
    }
}

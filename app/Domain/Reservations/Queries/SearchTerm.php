<?php

namespace App\Domain\Reservations\Queries;

/**
 * Classifies a search box input so only the matching indexed query runs (architecture §10):
 * booking reference, e-mail, phone digits, PMS room number or name.
 */
final class SearchTerm
{
    public function __construct(
        public readonly string $raw,
        public readonly string $kind,      // ref | email | phone | name
        public readonly string $value,     // normalised value for that kind
        public readonly ?string $room,     // also try as PMS room number
    ) {}

    public static function parse(string $input): ?self
    {
        $raw = trim(mb_substr($input, 0, 100));
        if (mb_strlen($raw) < 2) {
            return null;
        }
        $room = preg_match('/^[A-Za-z0-9-]{1,10}$/', $raw) ? $raw : null;
        if (preg_match('/^R-?(\d{3,})$/i', $raw, $m)) {
            return new self($raw, 'ref', 'R-'.$m[1], null);
        }
        if (str_contains($raw, '@')) {
            return new self($raw, 'email', mb_strtolower($raw), null);
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (preg_match('/^[\d\s+().-]+$/', $raw) && strlen($digits) >= 4) {
            return new self($raw, 'phone', $digits, strlen($digits) <= 5 ? $raw : null);
        }

        return new self($raw, 'name', $raw, $room);
    }

    /** Pattern for a LIKE prefix search, wildcards in the input escaped. */
    public static function prefix(string $value): string
    {
        return addcslashes($value, '%_\\').'%';
    }

    /** Boolean-mode FULLTEXT query (ngram): every word required. */
    public function fulltext(): string
    {
        $words = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $this->value) ?? '') ?: [];

        return implode(' ', array_map(fn ($w) => '+'.$w, array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
    }
}

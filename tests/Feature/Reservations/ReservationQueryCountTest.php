<?php

namespace Tests\Feature\Reservations;

use App\Models\ReservationRoom;
use Illuminate\Support\Facades\DB;

/**
 * Lists, panels and search must not grow with the number of rows (no N+1). Counts include the
 * request's own middleware queries (session user, property, membership, permissions).
 */
class ReservationQueryCountTest extends ReservationTestCase
{
    /** Module queries stay under ~15; every request adds about 5 for the session user, property, membership and permissions. */
    private const MAX = 20;

    protected function setUp(): void
    {
        parent::setUp();
        $units = $this->units($this->deluxe);
        for ($i = 0; $i < 12; $i++) {
            $r = $this->book([$this->room($this->dlxBar, $i % 4, $i % 4 + 1), $this->room($this->steBar, 20 + $i, 21 + $i)], [
                'guest' => ['first_name' => 'Guest'.$i, 'last_name' => 'Test', 'email' => "g{$i}@example.com"],
            ]);
            if ($i < 3) {
                $this->inProperty($this->property);
                $this->service()->assignUnit($r, ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->where('room_type_id', $this->deluxe->id)->first(), $units[$i], $this->owner);
            }
        }
        app(\App\Support\PropertyContext::class)->clear();
    }

    private function queries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $n = count(DB::getQueryLog());
        if (getenv('SHOW_QUERIES')) {
            fwrite(STDERR, implode("\n", array_map(fn ($q) => substr($q['query'], 0, 160), DB::getQueryLog()))."\n----\n");
        }
        DB::disableQueryLog();

        return $n;
    }

    public function test_query_counts_stay_small(): void
    {
        $this->actingAs($this->owner);
        $id = DB::table('reservations')->value('public_id');
        $guest = DB::table('guests')->value('public_id');
        $checks = [
            'detail' => fn () => $this->getJson($this->api('/reservations/'.$id))->assertOk(),
            'history' => fn () => $this->getJson($this->api('/reservations/'.$id.'/history'))->assertOk(),
            'search' => fn () => $this->getJson($this->api('/search?q=Guest1'))->assertOk(),
            'guest profile' => fn () => $this->getJson($this->api('/guests/'.$guest))->assertOk(),
        ];
        foreach ($checks as $name => $call) {
            $n = $this->queries($call);
            fwrite(STDERR, "{$name}: {$n}\n");
            $this->assertLessThanOrEqual(self::MAX, $n, "{$name}: {$n} queries");
        }

        // Page lists: the same count for 12 rows as for 2 rows (no per-row query).
        // [many rows, few rows]: the count must not grow with the rows (no per-row query).
        $pages = [
            ['/reservations?per_page=10', '/reservations?per_page=10&page=2'],
            ['/front-desk', '/front-desk?q=Guest0'],
            ['/guests?per_page=10', '/guests?per_page=10&page=2'],
        ];
        foreach ($pages as [$many, $few]) {
            $this->get($this->page($many))->assertOk(); // warm per-request caches (lookups)
            $all = $this->queries(fn () => $this->get($this->page($many))->assertOk());
            $two = $this->queries(fn () => $this->get($this->page($few))->assertOk());
            fwrite(STDERR, "{$many}: {$all} / {$two}\n");
            $this->assertSame($two, $all, "{$many}: {$all} queries for many rows vs {$two} for a few");
            // Page = shell (user, property switcher, subscription: about 9 queries) + the list.
            $this->assertLessThanOrEqual(self::MAX + 5, $all);
        }
    }
}

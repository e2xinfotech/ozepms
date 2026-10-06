<?php

namespace Tests\Unit\Inventory;

use App\Domain\Inventory\AriCopyBuilder;
use PHPUnit\Framework\TestCase;

/** Date mapping and change-set ranges of "copy values". */
class AriCopyBuilderTest extends TestCase
{
    private function dates(string $from, int $days): array
    {
        return array_map(fn ($i) => date('Y-m-d', strtotime("$from +$i days")), range(0, $days - 1));
    }

    public function test_plain_mapping_repeats_the_source(): void
    {
        $map = (new AriCopyBuilder)->mapDates($this->dates('2026-11-02', 3), $this->dates('2026-12-01', 7), false);

        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-02'], array_values($map));
    }

    public function test_aligned_mapping_keeps_weekdays_and_cycles_weeks(): void
    {
        // Source: two weeks starting Monday 2 Nov 2026. Target: Thursday 3 Dec for 15 nights.
        $map = (new AriCopyBuilder)->mapDates($this->dates('2026-11-02', 14), $this->dates('2026-12-03', 15), true);

        foreach ($map as $target => $source) {
            $this->assertSame(date('N', strtotime($target)), date('N', strtotime($source)));
        }
        $this->assertSame('2026-11-05', $map['2026-12-03']);   // first Thursday of the source
        $this->assertSame('2026-11-12', $map['2026-12-10']);   // second Thursday
        $this->assertSame('2026-11-05', $map['2026-12-17']);   // back to the first
    }

    public function test_aligned_mapping_skips_missing_weekdays(): void
    {
        $map = (new AriCopyBuilder)->mapDates(['2026-11-06', '2026-11-07'], $this->dates('2026-11-30', 7), true);   // Fri + Sat

        $this->assertSame(['2026-12-04' => '2026-11-06', '2026-12-05' => '2026-11-07'], $map);
    }

    public function test_segments_use_a_weekday_filter_for_weekly_patterns(): void
    {
        $b = new AriCopyBuilder;
        $weekends = array_values(array_filter($this->dates('2026-11-02', 28), fn ($d) => date('N', strtotime($d)) >= 6));

        $this->assertSame([['2026-11-07', '2026-11-29', [6, 7]]], $b->segments($weekends));
        $this->assertSame([['2026-11-02', '2026-11-05', []]], $b->segments($this->dates('2026-11-02', 4)));
        // Mon, Tue, Fri of one week is still a weekly pattern; Mon 2, Tue 3 and Tue 10 (Mon 9 missing) is not.
        $this->assertSame([['2026-11-02', '2026-11-06', [1, 2, 5]]], $b->segments(['2026-11-02', '2026-11-03', '2026-11-06']));
        $this->assertSame([['2026-11-02', '2026-11-03', []], ['2026-11-10', '2026-11-10', []]], $b->segments(['2026-11-02', '2026-11-03', '2026-11-10']));
    }
}

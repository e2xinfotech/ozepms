<?php

namespace Tests\Unit\Availability;

use App\Domain\Availability\RestrictionEvaluator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RestrictionEvaluatorTest extends TestCase
{
    private RestrictionEvaluator $evaluator;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        $this->evaluator = new RestrictionEvaluator;
        $this->today = CarbonImmutable::parse('2026-11-01');
    }

    /** Open rows for 2026-11-10 … 2026-11-20 with overrides per date. */
    private function rows(array $overrides = []): array
    {
        $rows = [];
        for ($d = CarbonImmutable::parse('2026-11-10'); $d->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-20')); $d = $d->addDay()) {
            $rows[$d->toDateString()] = array_merge(
                ['stop_sell' => false, 'cta' => false, 'ctd' => false, 'min_los' => null, 'max_los' => null, 'min_los_arrival' => null, 'cutoff_days' => null, 'max_advance_days' => null],
                $overrides[$d->toDateString()] ?? [],
            );
        }

        return $rows;
    }

    private function check(array $rows, string $in, string $out, ?CarbonImmutable $today = null): array
    {
        return $this->evaluator->evaluate($rows, CarbonImmutable::parse($in), CarbonImmutable::parse($out), $today ?? $this->today);
    }

    public function test_open_stay_is_allowed(): void
    {
        $this->assertSame([], $this->check($this->rows(), '2026-11-12', '2026-11-15'));
    }

    public function test_stop_sell_on_any_night_closes_the_stay_but_not_on_departure_day(): void
    {
        $rows = $this->rows(['2026-11-13' => ['stop_sell' => true]]);
        $this->assertSame(['closed'], $this->check($rows, '2026-11-12', '2026-11-15'));
        $this->assertSame([], $this->check($rows, '2026-11-11', '2026-11-13'), 'check-out on a closed date is fine');
        $this->assertSame(['stop_sell'], $this->evaluator->evaluate($rows, CarbonImmutable::parse('2026-11-13'), CarbonImmutable::parse('2026-11-14'), $this->today, 'stop_sell'));
    }

    public function test_cta_only_on_arrival_and_ctd_only_on_departure(): void
    {
        $rows = $this->rows(['2026-11-12' => ['cta' => true, 'ctd' => true]]);
        $this->assertSame(['cta'], $this->check($rows, '2026-11-12', '2026-11-14'));
        $this->assertSame(['ctd'], $this->check($rows, '2026-11-10', '2026-11-12'));
        $this->assertSame([], $this->check($rows, '2026-11-11', '2026-11-13'), 'staying through is allowed');
    }

    public function test_min_and_max_los_apply_on_every_night(): void
    {
        $rows = $this->rows(['2026-11-14' => ['min_los' => 3], '2026-11-15' => ['max_los' => 2]]);
        $this->assertSame(['min_los'], $this->check($rows, '2026-11-13', '2026-11-15'));
        $this->assertSame([], $this->check($rows, '2026-11-12', '2026-11-15'));
        $this->assertSame(['max_los'], $this->check($rows, '2026-11-15', '2026-11-18'));
        $both = $this->rows(['2026-11-14' => ['min_los' => 3], '2026-11-15' => ['max_los' => 1]]);
        $this->assertSame(['min_los', 'max_los'], $this->check($both, '2026-11-14', '2026-11-16'));
    }

    public function test_min_los_on_arrival_only_counts_for_the_arrival_date(): void
    {
        $rows = $this->rows(['2026-11-14' => ['min_los_arrival' => 3]]);
        $this->assertSame(['min_los_arrival'], $this->check($rows, '2026-11-14', '2026-11-16'));
        $this->assertSame([], $this->check($rows, '2026-11-13', '2026-11-15'));
    }

    public function test_cutoff_and_max_advance_use_days_before_arrival(): void
    {
        $rows = $this->rows(['2026-11-10' => ['cutoff_days' => 9, 'max_advance_days' => 20]]);
        $this->assertSame([], $this->check($rows, '2026-11-10', '2026-11-11'), '9 days ahead is enough');
        $this->assertSame(['cutoff'], $this->check($rows, '2026-11-10', '2026-11-11', CarbonImmutable::parse('2026-11-02')));
        $this->assertSame(['max_advance'], $this->check($rows, '2026-11-10', '2026-11-11', CarbonImmutable::parse('2026-10-20')));
    }

    public function test_missing_rows_mean_no_rate(): void
    {
        $rows = $this->rows();
        unset($rows['2026-11-13']);
        $this->assertSame(['no_rate'], $this->check($rows, '2026-11-12', '2026-11-15'));
        $this->assertSame([], $this->check($this->rows(), '2026-11-19', '2026-11-21'), 'a missing departure row is ignored');
    }

    public function test_derived_products_inherit_parent_rows_but_keep_their_own_stop_sell(): void
    {
        $parent = ['stop_sell' => false, 'min_los' => 3, 'cta' => true];
        $own = ['stop_sell' => true, 'min_los' => null, 'cta' => false];

        $this->assertSame(['stop_sell' => true, 'min_los' => 3, 'cta' => true], RestrictionEvaluator::effective($own, $parent, true));
        $this->assertSame($own, RestrictionEvaluator::effective($own, $parent, false));
        $this->assertSame($own, RestrictionEvaluator::effective($own, null, true));
    }
}

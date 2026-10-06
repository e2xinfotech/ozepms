<?php

namespace Tests\Unit\Tax;

use App\Domain\Tax\TaxLine;
use App\Domain\Tax\TaxRuleMatcher;
use App\Models\TaxRule;
use App\Models\TaxRuleScope;
use Tests\TestCase;

/** Room type / rate plan scope of a tax rule: no scope = everything; room type × rate plan rows = both must match. */
class TaxRuleScopeTest extends TestCase
{
    private function rule(array $scopes): TaxRule
    {
        $rule = new TaxRule;
        $rule->setRelation('scopes', collect(array_map(fn (array $s) => new TaxRuleScope($s), $scopes)));

        return $rule;
    }

    private function line(?int $roomType, ?int $ratePlan): TaxLine
    {
        return TaxLine::from(['category' => 'accommodation', 'amount' => '1000.00', 'date' => '2026-10-10', 'room_type_id' => $roomType, 'rate_plan_id' => $ratePlan]);
    }

    public function test_rule_without_scope_applies_everywhere(): void
    {
        $this->assertTrue((new TaxRuleMatcher)->inScope($this->rule([]), $this->line(5, 9)));
    }

    public function test_room_type_scope(): void
    {
        $rule = $this->rule([['room_type_id' => 5, 'rate_plan_id' => null]]);
        $this->assertTrue((new TaxRuleMatcher)->inScope($rule, $this->line(5, 9)));
        $this->assertFalse((new TaxRuleMatcher)->inScope($rule, $this->line(6, 9)));
    }

    public function test_room_type_and_rate_plan_must_both_match(): void
    {
        $rule = $this->rule([['room_type_id' => 5, 'rate_plan_id' => 9], ['room_type_id' => 7, 'rate_plan_id' => 9]]);
        $matcher = new TaxRuleMatcher;
        $this->assertTrue($matcher->inScope($rule, $this->line(5, 9)));
        $this->assertTrue($matcher->inScope($rule, $this->line(7, 9)));
        $this->assertFalse($matcher->inScope($rule, $this->line(5, 8)));
        $this->assertFalse($matcher->inScope($rule, $this->line(6, 9)));
    }
}

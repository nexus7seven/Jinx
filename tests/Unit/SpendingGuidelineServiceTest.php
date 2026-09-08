<?php

namespace Tests\Unit;

use App\Services\SpendingGuidelineService;
use Tests\TestCase;

class SpendingGuidelineServiceTest extends TestCase
{
    public function test_comms_leisure_one_adult(): void
    {
        $s = app(SpendingGuidelineService::class);
        $this->assertEqualsWithDelta(250.0, $s->cap('comms_leisure', 1, 0, 0), 0.0001);
    }

    public function test_comms_leisure_two_adults_one_teen(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 250 + 179 + 140
        $this->assertEqualsWithDelta(569.0, $s->cap('comms_leisure', 2, 0, 1), 0.0001);
    }

    public function test_comms_min_preserves_source_precision(): void
    {
        $s = app(SpendingGuidelineService::class);
        $this->assertEqualsWithDelta(175.0, $s->bounds('comms', 1, 0, 0)['min'], 0.0001);
        $this->assertEqualsWithDelta(125.30, $s->bounds('comms', 2, 0, 0)['min'] - 175.0, 0.0001);
    }

    public function test_food_two_adults_one_child_under_16(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 454 + 333 + 197
        $this->assertEqualsWithDelta(984.0, $s->cap('food', 2, 1, 0), 0.0001);
    }

    public function test_personal_one_adult_two_under_16(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 95 + 47 + 47
        $this->assertEqualsWithDelta(189.0, $s->cap('personal', 1, 2, 0), 0.0001);
    }
}

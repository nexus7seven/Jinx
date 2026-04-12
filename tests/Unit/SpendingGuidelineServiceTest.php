<?php

namespace Tests\Unit;

use App\Services\SpendingGuidelineService;
use Tests\TestCase;

class SpendingGuidelineServiceTest extends TestCase
{
    public function test_comms_leisure_one_adult(): void
    {
        $s = app(SpendingGuidelineService::class);
        $this->assertSame(250.0, $s->cap('comms_leisure', 1, 0, 0));
    }

    public function test_comms_leisure_two_adults_one_teen(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 250 + 178 + 140
        $this->assertSame(568.0, $s->cap('comms_leisure', 2, 0, 1));
    }

    public function test_food_two_adults_one_child_under_16(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 454 + 333 + 197
        $this->assertSame(984.0, $s->cap('food', 2, 1, 0));
    }

    public function test_personal_one_adult_two_under_16(): void
    {
        $s = app(SpendingGuidelineService::class);
        // 95 + 47 + 47
        $this->assertSame(189.0, $s->cap('personal', 1, 2, 0));
    }
}

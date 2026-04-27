<?php

namespace App\Services;

use App\Models\RemarketingStep;
use Illuminate\Support\Carbon;

class RemarketingScheduleWindowService
{
    public static function nextAllowedTime(RemarketingStep $step, Carbon $rawDue): Carbon
    {
        return $rawDue->copy();
    }
}

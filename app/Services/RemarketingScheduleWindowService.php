<?php

namespace App\Services;

use App\Models\RemarketingStep;
use Carbon\Carbon;

class RemarketingScheduleWindowService
{
    public const DAYS_ALL = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAYS_NO_SUNDAY = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const DEFAULT_START_TIME = '09:00:00';

    public const DEFAULT_END_TIME = '21:00:00';

    public const TIMEZONE = 'Europe/London';

    public function isAllowedNow(RemarketingStep $step, ?Carbon $at = null): bool
    {
        $at = ($at ?? Carbon::now(self::TIMEZONE))->copy()->setTimezone(self::TIMEZONE);

        $dayKey = strtolower($at->format('D'));
        if (! in_array($dayKey, $this->allowedDaysForStep($step), true)) {
            return false;
        }

        if (! $step->respect_send_window) {
            return true;
        }

        $startTime = $step->send_window_start_time ?: self::DEFAULT_START_TIME;
        $endTime = $step->send_window_end_time ?: self::DEFAULT_END_TIME;
        $currentTime = $at->format('H:i:s');

        return $currentTime >= $startTime && $currentTime < $endTime;
    }

    public function nextAllowedTime(RemarketingStep $step, Carbon $dueAt): Carbon
    {
        $candidate = $dueAt->copy()->setTimezone(self::TIMEZONE);
        $allowedDays = $this->allowedDaysForStep($step);
        $startTime = $step->send_window_start_time ?: self::DEFAULT_START_TIME;
        $endTime = $step->send_window_end_time ?: self::DEFAULT_END_TIME;

        while (true) {
            $dayKey = strtolower($candidate->format('D'));
            $isAllowedDay = in_array($dayKey, $allowedDays, true);

            if (! $isAllowedDay) {
                $candidate->addDay()->setTimeFromTimeString($startTime);
                continue;
            }

            if (! $step->respect_send_window) {
                return $candidate;
            }

            $timeValue = $candidate->format('H:i:s');
            if ($timeValue < $startTime) {
                return $candidate->copy()->setTimeFromTimeString($startTime);
            }

            if ($timeValue >= $endTime) {
                $candidate->addDay()->setTimeFromTimeString($startTime);
                continue;
            }

            return $candidate;
        }
    }

    public function allowedDaysForStep(RemarketingStep $step): array
    {
        $allowed = $step->allowed_days_json;
        if (is_array($allowed) && $allowed !== []) {
            return array_map(static fn (string $day): string => strtolower($day), $allowed);
        }

        return $step->medium === 'email'
            ? self::DAYS_ALL
            : self::DAYS_NO_SUNDAY;
    }
}

<?php

namespace App\Services;

use App\Models\CompanyProjectAccess;
use Illuminate\Support\Facades\Cache;

class RateLimitService
{
    public function checkRateLimit(CompanyProjectAccess $access): bool
    {
        // Check per-minute limit
        $minuteKey = "rate_limit:{$access->id}:minute:" . now()->format('Y-m-d-H-i');
        $minuteCount = Cache::get($minuteKey, 0);

        if ($minuteCount >= $access->rate_limit_per_minute) {
            return false;
        }

        // Check per-hour limit
        $hourKey = "rate_limit:{$access->id}:hour:" . now()->format('Y-m-d-H');
        $hourCount = Cache::get($hourKey, 0);

        if ($hourCount >= $access->rate_limit_per_hour) {
            return false;
        }

        // Check circuit breaker
        if ($access->circuit_breaker_state === 'open') {
            if ($access->circuit_breaker_reset_at && $access->circuit_breaker_reset_at->isFuture()) {
                return false; // Still in open state
            } else {
                // Try half-open
                $access->circuit_breaker_state = 'half_open';
                $access->save();
            }
        }

        return true;
    }

    public function recordApiCall(CompanyProjectAccess $access, bool $success): void
    {
        // Increment counters
        $minuteKey = "rate_limit:{$access->id}:minute:" . now()->format('Y-m-d-H-i');
        $hourKey = "rate_limit:{$access->id}:hour:" . now()->format('Y-m-d-H');

        Cache::increment($minuteKey, 1, now()->addMinutes(2));
        Cache::increment($hourKey, 1, now()->addHours(2));

        // Update circuit breaker
        if (!$success) {
            $access->circuit_breaker_failures++;

            if ($access->circuit_breaker_failures >= 5) {
                // Open circuit breaker
                $access->circuit_breaker_state = 'open';
                $access->circuit_breaker_reset_at = now()->addMinutes(5);
                $access->save();
            }
        } else {
            // Reset on success
            if ($access->circuit_breaker_state === 'half_open') {
                $access->circuit_breaker_state = 'closed';
                $access->circuit_breaker_failures = 0;
                $access->save();
            }
        }
    }
}

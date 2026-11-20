<?php

namespace Tests\Unit;

use App\Services\SerialGapAnalyticsService;
use Tests\TestCase;

class SerialGapAnalyticsTest extends TestCase
{
    public function test_german_tank_estimator_uses_m_plus_m_over_k_minus_one(): void
    {
        $service = app(SerialGapAnalyticsService::class);

        $this->assertSame(
            ['observed' => 0, 'max' => null, 'estimate' => null, 'coverage' => null],
            $service->estimate([])
        );

        $result = $service->estimate([1, 2, 3]);
        $this->assertSame(3, $result['observed']);
        $this->assertSame(3, $result['max']);
        $this->assertSame(3.0, $result['estimate']);

        $result = $service->estimate([10, 20, 30, 40]);
        $this->assertSame(40, $result['max']);
        $this->assertSame(49.0, $result['estimate']);
    }

    public function test_estimator_ignores_invalid_and_duplicate_observations_deterministically(): void
    {
        $result = app(SerialGapAnalyticsService::class)->estimate([0, -5, 'n/a', 8, 8, 12]);

        $this->assertSame(3, $result['observed']);
        $this->assertSame(12, $result['max']);
        $this->assertSame(15.0, $result['estimate']);
    }
}

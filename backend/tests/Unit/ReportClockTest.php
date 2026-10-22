<?php

namespace Tests\Unit;

use App\Services\ReportClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReportClockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'reports.timezone' => 'Asia/Ho_Chi_Minh',
            'reports.cutoff_time' => '23:00',
            'reports.working_days' => [1, 2, 3, 4, 5, 6],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_monday_and_saturday_are_open_before_2300_and_lock_exactly_at_cutoff(): void
    {
        $clock = app(ReportClock::class);

        foreach (['2026-10-05', '2026-10-10'] as $date) {
            $cutoff = $clock->cutoffForDate($date);
            $this->assertSame("{$date} 16:00:00", $cutoff->format('Y-m-d H:i:s'));
            $this->assertTrue($clock->isWorkingDate($date));

            CarbonImmutable::setTestNow($cutoff->subMinute());
            $this->assertFalse($clock->isWindowClosed());
            $this->assertFalse($clock->isLocked($cutoff));

            CarbonImmutable::setTestNow($cutoff);
            $this->assertTrue($clock->isWindowClosed());
            $this->assertTrue($clock->isLocked($cutoff));

            CarbonImmutable::setTestNow($cutoff->addMinute());
            $this->assertTrue($clock->isWindowClosed());
        }
    }

    public function test_sunday_is_not_a_working_day_and_has_no_reporting_window(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $clock = app(ReportClock::class);

        $this->assertSame('2026-10-11', $clock->businessDate());
        $this->assertFalse($clock->isWorkingDate('2026-10-11'));
        $this->assertTrue($clock->isWindowClosed());
    }

    public function test_business_date_and_working_day_ranges_use_ho_chi_minh_timezone(): void
    {
        CarbonImmutable::setTestNow('2026-10-04 17:30:00 UTC');
        $clock = app(ReportClock::class);

        $this->assertSame('2026-10-05', $clock->businessDate());
        $this->assertCount(6, $clock->workingDatesBetween('2026-10-04', '2026-10-10'));
        $this->assertCount(26, $clock->workingDatesBetween('2026-09-11', '2026-10-10'));
        $this->assertNotContains('2026-10-04', $clock->workingDatesBetween('2026-10-04', '2026-10-10'));
    }
}

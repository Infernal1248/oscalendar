<?php

namespace Tests\Unit;

use App\Models\RosterItem;
use App\Services\ParserTaskScheduler;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ParserTaskSchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flight_refresh_intervals_follow_distance_to_flight(): void
    {
        $now = Carbon::parse('2026-08-07 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $scheduler = new ParserTaskScheduler();

        $cases = [
            [12, 15, 100],
            [48, 60, 80],
            [84, 180, 60],
            [120, 360, 40],
            [192, 720, 20],
        ];

        foreach ($cases as [$hoursUntilStart, $expectedMinutes, $expectedPriority]) {
            $item = new RosterItem([
                'starts_at' => $now->copy()->addHours($hoursUntilStart),
                'ends_at' => $now->copy()->addHours($hoursUntilStart + 2),
            ]);
            [$priority, $nextRunAt] = $scheduler->flightSchedule($item, $now);

            $this->assertSame($expectedPriority, $priority);
            $this->assertEquals($expectedMinutes, $now->diffInMinutes($nextRunAt));
        }
    }

    public function test_finished_flight_refreshes_hourly_for_one_day_then_completes(): void
    {
        $now = Carbon::parse('2026-08-07 12:00:00', 'UTC');
        $scheduler = new ParserTaskScheduler();

        $recent = new RosterItem([
            'starts_at' => $now->copy()->subHours(14),
            'ends_at' => $now->copy()->subHours(12),
        ]);
        [$priority, $nextRunAt] = $scheduler->flightSchedule($recent, $now);
        $this->assertSame(90, $priority);
        $this->assertEquals(60, $now->diffInMinutes($nextRunAt));

        $old = new RosterItem([
            'starts_at' => $now->copy()->subHours(28),
            'ends_at' => $now->copy()->subHours(26),
        ]);
        [$priority, $nextRunAt] = $scheduler->flightSchedule($old, $now);
        $this->assertSame(0, $priority);
        $this->assertNull($nextRunAt);
    }
}

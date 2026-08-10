<?php

namespace Tests\Feature;

use App\Models\CalendarFeed;
use App\Models\FlightSegment;
use App\Models\RosterItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow('2026-08-10 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_uses_direct_titles_and_replaces_flight_details_with_crew(): void
    {
        $user = User::query()->create(['display_name' => 'Calendar Test']);
        $feed = CalendarFeed::query()->create([
            'user_id' => $user->id,
            'token' => 'calendar-test-token',
            'include_crew' => false,
            'include_phones' => false,
        ]);

        RosterItem::query()->create([
            'user_id' => $user->id,
            'kind' => 'other',
            'title' => 'План мероприятий',
            'flight_numbers_raw' => 'ОФИС',
            'route_raw' => 'Шереметьево',
            'starts_at' => '2026-08-13 05:30:00',
            'ends_at' => '2026-08-13 07:30:00',
        ]);

        $rosterItem = RosterItem::query()->create([
            'user_id' => $user->id,
            'source_external_id' => '1631777',
            'kind' => 'flight_ring',
            'title' => 'По рейсам',
            'flight_numbers_raw' => 'ФВ6363',
            'route_raw' => 'ШЕРЕМЕТ - ТЮМЕНЬ',
            'starts_at' => '2026-08-12 08:15:00',
            'ends_at' => '2026-08-12 11:05:00',
        ]);

        $segment = FlightSegment::query()->create([
            'user_id' => $user->id,
            'roster_item_id' => $rosterItem->id,
            'source_para_id' => 'para-1',
            'flight_number' => 'ФВ6363',
            'route_raw' => 'ШЕРЕМЕТ - ТЮМЕНЬ',
            'aircraft_type' => 'СУ95',
            'board' => '89100',
            'purpose' => 'Р',
            'parking_minutes' => 60,
            'dep_stand' => '10',
            'arr_stand' => '20',
            'starts_at' => '2026-08-12 08:15:00',
            'ends_at' => '2026-08-12 11:05:00',
        ]);

        $segment->crewMembers()->create([
            'role' => 'КВС',
            'full_name' => 'Фраиндт Роман Александрович',
            'phones' => ['+79690290525', '+79999667434'],
        ]);
        $segment->crewMembers()->create([
            'role' => 'ВП',
            'full_name' => 'Елисеев Илья Викторович',
            'phones' => ['+79175733818'],
        ]);

        $content = $this->get('/api/calendar/'.$feed->token.'.ics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();

        $unfolded = str_replace("\r\n ", '', $content);

        $this->assertStringContainsString("SUMMARY:ОФИС\r\n", $unfolded);
        $this->assertStringContainsString("SUMMARY:ФВ6363 ШЕРЕМЕТ - ТЮМЕНЬ\r\n", $unfolded);
        $this->assertStringContainsString('DESCRIPTION:Экипаж:\\n1) КВС Фраиндт Роман Александрович\\n+79690290525\\n+79999667434\\n2) ВП Елисеев Илья Викторович\\n+79175733818', $unfolded);
        $this->assertStringNotContainsString('Рейс ФВ6363', $unfolded);
        $this->assertStringNotContainsString('Тип ВС:', $unfolded);
        $this->assertStringNotContainsString('Цель:', $unfolded);
        $this->assertStringNotContainsString('Стоянка:', $unfolded);
    }
}

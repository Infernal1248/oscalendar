<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    public function show(string $token): Response
    {
        $feed = CalendarFeed::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->firstOrFail();

        $feed->forceFill(['last_accessed_at' => now()])->save();

        return response($this->emptyCalendar(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);
    }

    private function emptyCalendar(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//oscalendar//Laravel//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'END:VCALENDAR',
            '',
        ]);
    }
}

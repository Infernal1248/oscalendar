<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\FlightSegment;
use App\Models\RosterItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    public function show(string $token): Response
    {
        $feed = CalendarFeed::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->firstOrFail();

        $calendar = $this->calendar($feed);

        $feed->forceFill([
            'last_accessed_at' => now(),
            'last_generated_at' => now(),
        ])->save();

        Log::info('Calendar feed generated', [
            'feed_id' => $feed->id,
            'user_id' => $feed->user_id,
            'events_count' => $calendar['events_count'],
        ]);

        return response($calendar['content'], 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="oscalendar.ics"',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    private function calendar(CalendarFeed $feed): array
    {
        $events = [];

        foreach ($this->flightSegments($feed) as $segment) {
            $events[] = $this->flightSegmentEvent($segment, $feed);
        }

        foreach ($this->rosterItems($feed) as $item) {
            $events[] = $this->rosterItemEvent($item);
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//oscalendar//Laravel//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:oscalendar',
            'X-WR-TIMEZONE:UTC',
        ];

        foreach ($events as $event) {
            $lines = array_merge($lines, $event);
        }

        $lines[] = 'END:VCALENDAR';
        $lines[] = '';

        return [
            'content' => implode("\r\n", array_map([$this, 'foldLine'], $lines)),
            'events_count' => count($events),
        ];
    }

    private function flightSegments(CalendarFeed $feed)
    {
        return FlightSegment::query()
            ->where('user_id', $feed->user_id)
            ->whereBetween('starts_at', [now()->subMonths(2), now()->addMonths(12)])
            ->with([
                'rosterItem',
                'crewMembers',
                'deferredItems',
            ])
            ->orderBy('starts_at')
            ->get();
    }

    private function rosterItems(CalendarFeed $feed)
    {
        return RosterItem::query()
            ->where('user_id', $feed->user_id)
            ->where('is_actual', true)
            ->where('is_removed_from_source', false)
            ->whereBetween('starts_at', [now()->subMonths(2), now()->addMonths(12)])
            ->whereDoesntHave('flightSegments')
            ->orderBy('starts_at')
            ->get();
    }

    private function flightSegmentEvent(FlightSegment $segment, CalendarFeed $feed): array
    {
        $summary = trim(implode(' ', array_filter([
            $segment->flight_number ? 'Рейс '.$segment->flight_number : 'Рейс',
            $segment->route_raw,
        ])));

        $description = array_filter([
            $segment->aircraft_type ? 'Тип ВС: '.$segment->aircraft_type : null,
            $segment->board ? 'Борт: '.$segment->board : null,
            $segment->purpose ? 'Цель: '.$segment->purpose : null,
            $segment->parking_minutes !== null ? 'Стоянка: '.$this->parking((int) $segment->parking_minutes) : null,
            $segment->dep_stand ? 'Стоянка вылета: '.$segment->dep_stand : null,
            $segment->arr_stand ? 'Стоянка прилёта: '.$segment->arr_stand : null,
            $segment->open_doc_url ? 'Задание: '.$segment->open_doc_url : null,
            $segment->download_doc_url ? 'Скачать задание: '.$segment->download_doc_url : null,
        ]);

        if ($feed->include_crew && $segment->crewMembers->isNotEmpty()) {
            $description[] = '';
            $description[] = 'Экипаж:';
            foreach ($segment->crewMembers as $crew) {
                $line = trim(($crew->role ? $crew->role.' ' : '').$crew->full_name);

                if ($feed->include_phones && $crew->phones) {
                    $line .= ' '.implode(', ', $crew->phones);
                }

                $description[] = $line;
            }
        }

        if ($feed->include_deferred && $segment->deferredItems->isNotEmpty()) {
            $description[] = '';
            $description[] = 'Неисправности:';
            foreach ($segment->deferredItems as $item) {
                $description[] = trim(($item->is_warning ? '!!! ' : '').($item->title ?: $item->group_name));
            }
        }

        return $this->event([
            'uid' => 'flight-segment-'.$segment->id.'@oscalendar',
            'summary' => $summary ?: 'Рейс',
            'description' => implode("\n", $description),
            'location' => $segment->route_raw,
            'starts_at' => $segment->starts_at,
            'ends_at' => $segment->ends_at ?: Carbon::parse($segment->starts_at)->addHours(2),
            'url' => $segment->open_doc_url ?: $segment->download_doc_url,
            'updated_at' => $segment->updated_at,
        ]);
    }

    private function rosterItemEvent(RosterItem $item): array
    {
        return $this->event([
            'uid' => 'roster-item-'.$item->id.'@oscalendar',
            'summary' => $item->title ?: $item->kind ?: 'План',
            'description' => implode("\n", array_filter([
                $item->flight_numbers_raw ? 'Рейс: '.$item->flight_numbers_raw : null,
                $item->route_raw ? 'Маршрут: '.$item->route_raw : null,
                $item->aircraft_type_raw ? 'Тип ВС: '.$item->aircraft_type_raw : null,
                $item->boards_raw ? 'Борт: '.$item->boards_raw : null,
            ])),
            'location' => $item->route_raw,
            'starts_at' => $item->starts_at,
            'ends_at' => $item->ends_at ?: Carbon::parse($item->starts_at)->addHours(2),
            'updated_at' => $item->updated_at,
        ]);
    }

    private function event(array $data): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$this->escape($data['uid']),
            'DTSTAMP:'.$this->icsDate(now()),
            'DTSTART:'.$this->icsDate($data['starts_at']),
            'DTEND:'.$this->icsDate($data['ends_at']),
            'SUMMARY:'.$this->escape($data['summary']),
        ];

        if (! empty($data['description'])) {
            $lines[] = 'DESCRIPTION:'.$this->escape($data['description']);
        }

        if (! empty($data['location'])) {
            $lines[] = 'LOCATION:'.$this->escape($data['location']);
        }

        if (! empty($data['url'])) {
            $lines[] = 'URL:'.$this->escape($data['url']);
        }

        if (! empty($data['updated_at'])) {
            $lines[] = 'LAST-MODIFIED:'.$this->icsDate($data['updated_at']);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function icsDate($value): string
    {
        return Carbon::parse($value)->utc()->format('Ymd\THis\Z');
    }

    private function escape(?string $value): string
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\n', $value);

        return $value;
    }

    private function foldLine(string $line): string
    {
        $result = '';

        while (mb_strlen($line) > 75) {
            $result .= mb_substr($line, 0, 75)."\r\n ";
            $line = mb_substr($line, 75);
        }

        return $result.$line;
    }

    private function parking(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' мин.';
        }

        return intdiv($minutes, 60).' ч. '.($minutes % 60).' мин.';
    }
}

<?php

namespace App\Services\Monitoring;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;

class ProcessMetrics
{
    private string $group;
    private int $status = 0;
    private int $errors = 0;
    private int $warnings = 0;
    private int $failures = 0;
    private bool $finished = false;

    public function __construct()
    {
        $this->group = app()->runningInConsole() ? 'cli:other' : 'http:other';
    }

    public function request(RequestHandled $event): void
    {
        // Fixed categories only: no URLs, query strings, user IDs or secrets.
        $request = $event->request;
        $this->group = match (true) {
            $request->is('api/internal/parser-jobs/claim') => 'http:claim',
            $request->is('api/internal/parser-nodes/heartbeat') => 'http:node-heartbeat',
            $request->is('api/internal/monitor/*') => 'http:monitor',
            $request->is('api/internal/*') => 'http:parser-results',
            $request->is('api/telegram/*') => 'http:telegram',
            default => 'http:other',
        };
        $this->status = $event->response->getStatusCode();
    }

    public function command(CommandFinished $event): void
    {
        $known = ['schedule:run', 'schedule:finish', 'monitor:check', 'telegram:notify-roster',
            'checks:notify', 'push:send', 'flight-documents:prune'];
        // The outer command finishes last; nested calls are not counted twice.
        $this->group = 'cli:'.(in_array($event->command, $known, true) ? $event->command : 'other');
        if ($event->exitCode !== 0) $this->failures++;
    }

    public function log(MessageLogged $event): void
    {
        if (in_array($event->level, ['emergency', 'alert', 'critical', 'error'], true)) $this->errors++;
        if ($event->level === 'warning') $this->warnings++;
    }

    public static function cpu(?array $usage): ?float
    {
        if (! $usage) return null;
        return ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0)
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
    }

    public function finish(): void
    {
        if ($this->finished) return;
        $this->finished = true;
        try {
            $before = self::cpu(defined('OSCALENDAR_CPU_START') ? OSCALENDAR_CPU_START : null);
            $after = self::cpu(function_exists('getrusage') ? (getrusage() ?: null) : null);
            $fatal = error_get_last();
            $fatal = $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            app(MetricStore::class)->write([
                'group' => $this->group, 'cpu' => $before !== null && $after !== null ? max(0, $after - $before) : null,
                'wall' => max(0, microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true))),
                'memory' => memory_get_peak_usage(true),
                'http_5xx' => $this->status >= 500 ? 1 : 0,
                'http_4xx' => $this->status >= 400 && $this->status < 500 ? 1 : 0,
                'errors' => $this->errors + (int) $fatal, 'warnings' => $this->warnings, 'failed' => $this->failures,
            ]);
        } catch (\Throwable $exception) {
            // Telemetry is best effort and never changes an application's outcome.
        }
    }
}

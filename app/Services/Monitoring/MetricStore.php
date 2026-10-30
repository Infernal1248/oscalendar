<?php

namespace App\Services\Monitoring;

use Carbon\CarbonInterface;

/** Small, private minute buckets: no database query on the HTTP hot path. */
class MetricStore
{
    public function write(array $measurement): bool
    {
        if (! config('monitor.metrics_enabled')) return false;
        $directory = config('monitor.metrics_path');
        $handle = null;
        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) return false;
            $file = $directory.'/'.now()->utc()->format('YmdHi').'.json';
            $handle = @fopen($file, 'c+');
            // Monitoring must never hold up application work waiting for a lock.
            if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) return false;
            $contents = stream_get_contents($handle);
            $bucket = $contents ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR) : [];
            $group = $measurement['group'];
            $row = $bucket[$group] ?? ['count' => 0, 'cpu' => 0, 'cpu_samples' => 0, 'wall' => 0,
                'wall_max' => 0, 'memory_max' => 0, 'http_5xx' => 0, 'http_4xx' => 0,
                'errors' => 0, 'warnings' => 0, 'failed' => 0];
            $row['count']++;
            if ($measurement['cpu'] !== null) {
                $row['cpu'] += max(0, $measurement['cpu']);
                $row['cpu_samples']++;
            }
            $row['wall'] += $measurement['wall'];
            $row['wall_max'] = max($row['wall_max'], $measurement['wall']);
            $row['memory_max'] = max($row['memory_max'], $measurement['memory']);
            foreach (['http_5xx', 'http_4xx', 'errors', 'warnings', 'failed'] as $counter) {
                $row[$counter] += $measurement[$counter] ?? 0;
            }
            $status = (int) ($measurement['http_status'] ?? 0);
            foreach (($measurement['warning_categories'] ?? []) as $category => $count) {
                if (! array_key_exists($category, WarningCategories::labels()) || ! is_int($count) || $count <= 0) continue;
                $key = 'warning:'.$category;
                $row[$key] = ($row[$key] ?? 0) + $count;
            }
            if ($status >= 400 && $status < 500 && ($measurement['http_4xx'] ?? 0)) {
                $addressGroup = $measurement['http_address_group'] ?? 'other';
                if (! is_string($addressGroup) || ! array_key_exists($addressGroup, HttpAddressGroups::labels())) $addressGroup = 'other';
                // Flat numeric counters remain compatible with old minute buckets.
                $key = 'http_4xx:'.$status.':'.$addressGroup;
                $row[$key] = ($row[$key] ?? 0) + 1;
            }
            $bucket[$group] = $row;
            $json = json_encode($bucket, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (! ftruncate($handle, 0)) return false;
            return fwrite($handle, $json) === strlen($json);
        } catch (\Throwable $exception) {
            // Disk/permission failures must not break requests or log recursively.
            return false;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
    }

    public function read(CarbonInterface $from, CarbonInterface $until): array
    {
        $groups = [];
        $buckets = 0;
        $unreadable = 0;
        $blocks = [];
        // Bound reads to the requested window; never scan logs or all storage.
        for ($minute = $from->copy()->utc()->startOfMinute(); $minute->lt($until); $minute->addMinute()) {
            $file = config('monitor.metrics_path').'/'.$minute->format('YmdHi').'.json';
            if (! is_file($file)) continue;
            $handle = @fopen($file, 'r');
            if (! $handle) { $unreadable++; continue; }
            try {
                if (! flock($handle, LOCK_SH | LOCK_NB)) { $unreadable++; continue; }
                $bucket = json_decode(stream_get_contents($handle), true, 32, JSON_THROW_ON_ERROR);
                foreach ($bucket as $name => $row) {
                    $block = (int) floor(($minute->timestamp - $from->timestamp) / 7200);
                    $blocks[$block]['cpu'] = ($blocks[$block]['cpu'] ?? 0) + $row['cpu'];
                    $blocks[$block]['count'] = ($blocks[$block]['count'] ?? 0) + $row['count'];
                    foreach ($row as $key => $value) {
                        $groups[$name][$key] = str_ends_with($key, '_max')
                            ? max($groups[$name][$key] ?? 0, $value)
                            : ($groups[$name][$key] ?? 0) + $value;
                    }
                }
                $buckets++;
            } catch (\Throwable $exception) {
                $unreadable++;
            } finally {
                fclose($handle);
            }
        }
        return compact('groups', 'buckets', 'unreadable', 'blocks');
    }

    public function prune(): void
    {
        $cutoff = now()->utc()->subDays(config('monitor.retention_days'))->format('YmdHi');
        foreach (glob(config('monitor.metrics_path').'/*.json') ?: [] as $file) {
            if (basename($file, '.json') < $cutoff) @unlink($file);
        }
    }
}

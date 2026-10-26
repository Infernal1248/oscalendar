<?php
namespace App\Services;

class FlightReportDutyDuration
{
    public function normalize(mixed $value): ?string
    {
        // Timedeltas are exported as seconds by the XLSX parser.
        if (is_int($value) || is_float($value)) {
            if ($value < 0 || ! is_finite((float) $value)) return null;
            $minutes = (int) round($value / 60);
            return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
        }
        if (! is_string($value) || ! preg_match('/^(\d+):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $match)) return null;
        return ((int) $match[1]).':'.$match[2];
    }

    public function extract(array $data): ?string
    {
        if (($duration = $this->normalize($data['duty_duration'] ?? null)) !== null) return $duration;
        foreach ($data['sheets'] ?? [] as $sheet) {
            $cells = $sheet['cells'] ?? [];
            foreach ($cells as $coordinate => $cell) {
                $label = mb_strtolower(str_replace('ё', 'е', trim((string) ($cell['value'] ?? ''))));
                if ($label !== 'продолжительность полетной смены'
                    || ! preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $position)) continue;
                $values = [];
                foreach ($cells as $key => $candidate) {
                    if (! preg_match('/^([A-Z]+)(\d+)$/', $key, $at) || $at[2] !== $position[2]) continue;
                    $column = $this->column($at[1]);
                    if ($column <= $this->column($position[1])) continue;
                    $value = $candidate['value'] ?? null;
                    // Numeric raw cells are durations only when their format says so.
                    if (is_numeric($value) && ! preg_match('/h/i', $candidate['format'] ?? '')) continue;
                    if (($duration = $this->normalize($value)) !== null) $values[$column] = $duration;
                }
                if ($values) { ksort($values); return reset($values); }
            }
        }
        return null;
    }

    private function column(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) $number = $number * 26 + ord($letter) - 64;
        return $number;
    }
}

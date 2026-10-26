<?php
namespace App\Services;

class FlightReportDelayReasons
{
    public function extract(array $data): array
    {
        foreach ($data['sheets'] ?? [] as $sheet) {
            $rows = [];
            foreach ($sheet['cells'] ?? [] as $coordinate => $cell) {
                $value = $cell['value'] ?? null;
                if (! is_scalar($value) || ($cell['type'] ?? '') === 'f'
                    || ! preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $position)) continue;
                $column = 0;
                foreach (str_split($position[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                $rows[(int) $position[2]][$column] = trim((string) $value);
            }
            ksort($rows);
            $section = null;
            $codeColumn = $nameColumn = null;
            $reasons = [];
            foreach ($rows as $number => $values) {
                if ($section === null) {
                    foreach ($values as $value) {
                        if ($this->label($value) === 'причины задержки') $section = $number;
                    }
                    continue;
                }
                if ($codeColumn === null || $nameColumn === null) {
                    if ($number > $section + 5) break;
                    $headers = [];
                    foreach ($values as $column => $value) $headers[$this->label($value)] = $column;
                    $codeColumn = $headers['код'] ?? null;
                    $nameColumn = $headers['наименование'] ?? null;
                    continue;
                }
                $code = $values[$codeColumn] ?? '';
                $description = $values[$nameColumn] ?? '';
                // A new merged section heading has no separate description cell.
                if ($code === '' || $description === '') break;
                $reasons[] = ['code'=>$code, 'description'=>$description];
            }
            if ($section !== null) return $reasons;
        }
        return [];
    }

    private function label(string $value): string
    {
        return preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($value)));
    }
}

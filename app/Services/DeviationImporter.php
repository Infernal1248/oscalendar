<?php

namespace App\Services;

use App\Models\Deviation;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

class DeviationImporter
{
    public function import(UploadedFile $file, int $userId): array
    {
        $records = $this->read($file->getPathname());
        $now = now();
        $inserted = DB::transaction(function () use ($records, $file, $userId, $now) {
            $inserted = 0;
            foreach (array_chunk($records, 100) as $chunk) {
                $rows = array_map(fn ($record) => $record + [
                    'uploaded_by' => $userId,
                    'source_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk);
                $inserted += DB::table('deviations')->insertOrIgnore($rows);
            }

            return $inserted;
        });

        return ['processed' => count($records), 'inserted' => $inserted, 'duplicates' => count($records) - $inserted];
    }

    public function read(string $path): array
    {
        $book = null;
        try {
            $reader = IOFactory::createReader(IOFactory::identify($path, [IOFactory::READER_XLS, IOFactory::READER_XLSX]));
            $info = $reader->listWorksheetInfo($path);
            if (array_sum(array_column($info, 'totalRows')) > 50000
                || max(array_column($info, 'totalColumns')) > 32) {
                $this->fail('Слишком большая таблица: максимум 50 000 строк и 32 колонки.');
            }
            $reader->setReadDataOnly(true)->setReadEmptyCells(false);
            $book = $reader->load($path);
            $records = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                $mapping = null;
                $group = null;
                $lastColumn = $sheet->getHighestDataColumn();
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCellIterator('A', $lastColumn) as $cell) {
                        if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR) {
                            $this->fail('Формулы и ошибки Excel не поддерживаются, загрузите значения ячеек.');
                        }
                        $values[] = $this->text($cell->getValue());
                    }
                    if (! array_filter($values, fn ($value) => $value !== null)) {
                        continue;
                    }
                    if ($mapping === null) {
                        $mapping = [];
                        foreach (Deviation::COLUMNS as $key => $label) {
                            $index = array_search($label, $values, true);
                            if ($index === false) {
                                $this->fail("Лист {$sheet->getTitle()}: не найдена колонка «{$label}».");
                            }
                            $mapping[$key] = $index;
                        }
                        continue;
                    }
                    $data = array_map(fn ($index) => $values[$index] ?? null, $mapping);
                    if ($data['event_number'] === Deviation::COLUMNS['event_number']) {
                        continue;
                    }
                    if ($data['event_number'] !== null) {
                        $group = array_intersect_key($data, array_flip(['event_number', 'event_text', 'report_event_count']));
                        if (! array_filter(array_slice($data, 3), fn ($value) => $value !== null)) {
                            continue;
                        }
                    } else {
                        $data = array_replace($data, $group ?? []);
                    }
                    $data['flight_date'] = $this->date($data['flight_date'], $book->getExcelCalendar());
                    // Counts describe the export period, not the identity of an individual event.
                    $data['parameter_value'] = $this->decimal($data['parameter_value']);
                    $rules = [];
                    foreach (Deviation::COLUMNS as $key => $label) {
                        $max = in_array($key, ['event_text', 'parameter'], true) ? 1000
                            : (in_array($key, ['event_number', 'level', 'aircraft_type', 'aircraft_registration', 'flight_number', 'captain_code', 'pilot_personnel_number'], true) ? 64 : 255);
                        $required = in_array($key, ['event_number', 'event_text', 'level', 'flight_date', 'aircraft_type', 'aircraft_registration', 'flight_number'], true);
                        $rules[$key] = [$required ? 'required' : 'nullable', 'string', "max:{$max}"];
                    }
                    $rules['flight_date'] = ['required', 'date_format:Y-m-d'];
                    $rules['report_event_count'] = ['nullable', 'integer', 'min:0', 'max:4294967295'];
                    $validator = Validator::make($data, $rules);
                    if ($validator->fails()) {
                        $this->fail("Лист {$sheet->getTitle()}, строка {$row->getRowIndex()}: ".$validator->errors()->first());
                    }
                    $identity = $data;
                    unset($identity['report_event_count']);
                    // ponytail: identical rows without an occurrence ID cannot be distinguished; use that ID if AirFASE adds it.
                    $data['fingerprint'] = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $records[] = $data;
                }
            }
            if (! $records) {
                $this->fail('В файле не найдены отклонения.');
            }

            return $records;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Не удалось прочитать файл. Загрузите корректный отчёт AirFASE в формате XLS или XLSX.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function decimal(?string $value): ?string
    {
        if ($value !== null && preg_match('/^([+-]?\d+)(?:[.,](\d+))?$/D', $value, $matches)) {
            $integer = ltrim($matches[1], '+');
            $fraction = rtrim($matches[2] ?? '', '0');

            return $integer.($fraction === '' ? '' : '.'.$fraction);
        }

        return $value;
    }

    private function date(?string $value, int $calendar): ?string
    {
        if ($value !== null && is_numeric($value) && (float) $value > 0 && (float) $value < 2958466) {
            $previous = Date::getExcelCalendar();
            Date::setExcelCalendar($calendar);
            try {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } finally {
                Date::setExcelCalendar($previous);
            }
        }
        foreach (['Y-m-d', 'd.m.Y', 'd-m-Y', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value ?? '');
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\AirportDirectory;
use App\Services\MeteoReports;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MeteoController extends Controller
{
    public function __construct(
        private AirportDirectory $airports,
        private MeteoReports $meteo,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $saved = $this->savedRequest($request)->first();

        return $this->snapshotResponse(['request' => $saved === null ? null : $this->snapshot($saved)]);
    }

    public function store(Request $request): JsonResponse
    {
        $query = $this->savedRequest($request);
        $input = $request->validate(['airports' => ['required', 'string', 'max:400']])['airports'];

        return $this->fetchAndSave($query, $request->user()->id, $input);
    }

    public function refresh(Request $request): JsonResponse
    {
        $query = $this->savedRequest($request);
        $saved = $query->first();
        abort_if($saved === null, 404, 'Сохранённого запроса погоды пока нет.');

        return $this->fetchAndSave($query, $request->user()->id, $saved->airports, $saved);
    }

    private function savedRequest(Request $request): Builder
    {
        abort_unless($request->user()->status === 'active', 403);

        return DB::table('meteo_last_requests')->where('user_id', $request->user()->id);
    }

    private function snapshot(object $saved): array
    {
        return [
            'airports' => $saved->airports,
            'results' => json_decode($saved->results, true, 512, JSON_THROW_ON_ERROR),
            'received_at' => Carbon::parse($saved->received_at, 'UTC')->toIso8601String(),
        ];
    }

    private function snapshotResponse(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function fetchAndSave(Builder $query, int $userId, string $airports, ?object $previous = null): JsonResponse
    {
        $results = $this->fetchReports($airports);
        $receivedAt = now('UTC');
        $saved = in_array('ok', array_column($results, 'status'), true);

        if ($saved) {
            $values = [
                'results' => json_encode($results, JSON_THROW_ON_ERROR),
                'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
            ];
            if ($previous === null) {
                $query->upsert(['user_id' => $userId, 'airports' => $airports] + $values, ['user_id']);
            } else {
                // A refresh in progress must not overwrite a request saved on another device.
                $updated = (clone $query)->where('received_at', $previous->received_at)
                    ->where('airports', $previous->airports)->update($values);
                if ($updated === 0) {
                    $current = $query->first();
                    abort_if($current === null, 404, 'Сохранённого запроса погоды пока нет.');

                    return $this->snapshotResponse($this->snapshot($current) + ['saved' => true]);
                }
            }
        }

        return $this->snapshotResponse([
            'airports' => $airports,
            'received_at' => $receivedAt->toIso8601String(),
            'results' => $results,
            'saved' => $saved,
        ]);
    }

    private function fetchReports(string $input): array
    {
        $names = $this->airports->splitInput($input);
        if ($names === [] || count($names) > AirportDirectory::MAX_QUERY_AIRPORTS) {
            throw ValidationException::withMessages(['airports' => 'Укажите от 1 до 10 аэропортов.']);
        }

        $results = [];
        $codes = [];
        foreach ($names as $name) {
            $airport = $this->airports->find($name);
            $icao = $airport['icao'] ?? null;
            if ($icao !== null) {
                if (isset($codes[$icao])) {
                    continue;
                }
                $codes[$icao] = true;
            }
            $results[] = [
                'name' => $airport['name'] ?? $name,
                'icao' => $icao,
                'iata' => $airport['iata'] ?? null,
                'report' => null,
                'status' => match (true) {
                    $airport === null => 'unknown_airport',
                    $icao === null => 'missing_icao',
                    default => 'unavailable',
                },
            ];
        }

        if ($codes !== []) {
            try {
                $reports = $this->meteo->fetch(array_keys($codes));
            } catch (RuntimeException $exception) {
                abort(503, 'Метеосервис временно недоступен. Попробуйте ещё раз.');
            }

            foreach ($results as $index => $result) {
                $icao = $result['icao'];
                if ($icao !== null && isset($reports[$icao])) {
                    $results[$index]['report'] = $reports[$icao];
                    $results[$index]['status'] = 'ok';
                }
            }
        }

        return $results;
    }
}

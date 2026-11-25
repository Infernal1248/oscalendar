<?php

namespace App\Http\Controllers;

use App\Services\AirportDirectory;
use App\Services\MeteoReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MeteoController extends Controller
{
    public function __invoke(Request $request, AirportDirectory $airports, MeteoReports $meteo): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);

        $input = $request->validate(['airports' => ['required', 'string', 'max:400']]);
        $names = $airports->splitInput($input['airports']);
        if ($names === [] || count($names) > AirportDirectory::MAX_QUERY_AIRPORTS) {
            throw ValidationException::withMessages(['airports' => 'Укажите от 1 до 10 аэропортов.']);
        }

        $results = [];
        $codes = [];
        foreach ($names as $name) {
            $airport = $airports->find($name);
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
                $reports = $meteo->fetch(array_keys($codes));
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

        return response()->json([
            'received_at' => now('UTC')->toIso8601String(),
            'results' => $results,
        ])->header('Cache-Control', 'private, no-store');
    }
}

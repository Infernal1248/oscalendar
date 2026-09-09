<?php

namespace App\Http\Controllers;

use App\Models\Deviation;
use App\Services\DeviationImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->permit($request, 'deviations.read');
        $columns = array_keys(Deviation::COLUMNS);
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,500'],
            'sort_by' => ['sometimes', Rule::in($columns)],
            'sort_order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'group_by' => ['nullable', Rule::in($columns)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'filters' => ['sometimes', 'array:'.implode(',', $columns)],
        ];
        foreach ($columns as $column) {
            $rules['filters.'.$column] = ['nullable', 'string', 'max:255'];
        }
        $rules['filters.report_event_count'] = ['nullable', 'integer', 'min:0'];
        $rules['filters.flight_date'] = ['nullable', 'date_format:Y-m-d'];
        $data = $request->validate($rules);
        $query = Deviation::query();
        foreach ($data['filters'] ?? [] as $column => $value) {
            if ($value !== null && $value !== '') {
                if (in_array($column, ['flight_date', 'report_event_count'], true)) {
                    $query->where($column, $value);
                } else {
                    // Escape LIKE wildcards with a portable, explicit escape character.
                    $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
                    $query->whereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
            }
        }
        if (! empty($data['date_from'])) {
            $query->where('flight_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->where('flight_date', '<=', $data['date_to']);
        }
        $sort = $data['sort_by'] ?? 'flight_date';
        $direction = $data['sort_order'] ?? 'desc';
        if (! empty($data['group_by']) && $data['group_by'] !== $sort) {
            $query->orderBy($data['group_by']);
        }
        $page = $query->orderBy($sort, $direction)->orderBy('id')->paginate($data['per_page'] ?? 50);

        return response()->json($page);
    }

    public function import(Request $request, DeviationImporter $importer): JsonResponse
    {
        $this->permit($request, 'deviations.import');
        $request->validate(['file' => ['required', 'file', 'extensions:xls,xlsx', 'max:10240']]);

        return response()->json($importer->import($request->file('file'), $request->user()->id));
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->status === 'active'
            && $request->user()->hasPermission('deviations.view')
            && $request->user()->hasPermission($permission), 403);
    }
}

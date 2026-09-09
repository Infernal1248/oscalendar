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
            'filter_rules' => ['sometimes', 'array:'.implode(',', $columns)],
            'filter_rules.*' => ['required', 'array:operator,constraints'],
            'filter_rules.*.operator' => ['required', Rule::in(['and', 'or'])],
            'filter_rules.*.constraints' => ['required', 'array', 'min:1', 'max:5'],
            'filter_rules.*.constraints.*' => ['required', 'array:matchMode,value'],
        ];
        foreach ($columns as $column) {
            $rules['filters.'.$column] = ['nullable', 'string', 'max:255'];
            $comparable = in_array($column, ['flight_date', 'report_event_count'], true);
            $rules['filter_rules.'.$column.'.constraints.*.matchMode'] = ['required', Rule::in($comparable
                ? ['equals', 'notEquals', 'lt', 'lte', 'gt', 'gte']
                : ['contains', 'notContains', 'startsWith', 'endsWith', 'equals', 'notEquals'])];
            $rules['filter_rules.'.$column.'.constraints.*.value'] = match ($column) {
                'flight_date' => ['required', 'date_format:Y-m-d'],
                'report_event_count' => ['required', 'integer', 'min:0'],
                default => ['required', 'string', 'max:255'],
            };
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
        // AND between columns; each column groups its own AND/OR rules.
        foreach ($data['filter_rules'] ?? [] as $column => $filter) {
            $query->where(function ($group) use ($column, $filter) {
                foreach ($filter['constraints'] as $constraint) {
                    $mode = $constraint['matchMode'];
                    $value = $constraint['value'];
                    $operators = ['equals' => '=', 'notEquals' => '!=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
                    if (isset($operators[$mode])) {
                        $group->where($column, $operators[$mode], $value, $filter['operator']);
                    } else {
                        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
                        $pattern = ($mode === 'startsWith' ? '' : '%').$escaped.($mode === 'endsWith' ? '' : '%');
                        $operator = $mode === 'notContains' ? 'NOT LIKE' : 'LIKE';
                        $group->whereRaw("{$column} {$operator} ? ESCAPE '!'", [$pattern], $filter['operator']);
                    }
                }
            });
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

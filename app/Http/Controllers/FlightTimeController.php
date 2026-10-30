<?php

namespace App\Http\Controllers;

use App\Models\FlightTimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FlightTimeController extends Controller
{
    public function metadata(Request $request)
    {
        abort_unless($request->user()->hasFlightTimeAccess(), 403);
        return response()->json(['columns' => collect(FlightTimeEntry::COLUMNS)->map(function ($label, $field) {
            $group = match (true) {
                str_starts_with($field, 'actual_') => 'Учтённое',
                str_starts_with($field, 'planned_'), $field === 'flight_assignment_number' => 'План',
                str_starts_with($field, 'forecast_') => 'Прогноз', default => null,
            };
            return ['field' => $field, 'label' => $label, 'group' => $group];
        })->values(), 'minute_fields' => FlightTimeEntry::MINUTES])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasFlightTimeAccess(), 403);
        $columns = array_keys(FlightTimeEntry::COLUMNS);
        $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,500'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort_by' => ['sometimes', Rule::in([...$columns, 'entry_month'])], 'sort_order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'group_by' => ['nullable', Rule::in([...$columns, 'entry_month'])],
            'filter_rules' => ['sometimes', 'array:'.implode(',', $columns)],
            'filter_rules.*' => ['required', 'array:operator,constraints'],
            'filter_rules.*.operator' => ['required', Rule::in(['and', 'or'])],
            'filter_rules.*.constraints' => ['required', 'array', 'min:1', 'max:5'],
            'filter_rules.*.constraints.*' => ['required', 'array:matchMode,value'],
        ];
        foreach ($columns as $column) {
            $minutes = in_array($column, FlightTimeEntry::MINUTES, true);
            $rules["filter_rules.{$column}.constraints.*.matchMode"] = ['required', Rule::in($minutes || $column === 'entry_date'
                ? ['equals', 'notEquals', 'lt', 'lte', 'gt', 'gte']
                : ['contains', 'notContains', 'startsWith', 'endsWith', 'equals', 'notEquals'])];
            $rules["filter_rules.{$column}.constraints.*.value"] = $column === 'entry_date' ? ['required', 'date_format:Y-m-d']
                : ($minutes ? ['required', 'regex:/\A[0-9]{1,7}(?::[0-5][0-9])?\z/'] : ['required', 'string', 'max:255']);
        }
        $data = $request->validate($rules);
        // Test access does not grant access to another employee's records.
        $query = FlightTimeEntry::where('user_id', $request->user()->id);
        if (! empty($data['date_from'])) $query->where('entry_date', '>=', $data['date_from']);
        if (! empty($data['date_to'])) $query->where('entry_date', '<=', $data['date_to']);
        foreach ($data['filter_rules'] ?? [] as $column => $filter) {
            $query->where(function ($q) use ($column, $filter) {
                foreach ($filter['constraints'] as $constraint) {
                    $value = $constraint['value'];
                    if (in_array($column, FlightTimeEntry::MINUTES, true)) {
                        $parts = explode(':', (string) $value);
                        $value = count($parts) === 2 ? (int) $parts[0] * 60 + (int) $parts[1] : (int) $parts[0];
                    }
                    $mode = $constraint['matchMode'];
                    $operators = ['equals' => '=', 'notEquals' => '!=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
                    if (isset($operators[$mode])) $q->where($column, $operators[$mode], $value, $filter['operator']);
                    else {
                        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
                        $pattern = ($mode === 'startsWith' ? '' : '%').$escaped.($mode === 'endsWith' ? '' : '%');
                        $operator = $mode === 'notContains' ? 'NOT LIKE' : 'LIKE';
                        $q->whereRaw("{$column} {$operator} ? ESCAPE '!'", [$pattern], $filter['operator']);
                    }
                }
            });
        }
        $sums = implode(', ', array_map(fn ($field) => "SUM({$field}) AS {$field}", FlightTimeEntry::MINUTES));
        $totals = (clone $query)->selectRaw($sums)->first()->only(FlightTimeEntry::MINUTES);
        $group = $data['group_by'] ?? null;
        $sort = $data['sort_by'] ?? 'entry_date';
        $ordered = (clone $query)->select(['id', 'user_id', ...$columns])->selectRaw('SUBSTR(entry_date, 1, 7) AS entry_month');
        if ($group && $group !== $sort) $ordered->orderBy($group);
        $page = $ordered->orderBy($sort, $data['sort_order'] ?? 'desc')->orderBy('row_position')->orderBy('id')
            ->paginate($data['per_page'] ?? 50);
        $groupTotals = [];
        if ($group && $page->count()) {
            $expression = $group === 'entry_month' ? 'SUBSTR(entry_date, 1, 7)' : $group;
            $values = collect($page->items())->map(fn ($entry) => $group === 'entry_date'
                ? $entry->entry_date->toDateString() : $entry->{$group})->unique()->values();
            $groups = (clone $query)->where(fn ($q) => $q->whereIn(DB::raw($expression), $values->filter(fn ($v) => $v !== null)->all())
                ->when($values->containsStrict(null), fn ($q) => $q->orWhereNull(DB::raw($expression))))
                ->selectRaw("{$expression} AS group_value, {$sums}")->groupByRaw($expression)->get();
            $groupTotals = $groups->map(fn ($row) => ['value' => $row->group_value, 'totals' => $row->only(FlightTimeEntry::MINUTES)])->all();
        }
        return response()->json(array_merge($page->toArray(), ['totals' => $totals, 'group_totals' => $groupTotals]))
            ->header('Cache-Control', 'private, no-store');
    }
}

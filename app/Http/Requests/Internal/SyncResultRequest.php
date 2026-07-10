<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

class SyncResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'source' => ['required', 'string', 'max:64'],
            'trigger' => ['nullable', 'string', 'max:32'],
            'parsed_at' => ['nullable', 'date'],
            'sync_run_id' => ['nullable', 'integer', 'exists:sync_runs,id'],

            'roster_items' => ['nullable', 'array'],
            'roster_items.*.source_external_id' => ['nullable', 'string', 'max:64'],
            'roster_items.*.source_request_raw' => ['nullable', 'string'],
            'roster_items.*.kind' => ['required', 'string', 'max:32'],
            'roster_items.*.title' => ['nullable', 'string', 'max:150'],
            'roster_items.*.aircraft_type_raw' => ['nullable', 'string', 'max:150'],
            'roster_items.*.flight_numbers_raw' => ['nullable', 'string', 'max:255'],
            'roster_items.*.boards_raw' => ['nullable', 'string', 'max:255'],
            'roster_items.*.route_raw' => ['nullable', 'string'],
            'roster_items.*.starts_at' => ['required', 'date'],
            'roster_items.*.ends_at' => ['nullable', 'date'],
            'roster_items.*.is_actual' => ['nullable', 'boolean'],
            'roster_items.*.is_removed_from_source' => ['nullable', 'boolean'],
            'roster_items.*.source_payload' => ['nullable', 'array'],

            'flight_segments' => ['nullable', 'array'],
            'flight_segments.*.roster_source_external_id' => ['nullable', 'string', 'max:64'],
            'flight_segments.*.source_para_id' => ['nullable', 'string', 'max:64'],
            'flight_segments.*.source_segment_id' => ['nullable', 'string', 'max:64'],
            'flight_segments.*.flight_number' => ['nullable', 'string', 'max:32'],
            'flight_segments.*.route_raw' => ['nullable', 'string', 'max:255'],
            'flight_segments.*.departure_name' => ['nullable', 'string', 'max:100'],
            'flight_segments.*.arrival_name' => ['nullable', 'string', 'max:100'],
            'flight_segments.*.aircraft_type' => ['nullable', 'string', 'max:64'],
            'flight_segments.*.board' => ['nullable', 'string', 'max:32'],
            'flight_segments.*.purpose' => ['nullable', 'string', 'max:16'],
            'flight_segments.*.starts_at' => ['required', 'date'],
            'flight_segments.*.ends_at' => ['nullable', 'date'],
            'flight_segments.*.parking_minutes' => ['nullable', 'integer', 'min:0'],
            'flight_segments.*.dep_stand' => ['nullable', 'string', 'max:32'],
            'flight_segments.*.arr_stand' => ['nullable', 'string', 'max:32'],
            'flight_segments.*.open_doc_url' => ['nullable', 'url'],
            'flight_segments.*.download_doc_url' => ['nullable', 'url'],
            'flight_segments.*.next_update_at' => ['nullable', 'date'],
            'flight_segments.*.source_payload' => ['nullable', 'array'],
            'flight_segments.*.crew' => ['nullable', 'array'],
            'flight_segments.*.crew.*.role' => ['nullable', 'string', 'max:50'],
            'flight_segments.*.crew.*.full_name' => ['required', 'string', 'max:180'],
            'flight_segments.*.crew.*.phones' => ['nullable', 'array'],
            'flight_segments.*.crew.*.phones.*' => ['string', 'max:50'],
            'flight_segments.*.deferred_items' => ['nullable', 'array'],
            'flight_segments.*.deferred_items.*.group_name' => ['nullable', 'string', 'max:150'],
            'flight_segments.*.deferred_items.*.title' => ['nullable', 'string'],
            'flight_segments.*.deferred_items.*.ata' => ['nullable', 'string', 'max:50'],
            'flight_segments.*.deferred_items.*.work_order' => ['nullable', 'string', 'max:100'],
            'flight_segments.*.deferred_items.*.due_at' => ['nullable', 'date'],
            'flight_segments.*.deferred_items.*.is_warning' => ['nullable', 'boolean'],
            'flight_segments.*.deferred_items.*.raw_data' => ['nullable', 'array'],
        ];
    }
}

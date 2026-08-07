<?php

namespace App\Http\Requests\Internal;

use Illuminate\Validation\Rule;

class PartialSyncResultRequest extends SyncResultRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sync_run_id' => ['required', 'integer', 'exists:sync_runs,id'],
            'trigger' => ['required', 'string', 'max:32'],
            'parsed_at' => ['required', 'date'],
            'chunk_kind' => ['required', 'string', Rule::in(['roster', 'flight_segments'])],
            'is_final' => ['required', 'boolean', Rule::in([false])],
            'roster_source_external_id' => ['present', 'nullable', 'string', 'max:64'],
            'roster_period' => ['nullable', 'date_format:Y-m'],
            'roster_items' => ['present', 'array'],
            'flight_segments' => ['present', 'array'],
        ]);
    }
}

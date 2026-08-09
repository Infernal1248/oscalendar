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
            'chunk_kind' => ['required', 'string', Rule::in(['roster', 'flight_segments', 'roster_acknowledgement'])],
            'is_final' => ['required', 'boolean', Rule::in([false])],
            'roster_source_external_id' => ['present', 'nullable', 'string', 'max:64'],
            'roster_period' => ['nullable', 'required_if:chunk_kind,roster_acknowledgement', 'date_format:Y-m'],
            'roster_change_state' => ['nullable', 'array'],
            'roster_change_state.requires_acknowledgement' => ['nullable', 'boolean'],
            'roster_change_state.is_confirmed' => ['nullable', 'boolean'],
            'roster_change_state.confirmation_text' => ['nullable', 'string', 'max:500'],
            'roster_change_event_id' => ['nullable', 'required_if:chunk_kind,roster_acknowledgement', 'integer', 'exists:roster_change_events,id'],
            'roster_change_hash' => ['nullable', 'required_if:chunk_kind,roster_acknowledgement', 'string', 'size:64'],
            'roster_items' => ['present', 'array'],
            'flight_segments' => ['present', 'array'],
        ]);
    }
}

<?php
namespace App\Services;

use App\Models\{ParserTask, PortalCredential, User};
use Illuminate\Support\Facades\DB;

class PilotCheckActions
{
    public function certificates(int $userId): array
    {
        $snapshot = DB::table('pilot_check_syncs')->where('user_id', $userId)->first();
        $rows = json_decode($snapshot?->certificates ?? '[]', true) ?: [];
        return ['rows'=>$rows, 'version'=>hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE)),
            'synced_at'=>$snapshot?->certificates_synced_at];
    }

    public function state(?ParserTask $task): string
    {
        if (! $task) return 'idle';
        if ($task->status === 'running') return 'running';
        if ($task->last_error_at && (! $task->last_success_at || $task->last_error_at->gte($task->last_success_at))) return 'failed';
        if ($task->status === 'scheduled' && ($task->payload['requested_at'] ?? null)) return 'queued';
        return $task->status === 'completed' ? 'completed' : 'idle';
    }

    public function queue(User $user, bool $confirm = false, ?string $version = null): ParserTask
    {
        abort_unless($user->hasReportsAccess(), 403, 'Требуется расширенная подписка.');
        abort_unless(PortalCredential::where('user_id', $user->id)->where('portal', 'rossiya_edu')->where('status', 'active')->exists(),
            422, 'Нет действующего подключения к OpenSky.');
        return DB::transaction(function () use ($user, $confirm, $version) {
            // Serialize user requests so duplicate clicks cannot create extra actions.
            User::lockForUpdate()->findOrFail($user->id);
            $type = $confirm ? 'checks_confirm_certificate' : 'checks_refresh';
            $certificates = $this->certificates($user->id);
            if ($confirm) {
                abort_unless($certificates['rows'] && hash_equals($certificates['version'], $version ?? ''),
                    409, 'Сведения о сертификате изменились. Обновите допуски и проверьте их перед подтверждением.');
            }
            $task = ParserTask::firstOrCreate(['task_key'=>"{$type}:rossiya_edu:user:{$user->id}"], [
                'user_id'=>$user->id, 'source'=>'rossiya_edu', 'portal'=>'rossiya_edu',
                'task_type'=>$type, 'status'=>'scheduled', 'priority'=>110, 'next_run_at'=>now(),
            ]);
            $task = ParserTask::lockForUpdate()->findOrFail($task->id);
            if ($task->status === 'running') return $task;
            if ($this->state($task) === 'queued') return $task;
            $payload = ['requested_at'=>now()->toIso8601String()];
            if ($confirm) {
                $payload['certificates'] = array_map(fn ($row) => array_intersect_key($row, array_flip([
                    'title', 'aircraft_type', 'valid_from', 'due_on', 'confirmation_path',
                ])), $certificates['rows']);
                $payload['approved_by'] = $user->id;
            }
            $task->update(['status'=>'scheduled', 'next_run_at'=>now(), 'priority'=>110,
                'last_error_at'=>null, 'last_error_text'=>null, 'payload'=>$payload]);
            return $task;
        });
    }
}

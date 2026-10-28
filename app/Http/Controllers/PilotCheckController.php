<?php
namespace App\Http\Controllers;

use App\Models\{PilotCheck, ParserTask};
use App\Services\PilotCheckActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PilotCheckController extends Controller
{
    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasReportsAccess(), 403, 'Требуется расширенная подписка.');
    }
    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        $user = $request->user();
        $refreshTask = ParserTask::where('task_key', "checks_refresh:rossiya_edu:user:{$user->id}")->first();
        $syncedAt = DB::table('pilot_check_syncs')->where('user_id', $user->id)->value('synced_at');
        return response()->json([
            'certificates' => app(PilotCheckActions::class)->certificates($user->id),
            'refresh_state' => app(PilotCheckActions::class)->state($refreshTask),
            'next_sync_at' => $refreshTask?->next_run_at?->toIso8601String(),
            'confirmation_state' => app(PilotCheckActions::class)->state(ParserTask::where('task_key', "checks_confirm_certificate:rossiya_edu:user:{$user->id}")->first()),
            'checks' => PilotCheck::where('user_id', $user->id)->where('is_current', true)->orderBy('due_on')->get()->filter(fn ($check) => $check->isVisibleCheck())->values(),
            'position' => $user->portalProfile?->position,
            'synced_at' => $syncedAt ? \Illuminate\Support\Carbon::parse($syncedAt, 'UTC')->toIso8601String() : null,
            'notifications_enabled' => (bool) $user->check_notifications_enabled,
            'timezone' => $user->timezone ?: 'UTC',
        ])->header('Cache-Control', 'private, no-store');
    }
    public function refresh(Request $request, PilotCheckActions $actions)
    {
        $task = $actions->queue($request->user());
        return response()->json(['status'=>$actions->state($task)], 202);
    }
    public function confirmCertificate(Request $request, PilotCheckActions $actions)
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['version'=>['required', 'string', 'size:64'], 'acknowledged'=>['required', 'accepted']]);
        $task = $actions->queue($request->user(), true, $data['version']);
        return response()->json(['status'=>$actions->state($task)], 202);
    }
    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before:2100-01-01']]);
    }
    public function store(Request $request)
    {
        $this->authorizeAccess($request);
        return response()->json(PilotCheck::create($this->validated($request) + [
            'user_id' => $request->user()->id, 'origin' => 'manual',
        ]), 201);
    }
    private function ownManual(Request $request, int $id): PilotCheck
    {
        $this->authorizeAccess($request);
        return PilotCheck::where('user_id', $request->user()->id)->where('origin', 'manual')->findOrFail($id);
    }
    public function update(Request $request, int $id)
    {
        $check = $this->ownManual($request, $id);
        $check->update($this->validated($request));
        return response()->json($check);
    }
    public function destroy(Request $request, int $id)
    {
        $this->ownManual($request, $id)->delete();
        return response()->json(['ok' => true]);
    }
    public function settings(Request $request)
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['notifications_enabled' => ['required', 'boolean']]);
        $request->user()->forceFill(['check_notifications_enabled' => $data['notifications_enabled']])->save();
        return response()->json(['ok' => true]);
    }
}

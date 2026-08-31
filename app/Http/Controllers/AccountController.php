<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\FlightSegment;
use App\Models\RosterChangeEvent;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1000'],
        ]);

        $localUser = User::query()->where('login', $credentials['login'])->first();

        if ($localUser) {
            if ($localUser->status === 'active'
                && $localUser->role === 'admin'
                && is_string($localUser->password)
                && Hash::check($credentials['password'], $localUser->password)) {
                return $this->authenticated($localUser);
            }

            return response()->json(['message' => 'Неверный логин или пароль.'], 422);
        }

        $portalCredential = PortalCredential::query()
            ->with('user')
            ->where('portal', 'rossiya_edu')
            ->where('login', $credentials['login'])
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $portalCredential
            || ! hash_equals(Crypt::decryptString($portalCredential->password_encrypted), $credentials['password'])) {
            return response()->json(['message' => 'Неверный логин или пароль.'], 422);
        }

        return $this->authenticated($portalCredential->user);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userData($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $request->user()->forceFill($data)->save();

        return response()->json($this->userData($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->permit($request, 'dashboard.view');
        $items = $this->actualRoster($request);

        return response()->json([
            'upcoming_count' => (clone $items)->where('starts_at', '>=', now())->count(),
            'flights_count' => (clone $items)->where('kind', 'flight_ring')->where('starts_at', '>=', now())->count(),
            'next_item' => (clone $items)->where('starts_at', '>=', now())->orderBy('starts_at')->first(),
        ]);
    }

    public function workplan(Request $request): JsonResponse
    {
        $this->permit($request, 'workplan.view');

        $items = $this->actualRoster($request)
            ->with(['flightSegments' => fn ($query) => $query
                ->select([
                    'id', 'roster_item_id', 'flight_number', 'route_raw', 'departure_name', 'arrival_name',
                    'aircraft_type', 'board', 'starts_at', 'ends_at',
                ])
                ->orderBy('starts_at')])
            ->whereBetween('starts_at', [now()->subMonths(2), now()->addMonths(12)])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (RosterItem $item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'title' => $item->title,
                'flight_numbers_raw' => $item->flight_numbers_raw,
                'route_raw' => $item->route_raw,
                'starts_at' => $item->starts_at,
                'ends_at' => $item->ends_at,
                'updated_at' => $item->updated_at,
                'segments' => $item->flightSegments->map(fn (FlightSegment $segment) => [
                    'id' => $segment->id,
                    'flight_number' => $segment->flight_number,
                    'route_raw' => $segment->route_raw,
                    'departure_name' => $segment->departure_name,
                    'arrival_name' => $segment->arrival_name,
                    'aircraft_type' => $segment->aircraft_type,
                    'board' => $segment->board,
                    'starts_at' => $segment->starts_at,
                    'ends_at' => $segment->ends_at,
                ]),
            ]);

        return response()->json($items);
    }

    public function changeHistory(Request $request): JsonResponse
    {
        $this->permit($request, 'history.view');

        return response()->json(RosterChangeEvent::query()
            ->where('user_id', $request->user()->id)
            ->select([
                'id', 'period', 'status', 'changes', 'created_at',
                'acknowledgement_requested_at', 'acknowledged_at', 'superseded_at',
            ])
            ->latest()
            ->get());
    }

    public function flight(Request $request, int $flightSegment): JsonResponse
    {
        $this->permit($request, 'workplan.view');

        $segment = FlightSegment::query()
            ->whereKey($flightSegment)
            ->where('user_id', $request->user()->id)
            ->withActualRosterItem()
            ->with(['crewMembers', 'deferredItems'])
            ->firstOrFail();

        return response()->json([
            'id' => $segment->id,
            'flight_number' => $segment->flight_number,
            'route_raw' => $segment->route_raw,
            'departure_name' => $segment->departure_name,
            'arrival_name' => $segment->arrival_name,
            'aircraft_type' => $segment->aircraft_type,
            'board' => $segment->board,
            'purpose' => $segment->purpose,
            'starts_at' => $segment->starts_at,
            'ends_at' => $segment->ends_at,
            'parking_minutes' => $segment->parking_minutes,
            'dep_stand' => $segment->dep_stand,
            'arr_stand' => $segment->arr_stand,
            'open_doc_url' => $segment->open_doc_url,
            'download_doc_url' => $segment->download_doc_url,
            'ofp_url' => $segment->ofp_pdf_path
                ? URL::signedRoute('flight.ofp', ['flightSegment' => $segment->id])
                : null,
            'crew' => $segment->crewMembers->map(fn ($member) => [
                'role' => $member->role,
                'full_name' => $member->full_name,
                'phones' => $member->phones ?? [],
                'personnel_number' => $member->personnel_number,
                'position' => $member->position,
                'qualification' => $member->qualification,
            ]),
            'deferred_items' => $segment->deferredItems->map(fn ($item) => [
                'group_name' => $item->group_name,
                'title' => $item->title,
                'ata' => $item->ata,
                'work_order' => $item->work_order,
                'issued_at' => $item->issued_at,
                'due_at' => $item->due_at,
                'mel' => $item->mel,
                'tah' => $item->tah,
                'tac' => $item->tac,
                'is_warning' => $item->is_warning,
            ]),
        ]);
    }

    private function actualRoster(Request $request)
    {
        return RosterItem::query()
            ->where('user_id', $request->user()->id)
            ->where('is_actual', true)
            ->where('is_removed_from_source', false);
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function userData(User $user): array
    {
        $permissions = $user->permissions ?? config('permissions.defaults');
        $calendarUrl = null;
        if ($user->role !== 'admin') {
            $feed = CalendarFeed::query()->firstOrCreate(
                ['user_id' => $user->id, 'is_active' => true],
                [
                    'token' => Str::random(80),
                    'name' => 'Personal',
                    'include_crew' => false,
                    'include_phones' => false,
                    'include_deferred' => false,
                ]
            );
            $calendarUrl = rtrim((string) config('app.url'), '/').'/api/calendar/'.$feed->token.'.ics';
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'timezone' => $user->timezone,
            'calendar_url' => $calendarUrl,
            'navigation' => $user->role === 'admin'
                ? ['profile', 'admin.users', 'admin.permissions']
                : array_values(array_filter([
                    'profile',
                    in_array('dashboard.view', $permissions, true) ? 'dashboard' : null,
                    in_array('workplan.view', $permissions, true) ? 'workplan' : null,
                    in_array('history.view', $permissions, true) ? 'history' : null,
                ])),
        ];
    }

    private function authenticated(User $user): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userData($user),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityMinute;
use App\Models\BreakSession;
use App\Models\WorkSession;
use App\Support\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'started_at' => ['required', 'date'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $startedAt = CarbonImmutable::parse($data['started_at'])->utc();

        if (! WorkSchedule::isWithinWindow($startedAt, $user)) {
            throw ValidationException::withMessages([
                'started_at' => 'Tracking can only start inside your office hours: '.WorkSchedule::describeWindows($user).' (Bangladesh time).',
            ]);
        }

        $session = DB::transaction(function () use ($data, $user, $startedAt) {
            // A desktop crash or a second device must not leave two live sessions.
            WorkSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['running', 'paused'])
                ->where('started_at', '<=', $startedAt)
                ->get()
                ->each(function (WorkSession $open) use ($startedAt): void {
                    $open->forceFill([
                        'status' => 'stopped',
                        'ended_at' => $startedAt,
                    ])->save();
                    $open->breaks()->whereNull('ended_at')->update([
                        'ended_at' => $startedAt,
                        'completed' => true,
                    ]);
                });

            $existing = WorkSession::query()->where('uuid', $data['uuid'])->first();
            if ($existing && $existing->user_id !== $user->id) {
                abort(403, 'This session belongs to another user.');
            }

            return WorkSession::query()->updateOrCreate(
                ['uuid' => $data['uuid'], 'user_id' => $user->id],
                [
                    'user_id' => $user->id,
                    'started_at' => $startedAt,
                    'ended_at' => null,
                    'status' => 'running',
                    'device_name' => $data['device_name'] ?? null,
                    'ip_address' => request()->ip(),
                ],
            );
        });

        return response()->json([
            'message' => 'Session started.',
            'session' => $this->sessionPayload($session->fresh()),
            'schedule' => WorkSchedule::apiPayload($user),
        ], 201);
    }

    public function sync(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:running,paused,stopped'],
            'activity' => ['nullable', 'array', 'max:3000'],
            'activity.*.minute_start' => ['required_with:activity', 'date'],
            'activity.*.keystrokes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'activity.*.clicks' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'activity.*.active_seconds' => ['nullable', 'integer', 'min:0', 'max:60'],
            'activity.*.idle_seconds' => ['nullable', 'integer', 'min:0', 'max:60'],
            'activity.*.is_idle' => ['nullable', 'boolean'],
            'breaks' => ['nullable', 'array', 'max:50'],
            'breaks.*.uuid' => ['required_with:breaks', 'uuid'],
            'breaks.*.type' => ['required_with:breaks', 'in:asor,maghrib,toilet'],
            'breaks.*.started_at' => ['required_with:breaks', 'date'],
            'breaks.*.ended_at' => ['nullable', 'date'],
            'breaks.*.duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'breaks.*.completed' => ['nullable', 'boolean'],
        ]);

        $session = $this->findSession($request, $uuid);

        DB::transaction(function () use ($session, $data, $request): void {
            foreach ($data['activity'] ?? [] as $row) {
                $minute = CarbonImmutable::parse($row['minute_start'])->utc()->startOfMinute();
                ActivityMinute::query()->updateOrCreate(
                    [
                        'work_session_id' => $session->id,
                        'minute_start' => $minute,
                    ],
                    [
                        'user_id' => $session->user_id,
                        'keystrokes' => (int) ($row['keystrokes'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'active_seconds' => min(60, (int) ($row['active_seconds'] ?? 0)),
                        'idle_seconds' => min(60, (int) ($row['idle_seconds'] ?? 0)),
                        'is_idle' => (bool) ($row['is_idle'] ?? false),
                    ],
                );
            }

            foreach ($data['breaks'] ?? [] as $row) {
                $startedAt = CarbonImmutable::parse($row['started_at'])->utc();
                $endedAt = ! empty($row['ended_at'])
                    ? CarbonImmutable::parse($row['ended_at'])->utc()
                    : null;
                $planned = (int) (WorkSchedule::breakMinutes($row['type'], $request->user()) * 60);
                $duration = min($planned, (int) ($row['duration_seconds'] ?? 0));

                $existingBreak = BreakSession::query()->where('uuid', $row['uuid'])->first();
                if ($existingBreak && $existingBreak->user_id !== $session->user_id) {
                    abort(403, 'This break belongs to another user.');
                }

                BreakSession::query()->updateOrCreate(
                    ['uuid' => $row['uuid'], 'user_id' => $session->user_id],
                    [
                        'work_session_id' => $session->id,
                        'user_id' => $session->user_id,
                        'type' => $row['type'],
                        'started_at' => $startedAt,
                        'ended_at' => $endedAt,
                        'duration_seconds' => $duration,
                        'completed' => (bool) ($row['completed'] ?? $endedAt !== null),
                    ],
                );
            }

            $this->recalculate($session);

            if (! empty($data['status']) && $session->status !== 'stopped') {
                $session->forceFill(['status' => $data['status']])->save();
            }
        });

        return response()->json([
            'message' => 'Session synced.',
            'session' => $this->sessionPayload($session->fresh()),
            'server_time' => WorkSchedule::now()->toIso8601String(),
        ]);
    }

    public function stop(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'ended_at' => ['required', 'date'],
        ]);

        $session = $this->findSession($request, $uuid);
        $endedAt = CarbonImmutable::parse($data['ended_at'])->utc();

        DB::transaction(function () use ($session, $endedAt): void {
            $session->breaks()
                ->whereNull('ended_at')
                ->get()
                ->each(function (BreakSession $break) use ($endedAt): void {
                    $planned = (int) (WorkSchedule::breakMinutes($break->type) * 60);
                    $duration = min($planned, max(0, (int) $break->started_at->diffInSeconds($endedAt)));
                    $break->forceFill([
                        'ended_at' => $endedAt,
                        'duration_seconds' => $duration,
                        'completed' => true,
                    ])->save();
                });

            $session->forceFill([
                'status' => 'stopped',
                'ended_at' => $endedAt,
            ])->save();

            $this->recalculate($session);
        });

        return response()->json([
            'message' => 'Session stopped.',
            'session' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $session = $request->user()
            ->workSessions()
            ->with(['activityMinutes', 'breaks'])
            ->whereIn('status', ['running', 'paused'])
            ->latest('started_at')
            ->first();

        return response()->json([
            'session' => $session ? $this->sessionPayload($session) : null,
            'schedule' => WorkSchedule::apiPayload($request->user()),
        ]);
    }

    private function findSession(Request $request, string $uuid): WorkSession
    {
        return WorkSession::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function recalculate(WorkSession $session): void
    {
        $active = (int) $session->activityMinutes()->sum('active_seconds');
        $idle = (int) $session->activityMinutes()->sum('idle_seconds');
        $breaks = (int) $session->breaks()->sum('duration_seconds');

        $session->forceFill([
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'break_seconds' => $breaks,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(WorkSession $session): array
    {
        return [
            'uuid' => $session->uuid,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'status' => $session->status,
            'active_seconds' => (int) ($session->active_seconds ?? 0),
            'idle_seconds' => (int) ($session->idle_seconds ?? 0),
            'break_seconds' => (int) ($session->break_seconds ?? 0),
        ];
    }
}

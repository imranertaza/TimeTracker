<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreakSession;
use App\Models\WorkSession;
use App\Support\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BreakController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'session_uuid' => ['required', 'uuid'],
            'type' => ['required', 'in:asor,maghrib,toilet'],
            'started_at' => ['required', 'date'],
        ]);

        $user = $request->user();
        $session = WorkSession::query()
            ->where('uuid', $data['session_uuid'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($session->status === 'stopped') {
            return response()->json(['message' => 'This work session has already stopped.'], 422);
        }

        $startedAt = CarbonImmutable::parse($data['started_at'])->utc();
        $planned = (int) (WorkSchedule::breakMinutes($data['type'], $user) * 60);

        $break = DB::transaction(function () use ($data, $session, $user, $startedAt): BreakSession {
            BreakSession::query()
                ->where('work_session_id', $session->id)
                ->whereNull('ended_at')
                ->get()
                ->each(function (BreakSession $open) use ($startedAt, $user): void {
                    $planned = (int) (WorkSchedule::breakMinutes($open->type, $user) * 60);
                    $open->forceFill([
                        'ended_at' => $startedAt,
                        'duration_seconds' => min($planned, max(0, (int) $open->started_at->diffInSeconds($startedAt))),
                        'completed' => true,
                    ])->save();
                });

            $session->forceFill(['status' => 'paused'])->save();

            $existing = BreakSession::query()->where('uuid', $data['uuid'])->first();
            if ($existing && $existing->user_id !== $user->id) {
                abort(403, 'This break belongs to another user.');
            }

            return BreakSession::query()->updateOrCreate(
                ['uuid' => $data['uuid'], 'user_id' => $user->id],
                [
                    'work_session_id' => $session->id,
                    'user_id' => $user->id,
                    'type' => $data['type'],
                    'started_at' => $startedAt,
                    'ended_at' => null,
                    'duration_seconds' => 0,
                    'completed' => false,
                ],
            );
        });

        return response()->json([
            'message' => 'Break started.',
            'break' => $this->breakPayload($break),
            'planned_seconds' => $planned,
            'schedule' => WorkSchedule::apiPayload($user),
        ], 201);
    }

    public function end(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'ended_at' => ['required', 'date'],
        ]);

        $break = BreakSession::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $endedAt = CarbonImmutable::parse($data['ended_at'])->utc();
        $planned = (int) (WorkSchedule::breakMinutes($break->type, $request->user()) * 60);

        DB::transaction(function () use ($break, $endedAt, $planned, $request): void {
            $actual = max(0, (int) $break->started_at->diffInSeconds($endedAt));
            $duration = min($planned, $actual);
            $overdue = $actual >= $planned;

            $break->forceFill([
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
                'completed' => true,
            ])->save();

            $session = $break->workSession;
            if ($session && $session->status !== 'stopped') {
                if ($overdue) {
                    // The staff member did not return within the allowance, so
                    // tracking stops automatically instead of resuming.
                    $session->forceFill([
                        'status' => 'stopped',
                        'ended_at' => $endedAt,
                    ])->save();
                } else {
                    $session->forceFill([
                        'status' => WorkSchedule::isWithinWindow($endedAt, $request->user()) ? 'running' : 'paused',
                    ])->save();
                }

                $session->forceFill([
                    'break_seconds' => (int) $session->breaks()->sum('duration_seconds'),
                ])->save();
            }
        });

        return response()->json([
            'message' => 'Break ended.',
            'break' => $this->breakPayload($break->fresh()),
            'schedule' => WorkSchedule::apiPayload($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function breakPayload(BreakSession $break): array
    {
        return [
            'uuid' => $break->uuid,
            'type' => $break->type,
            'label' => $break->label,
            'started_at' => $break->started_at?->toIso8601String(),
            'ended_at' => $break->ended_at?->toIso8601String(),
            'duration_seconds' => $break->duration_seconds,
            'completed' => $break->completed,
        ];
    }
}

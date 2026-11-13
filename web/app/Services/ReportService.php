<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkSession;
use App\Support\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ReportService
{
    public const TOTAL_MODES = [
        'active' => 'Exact working time only',
        'active_idle' => 'Exact working time + idle time',
        'active_namaz' => 'Exact working time + namaz break time',
        'active_idle_namaz' => 'Exact working time + idle time + namaz break time',
    ];

    /**
     * @return array<string, string>
     */
    public static function totalModes(): array
    {
        return self::TOTAL_MODES;
    }

    public static function normaliseMode(?string $mode): string
    {
        $mode = (string) $mode;

        return array_key_exists($mode, self::TOTAL_MODES) ? $mode : 'active';
    }

    public static function combinedTotal(int $activeSeconds, int $idleSeconds, int $namazSeconds, ?string $mode = 'active'): int
    {
        $mode = self::normaliseMode($mode);
        $total = $activeSeconds;

        if (str_contains($mode, 'idle')) {
            $total += $idleSeconds;
        }

        if (str_contains($mode, 'namaz')) {
            $total += $namazSeconds;
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function daily(User $user, CarbonInterface|string|null $date = null): array
    {
        $localDate = $this->localDate($date);
        [$startUtc, $endUtc] = $this->dayBounds($localDate);

        $sessions = $user->workSessions()
            ->withCount(['screenshots', 'breaks'])
            ->where('started_at', '>=', $startUtc)
            ->where('started_at', '<', $endUtc)
            ->orderBy('started_at')
            ->get();

        $sessionIds = $sessions->pluck('id');

        $activity = $user->activityMinutes()
            ->whereIn('work_session_id', $sessionIds)
            ->get();

        $breaks = $user->breakSessions()
            ->whereIn('work_session_id', $sessionIds)
            ->get();

        $activeSeconds = (int) $activity->sum('active_seconds');
        $idleSeconds = (int) $activity->sum('idle_seconds');
        $breakSeconds = (int) $breaks->sum('duration_seconds');

        $namaz = [
            'asor' => (int) $breaks->where('type', 'asor')->sum('duration_seconds'),
            'maghrib' => (int) $breaks->where('type', 'maghrib')->sum('duration_seconds'),
        ];
        $namaz['total'] = $namaz['asor'] + $namaz['maghrib'];
        $toiletSeconds = (int) $breaks->where('type', 'toilet')->sum('duration_seconds');

        $running = $sessions->first(fn (WorkSession $session) => $session->is_running);

        return [
            'user' => $user,
            'date' => $localDate,
            'sessions' => $sessions,
            'activity' => $activity,
            'breaks' => $breaks,
            'screenshots' => $sessions->sum('screenshots_count'),
            'active_seconds' => $activeSeconds,
            'idle_seconds' => $idleSeconds,
            'tracked_seconds' => $activeSeconds + $idleSeconds,
            'break_seconds' => $breakSeconds,
            'keystrokes' => (int) $activity->sum('keystrokes'),
            'clicks' => (int) $activity->sum('clicks'),
            'namaz' => $namaz,
            'toilet_seconds' => $toiletSeconds,
            'status' => $running ? $running->status : ($sessions->isEmpty() ? 'not_started' : 'stopped'),
            'last_seen_at' => $user->last_seen_at,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function teamDaily(CarbonInterface|string|null $date = null, bool $includeInactive = false): Collection
    {
        $date = $this->localDate($date);

        return User::query()
            ->where('role', 'staff')
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->daily($user, $date));
    }

    /**
     * @return array<string, int>
     */
    public function teamTotals(Collection $team): array
    {
        return [
            'staff' => $team->count(),
            'active_seconds' => (int) $team->sum('active_seconds'),
            'idle_seconds' => (int) $team->sum('idle_seconds'),
            'tracked_seconds' => (int) $team->sum('tracked_seconds'),
            'break_seconds' => (int) $team->sum('break_seconds'),
            'namaz_seconds' => (int) $team->sum(fn (array $row) => $row['namaz']['total']),
            'toilet_seconds' => (int) $team->sum('toilet_seconds'),
            'keystrokes' => (int) $team->sum('keystrokes'),
            'clicks' => (int) $team->sum('clicks'),
            'screenshots' => (int) $team->sum('screenshots'),
            'online' => (int) $team->filter(function (array $row) {
                return in_array($row['status'], ['running', 'paused'], true)
                    && $row['last_seen_at']
                    && $row['last_seen_at']->greaterThan(now()->subMinutes(3));
            })->count(),
        ];
    }

    /**
     * Monthly totals and a per-day breakdown for one staff member.
     *
     * @return array<string, mixed>
     */
    public function monthly(User $user, CarbonInterface|string|null $month = null, ?string $mode = 'active'): array
    {
        $mode = self::normaliseMode($mode);
        $monthStart = $this->localMonth($month);
        $nextMonth = $monthStart->addMonth();
        [$startUtc, $endUtc] = [$monthStart->utc(), $nextMonth->utc()];

        $sessions = $user->workSessions()
            ->withCount(['screenshots', 'breaks'])
            ->where('started_at', '>=', $startUtc)
            ->where('started_at', '<', $endUtc)
            ->orderBy('started_at')
            ->get();

        $sessionIds = $sessions->pluck('id');
        $activity = $user->activityMinutes()
            ->whereIn('work_session_id', $sessionIds)
            ->get();
        $breaks = $user->breakSessions()
            ->whereIn('work_session_id', $sessionIds)
            ->get();

        $days = [];
        for ($day = $monthStart; $day->lessThan($nextMonth); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = [
                'date' => $day,
                'active_seconds' => 0,
                'idle_seconds' => 0,
                'namaz_seconds' => 0,
                'toilet_seconds' => 0,
                'total_seconds' => 0,
            ];
        }

        foreach ($activity as $row) {
            $key = WorkSchedule::local($row->minute_start)->format('Y-m-d');
            if (! isset($days[$key])) {
                continue;
            }
            $days[$key]['active_seconds'] += (int) $row->active_seconds;
            $days[$key]['idle_seconds'] += (int) $row->idle_seconds;
        }

        foreach ($breaks as $row) {
            $key = WorkSchedule::local($row->started_at)->format('Y-m-d');
            if (! isset($days[$key])) {
                continue;
            }

            if ($row->type === 'toilet') {
                $days[$key]['toilet_seconds'] += (int) $row->duration_seconds;
            } else {
                $days[$key]['namaz_seconds'] += (int) $row->duration_seconds;
            }
        }

        foreach ($days as $key => $day) {
            $days[$key]['total_seconds'] = self::combinedTotal(
                $day['active_seconds'],
                $day['idle_seconds'],
                $day['namaz_seconds'],
                $mode,
            );
        }

        $activeSeconds = (int) $activity->sum('active_seconds');
        $idleSeconds = (int) $activity->sum('idle_seconds');
        $breakSeconds = (int) $breaks->sum('duration_seconds');
        $namaz = [
            'asor' => (int) $breaks->where('type', 'asor')->sum('duration_seconds'),
            'maghrib' => (int) $breaks->where('type', 'maghrib')->sum('duration_seconds'),
        ];
        $namaz['total'] = $namaz['asor'] + $namaz['maghrib'];
        $toiletSeconds = (int) $breaks->where('type', 'toilet')->sum('duration_seconds');

        return [
            'user' => $user,
            'month' => $monthStart,
            'days' => array_values($days),
            'sessions' => $sessions,
            'screenshots' => $sessions->sum('screenshots_count'),
            'active_seconds' => $activeSeconds,
            'idle_seconds' => $idleSeconds,
            'break_seconds' => $breakSeconds,
            'keystrokes' => (int) $activity->sum('keystrokes'),
            'clicks' => (int) $activity->sum('clicks'),
            'namaz' => $namaz,
            'toilet_seconds' => $toiletSeconds,
            'total_seconds' => self::combinedTotal($activeSeconds, $idleSeconds, $namaz['total'], $mode),
            'mode' => $mode,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function teamMonthly(CarbonInterface|string|null $month = null, ?string $mode = 'active'): Collection
    {
        $month = $this->localMonth($month);

        return User::query()
            ->where('role', 'staff')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->monthly($user, $month, $mode));
    }

    /**
     * @return array<string, int>
     */
    public function teamMonthlyTotals(Collection $team): array
    {
        return [
            'staff' => $team->count(),
            'active_seconds' => (int) $team->sum('active_seconds'),
            'idle_seconds' => (int) $team->sum('idle_seconds'),
            'break_seconds' => (int) $team->sum('break_seconds'),
            'namaz_seconds' => (int) $team->sum(fn (array $row) => $row['namaz']['total']),
            'toilet_seconds' => (int) $team->sum('toilet_seconds'),
            'total_seconds' => (int) $team->sum('total_seconds'),
            'keystrokes' => (int) $team->sum('keystrokes'),
            'clicks' => (int) $team->sum('clicks'),
            'screenshots' => (int) $team->sum('screenshots'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function weekly(User $user, int $days = 7): array
    {
        $today = WorkSchedule::now()->startOfDay();
        $rows = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $summary = $this->daily($user, $day);
            $rows[] = [
                'date' => $day,
                'label' => $day->format('D'),
                'active_seconds' => $summary['active_seconds'],
                'idle_seconds' => $summary['idle_seconds'],
                'namaz_seconds' => $summary['namaz']['total'],
                'toilet_seconds' => $summary['toilet_seconds'],
            ];
        }

        return $rows;
    }

    public function localMonth(CarbonInterface|string|null $month = null): CarbonImmutable
    {
        if ($month instanceof CarbonInterface) {
            return CarbonImmutable::instance($month)->timezone(WorkSchedule::timezone())->startOfMonth();
        }

        if (is_string($month) && $month !== '') {
            if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                return CarbonImmutable::parse($month.'-01', WorkSchedule::timezone())->startOfMonth();
            }

            return CarbonImmutable::parse($month, WorkSchedule::timezone())->startOfMonth();
        }

        return WorkSchedule::now()->startOfMonth();
    }

    private function localDate(CarbonInterface|string|null $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->timezone(WorkSchedule::timezone())->startOfDay();
        }

        if (is_string($date) && $date !== '') {
            return CarbonImmutable::parse($date, WorkSchedule::timezone())->startOfDay();

        }

        return WorkSchedule::now()->startOfDay();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayBounds(CarbonImmutable $localDate): array
    {
        return [
            $localDate->startOfDay()->utc(),
            $localDate->addDay()->startOfDay()->utc(),
        ];
    }
}

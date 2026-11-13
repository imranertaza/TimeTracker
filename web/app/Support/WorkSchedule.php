<?php

namespace App\Support;

use App\Models\OfficeSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class WorkSchedule
{
    public static function timezone(): string
    {
        return config('tracker.timezone', 'Asia/Dhaka');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * @return array<int, array{start: string, end: string}>
     */
    public static function globalWindows(): array
    {
        return self::normaliseWindows(OfficeSetting::current()->windows);
    }

    /**
     * Windows for a user. A staff member with a custom schedule uses their own
     * windows; otherwise the global office schedule applies.
     *
     * @return array<int, array{start: string, end: string, start_minutes: int, end_minutes: int}>
     */
    public static function windows(?User $user = null): array
    {
        $raw = $user?->office_windows;

        if (! is_array($raw) || $raw === []) {
            $raw = OfficeSetting::current()->windows;
        }

        $windows = self::normaliseWindows($raw);

        if ($windows === []) {
            $windows = self::normaliseWindows(config('tracker.windows', []));
        }

        return array_map(function (array $window): array {
            return [
                'start' => $window['start'],
                'end' => $window['end'],
                'start_minutes' => self::toMinutes($window['start']),
                'end_minutes' => self::toMinutes($window['end']),
            ];
        }, $windows);
    }

    /**
     * @return array<string, array{label: string, minutes: int}>
     */
    public static function breakRules(?User $user = null): array
    {
        $setting = OfficeSetting::current();

        return [
            'asor' => [
                'label' => (string) config('tracker.breaks.asor.label', 'Asor Namaz'),
                'minutes' => (int) $setting->asor_namaz_minutes,
            ],
            'maghrib' => [
                'label' => (string) config('tracker.breaks.maghrib.label', 'Maghrib Namaz'),
                'minutes' => (int) $setting->maghrib_namaz_minutes,
            ],
            'toilet' => [
                'label' => (string) config('tracker.breaks.toilet.label', 'Toilet Break'),
                'minutes' => self::toiletMinutes($user),
            ],
        ];
    }

    /**
     * Toilet allowance for a staff member. A null per-user value means the
     * global office setting applies.
     */
    public static function toiletMinutes(?User $user = null): int
    {
        $minutes = $user?->toilet_minutes;

        if ($minutes === null) {
            $minutes = OfficeSetting::current()->toilet_minutes;
        }

        return max(1, (int) $minutes);
    }

    public static function breakMinutes(string $type, ?User $user = null): int
    {
        if ($type === 'toilet') {
            return self::toiletMinutes($user);
        }

        return (int) (self::breakRules($user)[$type]['minutes'] ?? 20);
    }

    public static function idleThresholdMinutes(?User $user = null): int
    {
        return (int) OfficeSetting::current()->idle_threshold_minutes;
    }

    public static function screenshotIntervalMinutes(?User $user = null): int
    {
        return (int) OfficeSetting::current()->screenshot_interval_minutes;
    }

    public static function toMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    public static function isValidTime(string $time): bool
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return false;
        }

        return true;
    }

    /**
     * Turn either old-style start/end pairs or form input into clean windows.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public static function normaliseWindows(mixed $raw): array
    {
        $windows = [];

        if (! is_array($raw)) {
            return $windows;
        }

        foreach ($raw as $window) {
            if (! is_array($window)) {
                continue;
            }

            $start = trim((string) ($window['start'] ?? ''));
            $end = trim((string) ($window['end'] ?? ''));

            if (! self::isValidTime($start) || ! self::isValidTime($end)) {
                continue;
            }

            if (self::toMinutes($end) <= self::toMinutes($start)) {
                continue;
            }

            $windows[] = ['start' => $start, 'end' => $end];
        }

        return $windows;
    }

    /**
     * Validate windows submitted from an admin form.
     *
     * @return array<int, array{start: string, end: string}>|null
     *
     * @throws ValidationException
     */
    public static function parseWindowsInput(mixed $raw, bool $required = true): ?array
    {
        $windows = [];
        $pairs = is_array($raw) ? $raw : [];

        foreach ($pairs as $pair) {
            if (! is_array($pair)) {
                continue;
            }

            $start = trim((string) ($pair['start'] ?? ''));
            $end = trim((string) ($pair['end'] ?? ''));

            if ($start === '' && $end === '') {
                continue;
            }

            if (! self::isValidTime($start) || ! self::isValidTime($end)) {
                throw ValidationException::withMessages([
                    'windows' => 'Each office window needs a valid start and end time (for example 09:00 and 13:15).',
                ]);
            }

            if (self::toMinutes($end) <= self::toMinutes($start)) {
                throw ValidationException::withMessages([
                    'windows' => 'Each office window must end after it starts.',
                ]);
            }

            $windows[] = ['start' => $start, 'end' => $end];
        }

        if ($required && $windows === []) {
            throw ValidationException::withMessages([
                'windows' => 'Add at least one complete office window.',
            ]);
        }

        return $windows === [] ? null : $windows;
    }

    public static function isWithinWindow(CarbonInterface|string|null $at = null, ?User $user = null): bool
    {
        $local = self::local($at);
        $minute = ($local->hour * 60) + $local->minute;

        foreach (self::windows($user) as $window) {
            if ($minute >= $window['start_minutes'] && $minute < $window['end_minutes']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the current/next window and whether it is currently open.
     *
     * @return array{is_open: bool, window: array|null, starts_at: string|null, ends_at: string|null, next_start: string|null}
     */
    public static function status(CarbonInterface|string|null $at = null, ?User $user = null): array
    {
        $local = self::local($at);
        $minute = ($local->hour * 60) + $local->minute;

        foreach (self::windows($user) as $window) {
            if ($minute >= $window['start_minutes'] && $minute < $window['end_minutes']) {
                return [
                    'is_open' => true,
                    'window' => ['start' => $window['start'], 'end' => $window['end']],
                    'starts_at' => $local->format('Y-m-d').' '.$window['start'],
                    'ends_at' => $local->format('Y-m-d').' '.$window['end'],
                    'next_start' => null,
                ];
            }
        }

        $next = self::nextWindowStart($local, $user);

        return [
            'is_open' => false,
            'window' => null,
            'starts_at' => null,
            'ends_at' => null,
            'next_start' => $next->format('Y-m-d H:i'),
        ];
    }

    public static function nextWindowStart(CarbonInterface|string|null $at = null, ?User $user = null): CarbonImmutable
    {
        $local = self::local($at);
        $windows = self::windows($user);

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $day = $local->startOfDay()->addDays($dayOffset);
            foreach ($windows as $window) {
                $start = $day->setTimeFromTimeString($window['start']);
                if ($start->greaterThan($local)) {
                    return $start;
                }
            }
        }

        return $local->addDay()->startOfDay()->setTime(9, 0);
    }

    public static function local(CarbonInterface|string|null $at = null): CarbonImmutable
    {
        if ($at === null) {
            return self::now();
        }

        if ($at instanceof CarbonInterface) {
            return CarbonImmutable::instance($at)->timezone(self::timezone());
        }

        return CarbonImmutable::parse($at, self::timezone())->timezone(self::timezone());
    }

    /**
     * @return array<string, mixed>
     */
    public static function apiPayload(?User $user = null): array
    {
        return [
            'timezone' => self::timezone(),
            'windows' => self::windows($user),
            'idle_threshold_minutes' => self::idleThresholdMinutes($user),
            'screenshot_interval_minutes' => self::screenshotIntervalMinutes($user),
            'breaks' => self::breakRules($user),
            'status' => self::status(null, $user),
        ];
    }

    /**
     * Fingerprint of every setting the desktop app has to know about.
     *
     * The value only changes when the global office settings or this user's own
     * custom schedule change, which lets the desktop app ask the server "did
     * anything change?" and get an instant answer.
     */
    public static function settingsVersion(?User $user = null): string
    {
        $setting = OfficeSetting::current();

        $fingerprint = [
            'timezone' => self::timezone(),
            'windows' => self::normaliseWindows($setting->windows),
            'idle_threshold_minutes' => (int) $setting->idle_threshold_minutes,
            'screenshot_interval_minutes' => (int) $setting->screenshot_interval_minutes,
            'asor_namaz_minutes' => (int) $setting->asor_namaz_minutes,
            'maghrib_namaz_minutes' => (int) $setting->maghrib_namaz_minutes,
            'toilet_minutes' => (int) $setting->toilet_minutes,
            'total_time_mode' => (string) $setting->total_time_mode,
            'office_windows' => $user?->office_windows,
            'staff_toilet_minutes' => $user?->toilet_minutes,
        ];

        return substr(hash('sha256', (string) json_encode($fingerprint)), 0, 32);
    }

    /**
     * Version + schedule payload used by the live settings endpoint.
     *
     * @return array{version: string, schedule: array<string, mixed>}
     */
    public static function settingsPayload(?User $user = null): array
    {
        return [
            'version' => self::settingsVersion($user),
            'schedule' => self::apiPayload($user),
        ];
    }

    public static function describeWindows(?User $user = null): string
    {
        return collect(self::windows($user))
            ->map(fn (array $window) => $window['start'].'–'.$window['end'])
            ->implode(' · ');
    }

    public static function descriptionWithBreaks(?User $user = null): string
    {
        $breaks = collect(self::breakRules($user))
            ->map(fn (array $rule) => $rule['label'].' '.$rule['minutes'].'m')
            ->implode(' · ');

        return self::describeWindows($user).' · '.$breaks;
    }
}

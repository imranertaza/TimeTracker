<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Format
{
    /**
     * Human readable duration.
     *
     * Seconds are never silently dropped: 258 seconds is shown as "4m 18s"
     * and not as "4m", so the report always adds up to the real tracked time.
     */
    public static function duration(int $seconds, bool $clock = false): string
    {
        $seconds = max(0, $seconds);

        if ($clock) {
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        if ($hours > 0) {
            $text = sprintf('%dh %02dm', $hours, $minutes);

            return $rest > 0 ? $text.' '.sprintf('%02ds', $rest) : $text;
        }

        if ($minutes > 0) {
            $text = $minutes.'m';

            return $rest > 0 ? $text.' '.sprintf('%02ds', $rest) : $text;
        }

        return $seconds.'s';
    }

    /**
     * Wall clock length between two timestamps (for example "4m 20s").
     */
    public static function elapsed(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if (! $start || ! $end) {
            return '—';
        }

        return self::duration(abs((int) $start->diffInSeconds($end)));
    }

    public static function dateTime(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('d M Y, h:i A')
            : '—';
    }

    /**
     * Date and time including seconds, so a session's real length can be checked.
     */
    public static function dateTimeWithSeconds(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('d M Y, h:i:s A')
            : '—';
    }

    public static function time(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('h:i A')
            : '—';
    }

    /**
     * Clock time including seconds.
     */
    public static function timeWithSeconds(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('h:i:s A')
            : '—';
    }

    public static function day(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('d M Y')
            : '—';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'running' => 'Tracking',
            'paused' => 'On break',
            'stopped' => 'Stopped',
            default => 'Not started',
        };
    }

    public static function statusClass(string $status): string
    {
        return match ($status) {
            'running' => 'badge-success',
            'paused' => 'badge-warning',
            'stopped' => 'badge-muted',
            default => 'badge-muted',
        };
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}

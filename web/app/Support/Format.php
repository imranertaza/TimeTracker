<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Format
{
    public static function duration(int $seconds, bool $clock = false): string
    {
        $seconds = max(0, $seconds);

        if ($clock) {
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return $minutes.'m';
        }

        return $seconds.'s';
    }

    public static function dateTime(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('d M Y, h:i A')
            : '—';
    }

    public static function time(?CarbonInterface $value): string
    {
        return $value
            ? $value->timezone(WorkSchedule::timezone())->format('h:i A')
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

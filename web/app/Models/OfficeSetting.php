<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeSetting extends Model
{
    protected $fillable = [
        'windows',
        'idle_threshold_minutes',
        'screenshot_interval_minutes',
        'asor_namaz_minutes',
        'maghrib_namaz_minutes',
        'total_time_mode',
    ];

    protected function casts(): array
    {
        return [
            'windows' => 'array',
            'idle_threshold_minutes' => 'integer',
            'screenshot_interval_minutes' => 'integer',
            'asor_namaz_minutes' => 'integer',
            'maghrib_namaz_minutes' => 'integer',
        ];
    }

    public static function current(): self
    {
        $setting = static::query()->orderBy('id')->first();

        return $setting ?? static::query()->create(static::defaults());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'windows' => config('tracker.windows', [
                ['start' => '09:00', 'end' => '13:15'],
                ['start' => '14:15', 'end' => '18:30'],
            ]),
            'idle_threshold_minutes' => (int) config('tracker.idle_threshold_minutes', 5),
            'screenshot_interval_minutes' => (int) config('tracker.screenshot_interval_minutes', 10),
            'asor_namaz_minutes' => (int) config('tracker.breaks.asor.minutes', 20),
            'maghrib_namaz_minutes' => (int) config('tracker.breaks.maghrib.minutes', 15),
            'total_time_mode' => 'active',
        ];
    }
}

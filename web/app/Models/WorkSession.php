<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'started_at',
        'ended_at',
        'status',
        'active_seconds',
        'idle_seconds',
        'break_seconds',
        'device_name',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityMinutes(): HasMany
    {
        return $this->hasMany(ActivityMinute::class);
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(BreakSession::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    public function getTrackedSecondsAttribute(): int
    {
        return $this->active_seconds + $this->idle_seconds;
    }

    public function getIsRunningAttribute(): bool
    {
        return in_array($this->status, ['running', 'paused'], true);
    }
}

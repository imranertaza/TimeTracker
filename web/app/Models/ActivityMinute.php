<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityMinute extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_session_id',
        'user_id',
        'minute_start',
        'keystrokes',
        'clicks',
        'active_seconds',
        'idle_seconds',
        'is_idle',
    ];

    protected function casts(): array
    {
        return [
            'minute_start' => 'datetime',
            'is_idle' => 'boolean',
        ];
    }

    public function workSession(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

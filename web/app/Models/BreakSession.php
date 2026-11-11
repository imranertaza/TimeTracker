<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakSession extends Model
{
    use HasFactory;

    protected $table = 'break_sessions';

    protected $fillable = [
        'uuid',
        'work_session_id',
        'user_id',
        'type',
        'started_at',
        'ended_at',
        'duration_seconds',
        'completed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'completed' => 'boolean',
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

    public function getLabelAttribute(): string
    {
        return config("tracker.breaks.{$this->type}.label", ucfirst($this->type));
    }
}

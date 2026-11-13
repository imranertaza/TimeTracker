<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'timezone',
        'office_windows',
        'toilet_minutes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'office_windows' => 'array',
            'toilet_minutes' => 'integer',
        ];
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function activityMinutes(): HasMany
    {
        return $this->hasMany(ActivityMinute::class);
    }

    public function breakSessions(): HasMany
    {
        return $this->hasMany(BreakSession::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function issueApiToken(): string
    {
        $token = Str::random(80);
        $this->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function revokeApiToken(): void
    {
        $this->forceFill(['api_token_hash' => null])->save();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $key = 'desktop-login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return response()->json([
                'message' => 'Too many login attempts. Please wait a minute and try again.',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This staff account is inactive. Contact your administrator.'], 403);
        }

        RateLimiter::clear($key);

        $token = $user->issueApiToken();
        $user->forceFill([
            'last_seen_at' => now(),
            'timezone' => config('tracker.timezone', 'Asia/Dhaka'),
        ])->save();

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $this->userPayload($user),
            'server_time' => WorkSchedule::now()->toIso8601String(),
            'schedule' => WorkSchedule::apiPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'server_time' => WorkSchedule::now()->toIso8601String(),
            'schedule' => WorkSchedule::apiPayload($user),
            'current_session' => $user->workSessions()
                ->whereIn('status', ['running', 'paused'])
                ->latest('started_at')
                ->first(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->revokeApiToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'timezone' => $user->timezone ?: config('tracker.timezone', 'Asia/Dhaka'),
        ];
    }
}

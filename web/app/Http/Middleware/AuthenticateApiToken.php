<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['message' => 'Authentication token is required.'], 401);
        }

        $user = User::query()
            ->where('api_token_hash', hash('sha256', $bearer))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired authentication token.'], 401);
        }

        $now = now();

        // The desktop app polls the live settings endpoint every couple of
        // seconds, so the heartbeat column is only written when it actually
        // moved. This keeps the online indicator fresh without a database
        // write on every single request.
        if (! $user->last_seen_at || $user->last_seen_at->lt($now->copy()->subSeconds(60))) {
            $user->forceFill(['last_seen_at' => $now])->save();
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}

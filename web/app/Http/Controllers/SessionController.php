<?php

namespace App\Http\Controllers;

use App\Models\WorkSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function show(Request $request, WorkSession $workSession): View
    {
        $user = $request->user();

        if (! $user->isAdmin() && $workSession->user_id !== $user->id) {
            abort(403);
        }

        $workSession->load([
            'user',
            'activityMinutes' => fn ($query) => $query->orderBy('minute_start'),
            'breaks' => fn ($query) => $query->orderBy('started_at'),
            'screenshots' => fn ($query) => $query->orderBy('taken_at'),
        ]);

        return view('sessions.show', [
            'session' => $workSession,
            'activity' => $workSession->activityMinutes,
            'breaks' => $workSession->breaks,
            'screenshots' => $workSession->screenshots,
        ]);
    }
}

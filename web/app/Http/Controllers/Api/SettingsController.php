<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Live settings feed for the desktop app.
     *
     * The desktop app calls this endpoint every couple of seconds with the
     * version it already knows about. When the admin changes the global office
     * settings or a staff member's custom schedule the version changes and the
     * full schedule comes back in the same response, so the change lands in the
     * running app within seconds.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = WorkSchedule::settingsPayload($user);
        $since = trim((string) $request->query('since'));

        if ($since !== '' && hash_equals($payload['version'], $since)) {
            return $this->response([
                'version' => $payload['version'],
                'changed' => false,
            ]);
        }

        return $this->response([
            'version' => $payload['version'],
            'changed' => true,
            'schedule' => $payload['schedule'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function response(array $data): JsonResponse
    {
        return response()
            ->json($data + ['server_time' => WorkSchedule::now()->toIso8601String()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}

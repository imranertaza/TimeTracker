<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screenshot;
use App\Models\WorkSession;
use App\Support\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScreenshotController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => ['required', 'uuid'],
            'taken_at' => ['required', 'date'],
            'screenshot' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $user = $request->user();
        $session = WorkSession::query()
            ->where('uuid', $data['session_uuid'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $takenAt = CarbonImmutable::parse($data['taken_at'])->utc();
        $file = $request->file('screenshot');
        $directory = sprintf(
            'screenshots/%d/%s/%s',
            $user->id,
            $takenAt->timezone(WorkSchedule::timezone())->format('Y-m-d'),
            $session->uuid,
        );
        $filename = $takenAt->format('His').'-'.substr(bin2hex(random_bytes(4)), 0, 8).'.'.$file->extension();
        $path = $file->storeAs($directory, $filename, 'local');

        $screenshot = Screenshot::query()->create([
            'work_session_id' => $session->id,
            'user_id' => $user->id,
            'path' => $path,
            'taken_at' => $takenAt,
            'file_size' => $file->getSize() ?: 0,
        ]);

        return response()->json([
            'message' => 'Screenshot uploaded.',
            'screenshot' => [
                'id' => $screenshot->id,
                'taken_at' => $screenshot->taken_at->toIso8601String(),
                'url' => route('screenshots.show', $screenshot),
            ],
        ], 201);
    }
}

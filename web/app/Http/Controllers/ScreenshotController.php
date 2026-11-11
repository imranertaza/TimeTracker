<?php

namespace App\Http\Controllers;

use App\Models\Screenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScreenshotController extends Controller
{
    public function show(Request $request, Screenshot $screenshot): BinaryFileResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $screenshot->user_id !== $user->id) {
            abort(403);
        }

        $disk = Storage::disk('local');

        abort_unless($disk->exists($screenshot->path), 404, 'Screenshot file is missing.');

        return response()->file($disk->path($screenshot->path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

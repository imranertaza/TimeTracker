<?php

namespace Tests\Feature;

use App\Models\Screenshot;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function freezeAt(string $localTime): void
    {
        \Carbon\CarbonImmutable::setTestNow(
            \Carbon\CarbonImmutable::parse($localTime, 'Asia/Dhaka')
        );
    }

    public function test_desktop_api_tracks_a_session_inside_office_hours(): void
    {
        $this->seed();
        $this->freezeAt('2026-11-09 10:00:00');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
            'device_name' => 'Test PC',
        ])->assertOk()->json();

        $token = $login['token'];
        $uuid = (string) Str::uuid();
        $startedAt = now()->utc()->toIso8601String();

        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => $uuid,
            'started_at' => $startedAt,
            'device_name' => 'Test PC',
        ])->assertCreated();

        $breakUuid = (string) Str::uuid();
        $minute = now()->utc()->startOfMinute();

        $this->withToken($token)->postJson("/api/v1/sessions/{$uuid}/sync", [
            'status' => 'running',
            'activity' => [
                [
                    'minute_start' => $minute->toIso8601String(),
                    'keystrokes' => 42,
                    'clicks' => 8,
                    'active_seconds' => 60,
                    'idle_seconds' => 0,
                    'is_idle' => false,
                ],
            ],
            'breaks' => [
                [
                    'uuid' => $breakUuid,
                    'type' => 'asor',
                    'started_at' => $minute->copy()->addMinutes(5)->toIso8601String(),
                    'ended_at' => $minute->copy()->addMinutes(25)->toIso8601String(),
                    'duration_seconds' => 1200,
                    'completed' => true,
                ],
            ],
        ])->assertOk();

        $session = WorkSession::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(60, $session->active_seconds);
        $this->assertSame(0, $session->idle_seconds);
        $this->assertSame(1200, $session->break_seconds);
        $this->assertSame(42, (int) $session->activityMinutes()->sum('keystrokes'));

        $this->freezeAt('2026-11-09 11:00:00');
        $this->withToken($token)->postJson("/api/v1/sessions/{$uuid}/stop", [
            'ended_at' => now()->utc()->toIso8601String(),
        ])->assertOk();

        $this->assertSame('stopped', $session->fresh()->status);
    }

    public function test_toilet_break_is_tracked_and_overrunning_it_stops_the_session(): void
    {
        $this->seed();
        $this->freezeAt('2026-11-09 10:00:00');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
        ])->json('token');

        $sessionUuid = (string) Str::uuid();
        $breakUuid = (string) Str::uuid();

        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => $sessionUuid,
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/breaks/start', [
            'uuid' => $breakUuid,
            'session_uuid' => $sessionUuid,
            'type' => 'toilet',
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('planned_seconds', 900);

        // The staff member comes back after the allowance: tracking stops.
        $this->freezeAt('2026-11-09 10:20:00');

        $this->withToken($token)->postJson('/api/v1/breaks/'.$breakUuid.'/end', [
            'ended_at' => now()->utc()->toIso8601String(),
        ])->assertOk();

        $session = WorkSession::query()->where('uuid', $sessionUuid)->firstOrFail();
        $this->assertSame('stopped', $session->status);
        $this->assertSame(900, (int) $session->breaks()->where('type', 'toilet')->sum('duration_seconds'));
    }

    public function test_tracking_cannot_start_outside_office_windows(): void
    {
        $this->seed();
        $this->freezeAt('2026-11-09 13:30:00');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertStatus(422);

        $this->freezeAt('2026-11-09 14:00:00');
        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertStatus(422);

        $this->freezeAt('2026-11-09 14:16:00');
        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertCreated();
    }

    public function test_screenshot_upload_and_authorised_delivery(): void
    {
        Storage::fake('local');
        $this->seed();
        $this->freezeAt('2026-11-09 10:00:00');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
        ])->json();
        $token = $login['token'];
        $uuid = (string) Str::uuid();

        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => $uuid,
            'started_at' => now()->utc()->toIso8601String(),
            'device_name' => 'Test PC',
        ])->assertCreated();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=');
        $path = tempnam(sys_get_temp_dir(), 'shot').'.png';
        file_put_contents($path, $png);
        $upload = new UploadedFile($path, 'screen.png', 'image/png', null, true);

        $response = $this->withToken($token)->post('/api/v1/screenshots', [
            'session_uuid' => $uuid,
            'taken_at' => now()->utc()->toIso8601String(),
            'screenshot' => $upload,
        ])->assertCreated();

        $screenshot = Screenshot::query()->firstOrFail();
        Storage::disk('local')->assertExists($screenshot->path);

        $staff = User::query()->where('email', 'staff@officetracker.test')->firstOrFail();
        $this->actingAs($staff)->get($response->json('screenshot.url'))->assertOk();

        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $this->actingAs($admin)->get($response->json('screenshot.url'))->assertOk();
    }

    public function test_admin_and_staff_web_dashboards_render(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $staff = User::query()->where('email', 'staff@officetracker.test')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Office overview');
        $this->actingAs($admin)->get('/admin/reports')->assertOk()->assertSee('Daily time report');
        $this->actingAs($admin)->get('/admin/staff')->assertOk()->assertSee('Staff accounts');

        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertSee('My timesheet');
        $this->actingAs($staff)->get('/admin/reports')->assertForbidden();
    }
}

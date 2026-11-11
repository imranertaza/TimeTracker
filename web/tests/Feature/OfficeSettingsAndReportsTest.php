<?php

namespace Tests\Feature;

use App\Models\ActivityMinute;
use App\Models\BreakSession;
use App\Models\OfficeSetting;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeSettingsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function freezeAt(string $localTime): void
    {
        \Carbon\CarbonImmutable::setTestNow(
            \Carbon\CarbonImmutable::parse($localTime, 'Asia/Dhaka')
        );
    }

    public function test_admin_can_update_global_office_time_and_api_uses_it(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $this->freezeAt('2026-11-09 09:30:00');

        $this->actingAs($admin)->put('/admin/settings', [
            'windows' => [
                ['start' => '10:00', 'end' => '12:00'],
                ['start' => '', 'end' => ''],
                ['start' => '', 'end' => ''],
            ],
            'idle_threshold_minutes' => 6,
            'screenshot_interval_minutes' => 12,
            'asor_namaz_minutes' => 25,
            'maghrib_namaz_minutes' => 18,
            'total_time_mode' => 'active_idle',
        ])->assertRedirect('/admin/settings');

        $setting = OfficeSetting::current()->fresh();
        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $setting->windows);
        $this->assertSame(25, $setting->asor_namaz_minutes);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertStatus(422);

        $this->freezeAt('2026-11-09 10:30:00');

        $response = $this->withToken($token)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertCreated()->json();

        $this->assertSame('10:00', $response['schedule']['windows'][0]['start']);
        $this->assertSame('12:00', $response['schedule']['windows'][0]['end']);
        $this->assertSame(25, $response['schedule']['breaks']['asor']['minutes']);
    }

    public function test_per_staff_office_time_overrides_global_schedule(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $staffOne = User::query()->where('email', 'staff@officetracker.test')->firstOrFail();

        $this->actingAs($admin)->put('/admin/staff/'.$staffOne->id, [
            'name' => $staffOne->name,
            'email' => $staffOne->email,
            'is_active' => 1,
            'schedule_mode' => 'custom',
            'windows' => [
                ['start' => '12:00', 'end' => '15:00'],
                ['start' => '', 'end' => ''],
                ['start' => '', 'end' => ''],
            ],
        ])->assertRedirect('/admin/staff');

        $this->assertSame([['start' => '12:00', 'end' => '15:00']], $staffOne->fresh()->office_windows);

        $this->freezeAt('2026-11-09 09:30:00');

        $staffOneToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@officetracker.test',
            'password' => 'Staff@12345',
        ])->json('token');
        $staffTwoToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff2@officetracker.test',
            'password' => 'Staff@12345',
        ])->json('token');

        $this->withToken($staffOneToken)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertStatus(422);

        $this->freezeAt('2026-11-09 13:30:00');

        $this->withToken($staffOneToken)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertCreated();

        $this->withToken($staffTwoToken)->postJson('/api/v1/sessions/start', [
            'uuid' => (string) Str::uuid(),
            'started_at' => now()->utc()->toIso8601String(),
        ])->assertStatus(422);

        $this->actingAs($admin)->put('/admin/staff/'.$staffOne->id, [
            'name' => $staffOne->name,
            'email' => $staffOne->email,
            'is_active' => 1,
            'schedule_mode' => 'global',
            'windows' => [
                ['start' => '12:00', 'end' => '15:00'],
            ],
        ])->assertRedirect('/admin/staff');

        $this->assertNull($staffOne->fresh()->office_windows);
    }

    public function test_admin_settings_and_staff_schedule_forms_render(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $staff = User::query()->where('email', 'staff@officetracker.test')->firstOrFail();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Office time settings')
            ->assertSee('Global office windows');

        $staff->forceFill(['office_windows' => [['start' => '12:00', 'end' => '15:00']]])->save();

        $this->actingAs($admin)->get('/admin/staff/'.$staff->id.'/edit')
            ->assertOk()
            ->assertSee('Custom office time for this staff member')
            ->assertSee('12:00');
    }

    public function test_monthly_reports_and_total_time_formulas_are_available_to_admin_and_staff(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@officetracker.test')->firstOrFail();
        $staff = User::query()->where('email', 'staff@officetracker.test')->firstOrFail();

        $startedAt = \Carbon\CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Dhaka')->utc();
        $minute = \Carbon\CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Dhaka')->utc();
        $breakStart = \Carbon\CarbonImmutable::parse('2026-11-09 11:00:00', 'Asia/Dhaka')->utc();
        $breakEnd = \Carbon\CarbonImmutable::parse('2026-11-09 11:20:00', 'Asia/Dhaka')->utc();

        $session = WorkSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $staff->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addHours(4),
            'status' => 'stopped',
            'active_seconds' => 3600,
            'idle_seconds' => 600,
            'break_seconds' => 2100,
        ]);

        ActivityMinute::query()->create([
            'work_session_id' => $session->id,
            'user_id' => $staff->id,
            'minute_start' => $minute,
            'keystrokes' => 100,
            'clicks' => 20,
            'active_seconds' => 3600,
            'idle_seconds' => 600,
            'is_idle' => false,
        ]);

        BreakSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'work_session_id' => $session->id,
            'user_id' => $staff->id,
            'type' => 'asor',
            'started_at' => $breakStart,
            'ended_at' => $breakEnd,
            'duration_seconds' => 1200,
            'completed' => true,
        ]);

        BreakSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'work_session_id' => $session->id,
            'user_id' => $staff->id,
            'type' => 'maghrib',
            'started_at' => $breakStart->addHours(1),
            'ended_at' => $breakStart->addHours(1)->addMinutes(15),
            'duration_seconds' => 900,
            'completed' => true,
        ]);

        $reports = app(ReportService::class);
        $all = $reports->monthly($staff, '2026-11', 'active_idle_namaz');
        $exact = $reports->monthly($staff, '2026-11', 'active');
        $withIdle = $reports->monthly($staff, '2026-11', 'active_idle');
        $withNamaz = $reports->monthly($staff, '2026-11', 'active_namaz');

        $this->assertSame(3600, $all['active_seconds']);
        $this->assertSame(600, $all['idle_seconds']);
        $this->assertSame(2100, $all['namaz']['total']);
        $this->assertSame(6300, $all['total_seconds']);
        $this->assertSame(3600, $exact['total_seconds']);
        $this->assertSame(4200, $withIdle['total_seconds']);
        $this->assertSame(5700, $withNamaz['total_seconds']);

        foreach (['active', 'active_idle', 'active_namaz', 'active_idle_namaz'] as $mode) {
            $this->actingAs($admin)
                ->get('/admin/reports/monthly?month=2026-11&mode='.$mode)
                ->assertOk()
                ->assertSee('Monthly time report');
        }

        $this->actingAs($admin)
            ->get('/admin/reports/monthly?month=2026-11&mode=active_idle_namaz')
            ->assertOk()
            ->assertSee('Monthly time report')
            ->assertSee('Exact working time + idle time + namaz break time');

        $this->actingAs($staff)
            ->get('/my-reports?month=2026-11&date=2026-11-09')
            ->assertOk()
            ->assertSee('My time reports')
            ->assertSee('Exact working time + idle time + namaz break time')
            ->assertSee('1h 45m');

        $this->actingAs($staff)->get('/admin/reports/monthly')->assertForbidden();
    }
}

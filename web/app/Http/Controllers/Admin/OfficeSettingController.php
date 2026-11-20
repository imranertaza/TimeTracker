<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfficeSetting;
use App\Services\ReportService;
use App\Support\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfficeSettingController extends Controller
{
    public function edit(): View
    {
        $setting = OfficeSetting::current();
        $windows = WorkSchedule::normaliseWindows($setting->windows);

        if ($windows === []) {
            $windows = WorkSchedule::normaliseWindows(config('tracker.windows', []));
        }

        return view('admin.settings', [
            'setting' => $setting,
            'windows' => $windows,
            'modes' => ReportService::totalModes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'windows' => ['nullable', 'array'],
            'idle_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'stop_after_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'screenshot_interval_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'asor_namaz_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'johor_namaz_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'maghrib_namaz_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'toilet_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'total_time_mode' => ['required', Rule::in(array_keys(ReportService::totalModes()))],
            'total_time_include_away' => ['nullable', 'boolean'],
        ]);

        $windows = WorkSchedule::parseWindowsInput($request->input('windows'), true);

        OfficeSetting::current()->fill([
            'windows' => $windows,
            'idle_threshold_minutes' => (int) ($data['idle_threshold_minutes'] ?? 0),
            'stop_after_minutes' => (int) ($data['stop_after_minutes'] ?? 0),
            'screenshot_interval_minutes' => $data['screenshot_interval_minutes'],
            'asor_namaz_minutes' => $data['asor_namaz_minutes'],
            'johor_namaz_minutes' => $data['johor_namaz_minutes'],
            'maghrib_namaz_minutes' => $data['maghrib_namaz_minutes'],
            'toilet_minutes' => $data['toilet_minutes'],
            'total_time_mode' => $data['total_time_mode'],
            'total_time_include_away' => $request->boolean('total_time_include_away'),
        ])->save();

        return redirect()->route('admin.settings.edit')->with('success', 'Office time settings updated. Staff on the global settings will receive the new rules automatically.');
    }
}

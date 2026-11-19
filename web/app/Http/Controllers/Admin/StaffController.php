<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $staff = User::query()
            ->where('role', 'staff')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.staff.index', compact('staff', 'search'));
    }

    public function create(): View
    {
        return view('admin.staff.form', [
            'staff' => new User(['role' => 'staff', 'is_active' => true]),
            'globalWindows' => WorkSchedule::globalWindows(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
            'schedule_mode' => ['required', 'in:global,custom'],
            'windows' => ['nullable', 'array'],
            'inactivity_mode' => ['required', 'in:global,custom'],
            'idle_threshold_minutes' => ['nullable', 'required_if:inactivity_mode,custom', 'integer', 'min:0', 'max:120'],
            'stop_after_minutes' => ['nullable', 'required_if:inactivity_mode,custom', 'integer', 'min:0', 'max:240'],
            'break_mode' => ['required', 'in:global,custom'],
            'asor_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'johor_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'maghrib_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'toilet_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
        ]);

        $windows = $data['schedule_mode'] === 'custom'
            ? WorkSchedule::parseWindowsInput($request->input('windows'), true)
            : null;

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'staff',
            'is_active' => $request->boolean('is_active', true),
            'timezone' => config('tracker.timezone', 'Asia/Dhaka'),
            'office_windows' => $windows,
            'idle_threshold_minutes' => $data['inactivity_mode'] === 'custom'
                ? (int) $data['idle_threshold_minutes']
                : null,
            'stop_after_minutes' => $data['inactivity_mode'] === 'custom'
                ? (int) $data['stop_after_minutes']
                : null,
            'asor_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['asor_namaz_minutes']
                : null,
            'johor_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['johor_namaz_minutes']
                : null,
            'maghrib_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['maghrib_namaz_minutes']
                : null,
            'toilet_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['toilet_minutes']
                : null,
        ]);

        return redirect()->route('admin.staff.index')->with('success', 'Staff account created.');
    }

    public function edit(User $staff): View
    {
        abort_unless($staff->isStaff(), 404);

        return view('admin.staff.form', [
            'staff' => $staff,
            'globalWindows' => WorkSchedule::globalWindows(),
        ]);
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
            'schedule_mode' => ['required', 'in:global,custom'],
            'windows' => ['nullable', 'array'],
            'inactivity_mode' => ['required', 'in:global,custom'],
            'idle_threshold_minutes' => ['nullable', 'required_if:inactivity_mode,custom', 'integer', 'min:0', 'max:120'],
            'stop_after_minutes' => ['nullable', 'required_if:inactivity_mode,custom', 'integer', 'min:0', 'max:240'],
            'break_mode' => ['required', 'in:global,custom'],
            'asor_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'johor_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'maghrib_namaz_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
            'toilet_minutes' => ['nullable', 'required_if:break_mode,custom', 'integer', 'min:1', 'max:180'],
        ]);

        $windows = $data['schedule_mode'] === 'custom'
            ? WorkSchedule::parseWindowsInput($request->input('windows'), true)
            : null;

        $staff->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $request->boolean('is_active'),
            'office_windows' => $windows,
            'idle_threshold_minutes' => $data['inactivity_mode'] === 'custom'
                ? (int) $data['idle_threshold_minutes']
                : null,
            'stop_after_minutes' => $data['inactivity_mode'] === 'custom'
                ? (int) $data['stop_after_minutes']
                : null,
            'asor_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['asor_namaz_minutes']
                : null,
            'johor_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['johor_namaz_minutes']
                : null,
            'maghrib_namaz_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['maghrib_namaz_minutes']
                : null,
            'toilet_minutes' => $data['break_mode'] === 'custom'
                ? (int) $data['toilet_minutes']
                : null,
        ]);

        if (! empty($data['password'])) {
            $staff->password = $data['password'];
            $staff->revokeApiToken();
        }

        if (! $staff->is_active) {
            $staff->revokeApiToken();
        }

        $staff->save();

        return redirect()->route('admin.staff.index')->with('success', 'Staff account updated.');
    }
}

@extends('layouts.app')

@section('title', $staff->exists ? 'Edit staff' : 'Add staff')
@section('subtitle', $staff->exists ? 'Update account details and access' : 'Create a desktop tracker login')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">{{ $staff->exists ? 'Edit '.$staff->name : 'Add staff member' }}</h2>
            <p class="page-description">Credentials are used by the Python desktop tracker and this web panel.</p>
        </div>
        <a class="button button-secondary" href="{{ route('admin.staff.index') }}">Back to staff</a>
    </div>

    <div class="card" style="max-width:760px">
        <div class="card-pad">
            <form method="POST" action="{{ $staff->exists ? route('admin.staff.update', $staff) : route('admin.staff.store') }}">
                @csrf
                @if($staff->exists)
                    @method('PUT')
                @endif

                <div class="form-row">
                    <div class="field">
                        <label for="name">Full name</label>
                        <input class="input" id="name" name="name" type="text" value="{{ old('name', $staff->name) }}" required>
                        @error('name')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="email">Email address</label>
                        <input class="input" id="email" name="email" type="email" value="{{ old('email', $staff->email) }}" required>
                        @error('email')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="password">Password {{ $staff->exists ? '(leave blank to keep current)' : '' }}</label>
                        <input class="input" id="password" name="password" type="password" {{ $staff->exists ? '' : 'required' }} autocomplete="new-password">
                        @error('password')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                    </div>
                </div>

                <div class="field check">
                    <input id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $staff->is_active ?? true))>
                    <label for="is_active" style="margin:0;font-weight:500">Account is active and allowed to sync</label>
                </div>

                @php
                    $globalSetting = \App\Models\OfficeSetting::current();

                    $scheduleMode = old('schedule_mode', $staff->office_windows ? 'custom' : 'global');
                    $windowRows = old('windows', $staff->office_windows ?: $globalWindows);
                    $windowRows = array_values(is_array($windowRows) ? $windowRows : []);
                    while (count($windowRows) < 3) {
                        $windowRows[] = ['start' => '', 'end' => ''];
                    }

                    $inactivityMode = old('inactivity_mode', ($staff->idle_threshold_minutes !== null || $staff->stop_after_minutes !== null) ? 'custom' : 'global');
                    $idleValue = old('idle_threshold_minutes', $staff->idle_threshold_minutes ?? $globalSetting->idle_threshold_minutes);
                    $stopValue = old('stop_after_minutes', $staff->stop_after_minutes ?? $globalSetting->stop_after_minutes);

                    $breakMode = old('break_mode', (
                        $staff->asor_namaz_minutes !== null
                        || $staff->johor_namaz_minutes !== null
                        || $staff->maghrib_namaz_minutes !== null
                        || $staff->toilet_minutes !== null
                    ) ? 'custom' : 'global');

                    $breakValues = [
                        'asor_namaz_minutes' => old('asor_namaz_minutes', $staff->asor_namaz_minutes ?? $globalSetting->asor_namaz_minutes),
                        'johor_namaz_minutes' => old('johor_namaz_minutes', $staff->johor_namaz_minutes ?? $globalSetting->johor_namaz_minutes),
                        'maghrib_namaz_minutes' => old('maghrib_namaz_minutes', $staff->maghrib_namaz_minutes ?? $globalSetting->maghrib_namaz_minutes),
                        'toilet_minutes' => old('toilet_minutes', $staff->toilet_minutes ?? $globalSetting->toilet_minutes),
                    ];
                @endphp

                <div class="divider"></div>
                <h3 class="card-title mb-2">Office time</h3>
                <div class="notice mb-2">
                    <span>◉</span>
                    <span>Global office hours: <strong>{{ \App\Support\WorkSchedule::describeWindows() }}</strong>. Choose a custom schedule to override the global hours for this staff member only. Saved changes reach this staff member's desktop app within about 2 seconds.</span>
                </div>

                <div class="field">
                    <label for="schedule_mode">Schedule type</label>
                    <select class="input" id="schedule_mode" name="schedule_mode" required>
                        <option value="global" @selected($scheduleMode === 'global')>Use global office time</option>
                        <option value="custom" @selected($scheduleMode === 'custom')>Custom office time for this staff member</option>
                    </select>
                    @error('schedule_mode')<div class="errors">{{ $message }}</div>@enderror
                </div>

                <div id="schedule-custom-fields">
                    <p class="page-description mb-2">Add one row per working window. Leave extra rows blank.</p>
                    <div class="table-wrap mb-2">
                        <table>
                            <thead>
                            <tr><th>Window</th><th>Start time</th><th>End time</th><th></th></tr>
                            </thead>
                            <tbody id="office-window-rows">
                            @foreach($windowRows as $index => $window)
                                <tr>
                                    <td class="strong">Window {{ $index + 1 }}</td>
                                    <td><input class="input input-sm" type="time" name="windows[{{ $index }}][start]" value="{{ $window['start'] ?? '' }}"></td>
                                    <td><input class="input input-sm" type="time" name="windows[{{ $index }}][end]" value="{{ $window['end'] ?? '' }}"></td>
                                    <td class="text-right"><button class="button button-secondary button-sm remove-window" type="button">Remove</button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="toolbar mb-2">
                        <button class="button button-secondary button-sm" id="add-window" type="button">+ Add window</button>
                    </div>
                </div>
                @error('windows')<div class="errors mb-2">{{ $message }}</div>@enderror

                <div class="divider"></div>
                <h3 class="card-title mb-2">Inactivity handling</h3>
                <div class="notice mb-2">
                    <span>◉</span>
                    <span>
                        Global rule:
                        <strong>{{ $globalSetting->idle_threshold_minutes > 0 ? 'idle after '.$globalSetting->idle_threshold_minutes.' min' : 'idle disabled' }}</strong>
                        ·
                        <strong>{{ $globalSetting->stop_after_minutes > 0 ? 'stop after '.$globalSetting->stop_after_minutes.' min' : 'auto-stop disabled' }}</strong>.
                        Enter <strong>0</strong> to disable a timer. Stop takes priority over idle.
                    </span>
                </div>

                <div class="field">
                    <label for="inactivity_mode">Inactivity setting</label>
                    <select class="input" id="inactivity_mode" name="inactivity_mode" required>
                        <option value="global" @selected($inactivityMode === 'global')>Use global idle time and stop time</option>
                        <option value="custom" @selected($inactivityMode === 'custom')>Custom idle time and stop time for this staff member</option>
                    </select>
                    @error('inactivity_mode')<div class="errors">{{ $message }}</div>@enderror
                </div>

                <div id="inactivity-custom-fields">
                    <div class="form-row">
                        <div class="field">
                            <label for="idle_threshold_minutes">Idle time (minutes)</label>
                            <input class="input" id="idle_threshold_minutes" name="idle_threshold_minutes" type="number" min="0" max="120" value="{{ $idleValue }}">
                            <p class="page-description" style="margin-top:6px">0 = idle time disabled.</p>
                            @error('idle_threshold_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="stop_after_minutes">Stop time (minutes)</label>
                            <input class="input" id="stop_after_minutes" name="stop_after_minutes" type="number" min="0" max="240" value="{{ $stopValue }}">
                            <p class="page-description" style="margin-top:6px">0 = automatic stop disabled. Takes priority over idle.</p>
                            @error('stop_after_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="divider"></div>
                <h3 class="card-title mb-2">Break allowances</h3>
                <div class="notice mb-2">
                    <span>◉</span>
                    <span>
                        Global allowances:
                        <strong>{{ $globalSetting->asor_namaz_minutes }}m Asor</strong> ·
                        <strong>{{ $globalSetting->johor_namaz_minutes }}m Johor</strong> ·
                        <strong>{{ $globalSetting->maghrib_namaz_minutes }}m Maghrib</strong> ·
                        <strong>{{ $globalSetting->toilet_minutes }}m Toilet</strong>.
                        If a staff member does not return before an allowance ends, time tracking stops automatically.
                    </span>
                </div>

                <div class="field">
                    <label for="break_mode">Break allowance setting</label>
                    <select class="input" id="break_mode" name="break_mode" required>
                        <option value="global" @selected($breakMode === 'global')>Use global break allowances</option>
                        <option value="custom" @selected($breakMode === 'custom')>Custom break allowances for this staff member</option>
                    </select>
                    @error('break_mode')<div class="errors">{{ $message }}</div>@enderror
                </div>

                <div id="break-custom-fields">
                    <div class="form-row">
                        <div class="field">
                            <label for="asor_namaz_minutes">Asor namaz break (minutes)</label>
                            <input class="input" id="asor_namaz_minutes" name="asor_namaz_minutes" type="number" min="1" max="180" value="{{ $breakValues['asor_namaz_minutes'] }}">
                            @error('asor_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="johor_namaz_minutes">Johor namaz break (minutes)</label>
                            <input class="input" id="johor_namaz_minutes" name="johor_namaz_minutes" type="number" min="1" max="180" value="{{ $breakValues['johor_namaz_minutes'] }}">
                            @error('johor_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label for="maghrib_namaz_minutes">Maghrib namaz break (minutes)</label>
                            <input class="input" id="maghrib_namaz_minutes" name="maghrib_namaz_minutes" type="number" min="1" max="180" value="{{ $breakValues['maghrib_namaz_minutes'] }}">
                            @error('maghrib_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="toilet_minutes">Toilet break (minutes)</label>
                            <input class="input" id="toilet_minutes" name="toilet_minutes" type="number" min="1" max="180" value="{{ $breakValues['toilet_minutes'] }}">
                            @error('toilet_minutes')<div class="errors">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                @if($staff->exists)
                    <div class="notice mb-2">
                        <span>◉</span>
                        <span>Changing the password or disabling the account also revokes the desktop API token.</span>
                    </div>
                @endif

                <div class="toolbar">
                    <button class="button" type="submit">{{ $staff->exists ? 'Save changes' : 'Create staff account' }}</button>
                    <a class="button button-secondary" href="{{ route('admin.staff.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const toggle = (selectId, panelId) => {
                const select = document.getElementById(selectId);
                const panel = document.getElementById(panelId);
                if (!select || !panel) return;
                const sync = () => {
                    panel.style.display = select.value === 'custom' ? '' : 'none';
                };
                select.addEventListener('change', sync);
                sync();
            };

            toggle('schedule_mode', 'schedule-custom-fields');
            toggle('inactivity_mode', 'inactivity-custom-fields');
            toggle('break_mode', 'break-custom-fields');

            const body = document.getElementById('office-window-rows');
            const addButton = document.getElementById('add-window');
            if (!body || !addButton) return;

            const renumber = () => {
                [...body.querySelectorAll('tr')].forEach((row, index) => {
                    row.querySelector('td').textContent = 'Window ' + (index + 1);
                    row.querySelectorAll('input').forEach((input) => {
                        const field = input.name.includes('[start]') ? 'start' : 'end';
                        input.name = `windows[${index}][${field}]`;
                    });
                });
            };

            addButton.addEventListener('click', () => {
                const row = document.createElement('tr');
                row.innerHTML = '<td class="strong">Window</td>' +
                    '<td><input class="input input-sm" type="time"></td>' +
                    '<td><input class="input input-sm" type="time"></td>' +
                    '<td class="text-right"><button class="button button-secondary button-sm remove-window" type="button">Remove</button></td>';
                body.appendChild(row);
                renumber();
            });

            body.addEventListener('click', (event) => {
                const button = event.target.closest('.remove-window');
                if (!button) return;
                if (body.querySelectorAll('tr').length <= 1) return;
                button.closest('tr').remove();
                renumber();
            });
        })();
    </script>
@endpush

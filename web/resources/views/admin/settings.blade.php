@extends('layouts.app')

@section('title', 'Office settings')
@section('subtitle', 'Global office hours and tracking rules')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Office time settings</h2>
            <p class="page-description">These hours apply to every staff member unless you set a custom office time for that person.</p>
        </div>
        <a class="button button-secondary" href="{{ route('admin.staff.index') }}">Per-staff office time</a>
    </div>

    <div class="card" style="max-width:860px">
        <div class="card-pad">
            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf
                @method('PUT')

                <h3 class="card-title mb-2">Global office windows</h3>
                <p class="page-description mb-2">Tracking can only start inside these windows. Leave a window blank to disable it. Add as many windows as you need.</p>

                @php
                    $rows = old('windows', $windows);
                    $rows = array_values(is_array($rows) ? $rows : []);
                    while (count($rows) < 3) {
                        $rows[] = ['start' => '', 'end' => ''];
                    }
                @endphp

                <div class="table-wrap mb-2">
                    <table>
                        <thead>
                        <tr><th>Window</th><th>Start time</th><th>End time</th><th></th></tr>
                        </thead>
                        <tbody id="office-window-rows">
                        @foreach($rows as $index => $window)
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
                @error('windows')<div class="errors mb-2">{{ $message }}</div>@enderror

                <div class="divider"></div>
                <h3 class="card-title mb-2">Inactivity handling</h3>
                <p class="page-description mb-2">Both timers are measured from the last keyboard or mouse input. Enter <strong>0</strong> to disable a timer. When both are enabled, the stop timer has priority.</p>

                @php
                    $idlePreview = (int) old('idle_threshold_minutes', $setting->idle_threshold_minutes);
                    $stopPreview = (int) old('stop_after_minutes', $setting->stop_after_minutes);
                @endphp

                <div class="form-row">
                    <div class="field">
                        <label for="idle_threshold_minutes">Idle time (minutes)</label>
                        <input class="input" id="idle_threshold_minutes" name="idle_threshold_minutes" type="number" min="0" max="120" value="{{ old('idle_threshold_minutes', $setting->idle_threshold_minutes) }}">
                        <p class="page-description" style="margin-top:6px">After this many minutes without input, time is recorded as idle. <strong>0 = idle time disabled.</strong></p>
                        @error('idle_threshold_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="stop_after_minutes">Stop time (minutes)</label>
                        <input class="input" id="stop_after_minutes" name="stop_after_minutes" type="number" min="0" max="240" value="{{ old('stop_after_minutes', $setting->stop_after_minutes) }}">
                        <p class="page-description" style="margin-top:6px">After this many minutes without input, tracking stops. <strong>0 = automatic stop disabled.</strong> This timer takes priority over idle.</p>
                        @error('stop_after_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="notice mb-2">
                    <span>◉</span>
                    <span>
                        Current rule:
                        <strong>{{ $idlePreview > 0 ? 'idle after '.$idlePreview.' min' : 'idle disabled' }}</strong>
                        ·
                        <strong>{{ $stopPreview > 0 ? 'stop after '.$stopPreview.' min' : 'auto-stop disabled' }}</strong>.
                        Examples: idle 2 + stop 4 records idle from 2 to 4 minutes and then stops; idle 4 + stop 2 stops at 2 with no idle; idle 2 + stop 2 stops at 2 with no idle.
                    </span>
                </div>

                <div class="field">
                    <label for="screenshot_interval_minutes">Screenshot interval (minutes)</label>
                    <input class="input" id="screenshot_interval_minutes" name="screenshot_interval_minutes" type="number" min="1" max="180" value="{{ old('screenshot_interval_minutes', $setting->screenshot_interval_minutes) }}" required>
                    @error('screenshot_interval_minutes')<div class="errors">{{ $message }}</div>@enderror
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="asor_namaz_minutes">Asor namaz break (minutes)</label>
                        <input class="input" id="asor_namaz_minutes" name="asor_namaz_minutes" type="number" min="1" max="180" value="{{ old('asor_namaz_minutes', $setting->asor_namaz_minutes) }}" required>
                        @error('asor_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="johor_namaz_minutes">Johor namaz break (minutes)</label>
                        <input class="input" id="johor_namaz_minutes" name="johor_namaz_minutes" type="number" min="1" max="180" value="{{ old('johor_namaz_minutes', $setting->johor_namaz_minutes) }}" required>
                        @error('johor_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="maghrib_namaz_minutes">Maghrib namaz break (minutes)</label>
                        <input class="input" id="maghrib_namaz_minutes" name="maghrib_namaz_minutes" type="number" min="1" max="180" value="{{ old('maghrib_namaz_minutes', $setting->maghrib_namaz_minutes) }}" required>
                        @error('maghrib_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="toilet_minutes">Toilet break (minutes)</label>
                        <input class="input" id="toilet_minutes" name="toilet_minutes" type="number" min="1" max="180" value="{{ old('toilet_minutes', $setting->toilet_minutes) }}" required>
                        <p class="page-description" style="margin-top:6px">Global toilet allowance. You can override it for an individual staff member from Staff accounts.</p>
                        @error('toilet_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="notice mb-2">
                    <span>◉</span>
                    <span>If a staff member does not return before any namaz or toilet break allowance ends, time tracking stops automatically.</span>
                </div>

                <div class="field">
                    <label for="total_time_mode">Monthly total-time formula</label>
                    <select class="input" id="total_time_mode" name="total_time_mode" required>
                        @foreach($modes as $value => $label)
                            <option value="{{ $value }}" @selected(old('total_time_mode', $setting->total_time_mode) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="page-description" style="margin-top:6px">This is the default formula used on staff monthly reports. You can also change it when generating a monthly report.</p>
                    @error('total_time_mode')<div class="errors">{{ $message }}</div>@enderror
                </div>

                <div class="notice mb-2">
                    <span>◉</span>
                    <span>Desktop apps pick up this change within about 2 seconds and apply it straight away. Staff with a custom office time keep their own windows.</span>
                </div>

                <div class="toolbar">
                    <button class="button" type="submit">Save office settings</button>
                    <a class="button button-secondary" href="{{ route('admin.reports.monthly') }}">Open monthly report</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
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

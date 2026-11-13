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
                <p class="page-description mb-2">Tracking can only start inside these windows. Leave a window blank to disable it. Up to three windows are supported.</p>

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
                        <tr><th>Window</th><th>Start time</th><th>End time</th></tr>
                        </thead>
                        <tbody>
                        @foreach(array_slice($rows, 0, 3) as $index => $window)
                            <tr>
                                <td class="strong">Window {{ $index + 1 }}</td>
                                <td><input class="input input-sm" type="time" name="windows[{{ $index }}][start]" value="{{ $window['start'] ?? '' }}"></td>
                                <td><input class="input input-sm" type="time" name="windows[{{ $index }}][end]" value="{{ $window['end'] ?? '' }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @error('windows')<div class="errors mb-2">{{ $message }}</div>@enderror

                <div class="form-row">
                    <div class="field">
                        <label for="idle_threshold_minutes">Idle starts after (minutes)</label>
                        <input class="input" id="idle_threshold_minutes" name="idle_threshold_minutes" type="number" min="1" max="120" value="{{ old('idle_threshold_minutes', $setting->idle_threshold_minutes) }}" required>
                        @error('idle_threshold_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="screenshot_interval_minutes">Screenshot interval (minutes)</label>
                        <input class="input" id="screenshot_interval_minutes" name="screenshot_interval_minutes" type="number" min="1" max="180" value="{{ old('screenshot_interval_minutes', $setting->screenshot_interval_minutes) }}" required>
                        @error('screenshot_interval_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="asor_namaz_minutes">Asor namaz break (minutes)</label>
                        <input class="input" id="asor_namaz_minutes" name="asor_namaz_minutes" type="number" min="1" max="180" value="{{ old('asor_namaz_minutes', $setting->asor_namaz_minutes) }}" required>
                        @error('asor_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="maghrib_namaz_minutes">Maghrib namaz break (minutes)</label>
                        <input class="input" id="maghrib_namaz_minutes" name="maghrib_namaz_minutes" type="number" min="1" max="180" value="{{ old('maghrib_namaz_minutes', $setting->maghrib_namaz_minutes) }}" required>
                        @error('maghrib_namaz_minutes')<div class="errors">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-row">
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

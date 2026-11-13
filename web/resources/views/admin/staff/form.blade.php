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
                    $scheduleMode = old('schedule_mode', $staff->office_windows ? 'custom' : 'global');
                    $windowRows = old('windows', $staff->office_windows ?: $globalWindows);
                    $windowRows = array_values(is_array($windowRows) ? $windowRows : []);
                    while (count($windowRows) < 3) {
                        $windowRows[] = ['start' => '', 'end' => ''];
                    }
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

                <p class="page-description mb-2">Custom windows are only used when "Custom office time" is selected. Leave extra rows blank.</p>
                <div class="table-wrap mb-2">
                    <table>
                        <thead>
                        <tr><th>Window</th><th>Start time</th><th>End time</th></tr>
                        </thead>
                        <tbody>
                        @foreach(array_slice($windowRows, 0, 3) as $index => $window)
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

                <div class="divider"></div>
                <h3 class="card-title mb-2">Break allowance</h3>
                <div class="notice mb-2">
                    <span>◉</span>
                    <span>Global toilet break: <strong>{{ \App\Models\OfficeSetting::current()->toilet_minutes }} minutes</strong>. Leave the field below blank to use the global allowance, or set a custom toilet break for this staff member only.</span>
                </div>

                <div class="field">
                    <label for="toilet_minutes">Custom toilet break (minutes)</label>
                    <input class="input" id="toilet_minutes" name="toilet_minutes" type="number" min="1" max="180" value="{{ old('toilet_minutes', $staff->toilet_minutes) }}" placeholder="Use global setting">
                    <p class="page-description" style="margin-top:6px">If this staff member does not return before the allowance ends, time tracking stops automatically.</p>
                    @error('toilet_minutes')<div class="errors">{{ $message }}</div>@enderror
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

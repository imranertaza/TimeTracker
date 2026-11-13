@extends('layouts.app')

@section('title', 'My reports')
@section('subtitle', 'Daily and monthly exact working, idle, namaz and toilet time')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">My time reports</h2>
            <p class="page-description">Your exact working time, idle time, namaz and toilet break time for any day or month.</p>
        </div>
        <div class="toolbar">
            <form class="toolbar" method="GET" action="{{ route('reports.mine') }}">
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                <input class="input input-sm" type="date" name="date" value="{{ $date->format('Y-m-d') }}">
                <button class="button button-secondary button-sm" type="submit">View day</button>
            </form>
            <form class="toolbar" method="GET" action="{{ route('reports.mine') }}">
                <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
                <input class="input input-sm" type="month" name="month" value="{{ $month->format('Y-m') }}">
                <button class="button button-secondary button-sm" type="submit">View month</button>
            </form>
        </div>
    </div>

    <div class="schedule-strip mb-3">
        <div class="schedule-windows">
            @foreach($windows as $index => $window)
                <span class="window-pill {{ $schedule['is_open'] ? 'open' : '' }}">Window {{ $index + 1 }} · {{ $window['start'] }} – {{ $window['end'] }}</span>
            @endforeach
            @foreach($breaks as $rule)
                <span class="window-pill">{{ $rule['label'] }} · {{ $rule['minutes'] }} min</span>
            @endforeach
        </div>
        <span class="badge {{ $schedule['is_open'] ? 'badge-success' : 'badge-muted' }}">
            {{ $schedule['is_open'] ? 'Tracking allowed now' : 'Office closed' }}
        </span>
    </div>

    <div class="card mb-3">
        <div class="card-head">
            <div>
                <h3 class="card-title">Daily report · {{ $date->format('l, d F Y') }}</h3>
                <div class="card-subtitle">Calculated from every synced desktop minute</div>
            </div>
            <a class="button button-secondary button-sm" href="{{ route('dashboard', ['date' => $date->format('Y-m-d')]) }}">Open timesheet</a>
        </div>
        <div class="card-pad">
            <div class="grid grid-5">
                <div class="card stat-card tone-green">
                    <div class="stat-label">Exact working</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($summary['active_seconds']) }}</div>
                    <div class="stat-note">Keyboard / mouse active time</div>
                </div>
                <div class="card stat-card tone-orange">
                    <div class="stat-label">Idle</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($summary['idle_seconds']) }}</div>
                    <div class="stat-note">No input for over 5 minutes</div>
                </div>
                <div class="card stat-card tone-purple">
                    <div class="stat-label">Namaz break</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($summary['namaz']['total']) }}</div>
                    <div class="stat-note">Asor + Maghrib</div>
                </div>
                <div class="card stat-card tone-blue">
                    <div class="stat-label">Toilet break</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($summary['toilet_seconds']) }}</div>
                    <div class="stat-note">Today total</div>
                </div>
                <div class="card stat-card tone-blue">
                    <div class="stat-label">Tracked</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($summary['tracked_seconds']) }}</div>
                    <div class="stat-note">Exact working + idle</div>
                </div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Started</th><th>Ended</th><th>Elapsed</th><th>Status</th><th>Exact working</th><th>Idle</th><th>Breaks</th><th>Shots</th><th></th></tr></thead>
                <tbody>
                @forelse($summary['sessions'] as $session)
                    <tr>
                        <td class="num">{{ \App\Support\Format::timeWithSeconds($session->started_at) }}</td>
                        <td class="num">{{ \App\Support\Format::timeWithSeconds($session->ended_at) }}</td>
                        <td class="num muted">{{ \App\Support\Format::elapsed($session->started_at, $session->ended_at) }}</td>
                        <td><span class="badge {{ \App\Support\Format::statusClass($session->status) }}">{{ \App\Support\Format::statusLabel($session->status) }}</span></td>
                        <td class="num strong">{{ \App\Support\Format::duration($session->active_seconds) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($session->idle_seconds) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($session->break_seconds) }}</td>
                        <td class="num">{{ $session->screenshots_count }}</td>
                        <td class="text-right"><a class="button button-secondary button-sm" href="{{ route('sessions.show', $session) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty">No sessions recorded on this date.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h3 class="card-title">Monthly report · {{ $month->format('F Y') }}</h3>
                <div class="card-subtitle">Total formula set by the admin: {{ $modes[$mode] }}</div>
            </div>
        </div>
        <div class="card-pad">
            <div class="grid grid-5">
                <div class="card stat-card tone-green">
                    <div class="stat-label">Exact working</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($monthly['active_seconds']) }}</div>
                    <div class="stat-note">Whole month</div>
                </div>
                <div class="card stat-card tone-orange">
                    <div class="stat-label">Idle</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($monthly['idle_seconds']) }}</div>
                    <div class="stat-note">Whole month</div>
                </div>
                <div class="card stat-card tone-purple">
                    <div class="stat-label">Namaz break</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($monthly['namaz']['total']) }}</div>
                    <div class="stat-note">Asor + Maghrib</div>
                </div>
                <div class="card stat-card tone-blue">
                    <div class="stat-label">Toilet break</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($monthly['toilet_seconds']) }}</div>
                    <div class="stat-note">Whole month</div>
                </div>
                <div class="card stat-card tone-blue">
                    <div class="stat-label">Total working time</div>
                    <div class="stat-value">{{ \App\Support\Format::duration($monthly['total_seconds']) }}</div>
                    <div class="stat-note">{{ $modes[$mode] }}</div>
                </div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Exact working</th><th>Idle</th><th>Namaz</th><th>Toilet</th><th>Total</th></tr></thead>
                <tbody>
                @foreach($monthly['days'] as $day)
                    <tr>
                        <td class="num">{{ $day['date']->format('d M Y') }}</td>
                        <td class="num strong">{{ \App\Support\Format::duration($day['active_seconds']) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($day['idle_seconds']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($day['namaz_seconds']) }}</td>
                        <td class="num tone-blue">{{ \App\Support\Format::duration($day['toilet_seconds']) }}</td>
                        <td class="num strong">{{ \App\Support\Format::duration($day['total_seconds']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

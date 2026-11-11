@extends('layouts.app')

@section('title', 'Admin dashboard')
@section('subtitle', $date->format('l, d F Y') . ' · Asia/Dhaka')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Office overview</h2>
            <p class="page-description">Live attendance, productivity and namaz break totals for the selected day.</p>
        </div>
        <form class="toolbar" method="GET" action="{{ route('dashboard') }}">
            <input class="input input-sm" type="date" name="date" value="{{ $date->format('Y-m-d') }}">
            <button class="button button-secondary button-sm" type="submit">View day</button>
        </form>
    </div>

    <div id="schedule" class="schedule-strip">
        <div class="schedule-windows">
            @foreach(\App\Support\WorkSchedule::windows() as $index => $window)
                <span class="window-pill {{ $schedule['is_open'] ? 'open' : '' }}">Work window {{ $index + 1 }} · {{ $window['start'] }} – {{ $window['end'] }}</span>
            @endforeach
            @foreach(\App\Support\WorkSchedule::breakRules() as $rule)
                <span class="window-pill">{{ $rule['label'] }} · {{ $rule['minutes'] }} min</span>
            @endforeach
        </div>
        <span class="badge {{ $schedule['is_open'] ? 'badge-success' : 'badge-muted' }}">
            {{ $schedule['is_open'] ? 'Tracking allowed now' : 'Next start: '.\App\Support\WorkSchedule::local($schedule['next_start'])->format('d M, h:i A') }}
        </span>
    </div>

    <div class="grid grid-5 mb-3">
        <div class="card stat-card tone-green">
            <div class="stat-label">Staff online</div>
            <div class="stat-value">{{ $totals['online'] }} <span class="small muted">/ {{ $totals['staff'] }}</span></div>
            <div class="stat-note">Active tracker heartbeat in last 3 min</div>
        </div>
        <div class="card stat-card tone-green">
            <div class="stat-label">Exact working time</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['active_seconds']) }}</div>
            <div class="stat-note">Keyboard / mouse activity</div>
        </div>
        <div class="card stat-card tone-orange">
            <div class="stat-label">Idle time</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['idle_seconds']) }}</div>
            <div class="stat-note">After 5 minutes without input</div>
        </div>
        <div class="card stat-card tone-purple">
            <div class="stat-label">Namaz breaks</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['namaz_seconds']) }}</div>
            <div class="stat-note">Asor + Maghrib combined</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Screenshots</div>
            <div class="stat-value">{{ $totals['screenshots'] }}</div>
            <div class="stat-note">Random captures, every 10 min</div>
        </div>
    </div>

    <div class="card mb-3">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Staff timesheet</h3>
                    <div class="card-subtitle">{{ $team->count() }} active staff · click a row to inspect sessions</div>
                </div>
                <div class="toolbar">
                    <a class="button button-secondary button-sm" href="{{ route('admin.reports.index', ['date' => $date->format('Y-m-d')]) }}">Daily report</a>
                    <a class="button button-secondary button-sm" href="{{ route('admin.reports.monthly', ['month' => $date->format('Y-m')]) }}">Monthly report</a>
                </div>
            </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Staff member</th>
                    <th>Status</th>
                    <th>Exact working</th>
                    <th>Idle</th>
                    <th>Namaz</th>
                    <th>Keys / clicks</th>
                    <th>Shots</th>
                    <th>Last heartbeat</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($team as $row)
                    @php($lastSession = $row['sessions']->last())
                    <tr>
                        <td>
                            <div class="person">
                                <span class="avatar avatar-sm">{{ strtoupper(substr($row['user']->name, 0, 2)) }}</span>
                                <div>
                                    <div class="person-name">{{ $row['user']->name }}</div>
                                    <div class="person-meta">{{ $row['user']->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ \App\Support\Format::statusClass($row['status']) }}">{{ \App\Support\Format::statusLabel($row['status']) }}</span>
                        </td>
                        <td class="num strong">{{ \App\Support\Format::duration($row['active_seconds']) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($row['idle_seconds']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($row['namaz']['total']) }}</td>
                        <td class="num small"><span class="strong">{{ number_format($row['keystrokes']) }}</span> / {{ number_format($row['clicks']) }}</td>
                        <td class="num">{{ $row['screenshots'] }}</td>
                        <td class="small muted">{{ \App\Support\Format::dateTime($row['last_seen_at']) }}</td>
                        <td class="text-right">
                            @if($lastSession)
                                <a class="button button-secondary button-sm" href="{{ route('sessions.show', $lastSession) }}">Details</a>
                            @else
                                <span class="tiny muted">No session</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty">No active staff accounts yet.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-main">
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Recent screenshots</h3>
                    <div class="card-subtitle">Random captures from the desktop tracker</div>
                </div>
                <a class="small" href="{{ route('admin.reports.index') }}">View reports</a>
            </div>
            <div class="card-pad">
                @if($recentScreenshots->isEmpty())
                    <div class="empty"><div class="empty-icon">▧</div>No screenshots uploaded yet.</div>
                @else
                    <div class="gallery">
                        @foreach($recentScreenshots as $shot)
                            <a class="shot" href="{{ route('screenshots.show', $shot) }}" target="_blank" rel="noopener">
                                <img src="{{ route('screenshots.show', $shot) }}" alt="Screenshot by {{ $shot->user->name }}" loading="lazy">
                                <span class="shot-meta">{{ $shot->user->name }} · {{ \App\Support\Format::time($shot->taken_at) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Day summary</h3>
                    <div class="card-subtitle">{{ $date->format('d M Y') }}</div>
                </div>
            </div>
            <div class="card-pad">
                <div class="detail-list">
                    <div class="detail-row"><span class="detail-label">Total tracked</span><span class="detail-value">{{ \App\Support\Format::duration($totals['tracked_seconds']) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Active time</span><span class="detail-value tone-green">{{ \App\Support\Format::duration($totals['active_seconds']) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Idle time</span><span class="detail-value tone-orange">{{ \App\Support\Format::duration($totals['idle_seconds']) }}</span></div>
                    <div class="detail-row"><span class="detail-label">All breaks</span><span class="detail-value tone-purple">{{ \App\Support\Format::duration($totals['break_seconds']) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Keyboard presses</span><span class="detail-value">{{ number_format($totals['keystrokes']) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Mouse clicks</span><span class="detail-value">{{ number_format($totals['clicks']) }}</span></div>
                </div>
                <div class="notice mt-2">
                    <span>◉</span>
                    <span>Desktop clients sync automatically through the secured API. Idle starts after five minutes without keyboard or mouse input.</span>
                </div>
            </div>
        </div>
    </div>
@endsection

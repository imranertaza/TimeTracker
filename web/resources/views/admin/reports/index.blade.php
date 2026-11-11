@extends('layouts.app')

@section('title', 'Daily reports')
@section('subtitle', $date->format('l, d F Y') . ' · Bangladesh time')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Daily time report</h2>
            <p class="page-description">Active, idle and namaz totals calculated from every synced desktop minute.</p>
        </div>
        <div class="toolbar">
            <a class="button button-secondary" href="{{ route('admin.reports.monthly', ['month' => $date->format('Y-m')]) }}">Monthly report</a>
            <a class="button button-secondary" href="{{ route('admin.reports.export', request()->query()) }}">Export CSV</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-pad">
            <form class="toolbar" method="GET" action="{{ route('admin.reports.index') }}">
                <div>
                    <label class="tiny muted strong" for="date" style="display:block;margin-bottom:5px">Report date</label>
                    <input class="input input-sm" id="date" type="date" name="date" value="{{ $date->format('Y-m-d') }}">
                </div>
                <div>
                    <label class="tiny muted strong" for="user_id" style="display:block;margin-bottom:5px">Staff member</label>
                    <select class="input input-sm" id="user_id" name="user_id">
                        <option value="">All staff</option>
                        @foreach($staff as $member)
                            <option value="{{ $member->id }}" @selected($selectedUser?->id === $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="align-self:flex-end">
                    <button class="button button-sm" type="submit">Generate report</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-5 mb-3">
        <div class="card stat-card tone-green">
            <div class="stat-label">Exact working</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['active_seconds']) }}</div>
            <div class="stat-note">Productive keyboard / mouse time</div>
        </div>
        <div class="card stat-card tone-orange">
            <div class="stat-label">Idle</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['idle_seconds']) }}</div>
            <div class="stat-note">No input for over 5 minutes</div>
        </div>
        <div class="card stat-card tone-purple">
            <div class="stat-label">Namaz total</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['namaz_seconds']) }}</div>
            <div class="stat-note">Asor + Maghrib</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Keyboard / mouse</div>
            <div class="stat-value">{{ number_format($totals['keystrokes']) }}</div>
            <div class="stat-note">{{ number_format($totals['clicks']) }} mouse clicks</div>
        </div>
        <div class="card stat-card tone-gray">
            <div class="stat-label">Screenshots</div>
            <div class="stat-value">{{ $totals['screenshots'] }}</div>
            <div class="stat-note">Random captures</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-head">
            <div>
                <h3 class="card-title">{{ $selectedUser ? $selectedUser->name : 'All staff' }} · {{ $date->format('d M Y') }}</h3>
                <div class="card-subtitle">{{ $team->count() }} row(s) · times shown in Dhaka time</div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Staff</th>
                    <th>Status</th>
                    <th>Exact working</th>
                    <th>Idle</th>
                    <th>Tracked</th>
                    <th>Asor</th>
                    <th>Maghrib</th>
                    <th>Namaz total</th>
                    <th>Keys / clicks</th>
                    <th>Shots</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($team as $row)
                    <tr>
                        <td>
                            <div class="person">
                                <span class="avatar avatar-sm">{{ strtoupper(substr($row['user']->name, 0, 2)) }}</span>
                                <div><div class="person-name">{{ $row['user']->name }}</div><div class="person-meta">{{ $row['user']->email }}</div></div>
                            </div>
                        </td>
                        <td><span class="badge {{ \App\Support\Format::statusClass($row['status']) }}">{{ \App\Support\Format::statusLabel($row['status']) }}</span></td>
                        <td class="num strong">{{ \App\Support\Format::duration($row['active_seconds']) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($row['idle_seconds']) }}</td>
                        <td class="num">{{ \App\Support\Format::duration($row['tracked_seconds']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($row['namaz']['asor']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($row['namaz']['maghrib']) }}</td>
                        <td class="num strong">{{ \App\Support\Format::duration($row['namaz']['total']) }}</td>
                        <td class="num small">{{ number_format($row['keystrokes']) }} / {{ number_format($row['clicks']) }}</td>
                        <td class="num">{{ $row['screenshots'] }}</td>
                        <td class="text-right">
                            <a class="button button-secondary button-sm" href="{{ route('admin.reports.index', ['date' => $date->format('Y-m-d'), 'user_id' => $row['user']->id]) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="empty">No staff data for this date.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($selectedUser && $summary)
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Session breakdown · {{ $selectedUser->name }}</h3>
                    <div class="card-subtitle">Every desktop tracking session for this date</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Started</th><th>Ended</th><th>Status</th><th>Exact working</th><th>Idle</th><th>Break</th><th>Shots</th><th></th></tr></thead>
                    <tbody>
                    @forelse($summary['sessions'] as $session)
                        <tr>
                            <td class="num">{{ \App\Support\Format::time($session->started_at) }}</td>
                            <td class="num">{{ \App\Support\Format::time($session->ended_at) }}</td>
                            <td><span class="badge {{ \App\Support\Format::statusClass($session->status) }}">{{ \App\Support\Format::statusLabel($session->status) }}</span></td>
                            <td class="num">{{ \App\Support\Format::duration($session->active_seconds) }}</td>
                            <td class="num tone-orange">{{ \App\Support\Format::duration($session->idle_seconds) }}</td>
                            <td class="num tone-purple">{{ \App\Support\Format::duration($session->break_seconds) }}</td>
                            <td class="num">{{ $session->screenshots_count }}</td>
                            <td class="text-right"><a class="button button-secondary button-sm" href="{{ route('sessions.show', $session) }}">Details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">No sessions recorded.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

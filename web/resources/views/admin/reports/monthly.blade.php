@extends('layouts.app')

@section('title', 'Monthly reports')
@section('subtitle', $month->format('F Y') . ' · Bangladesh time')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Monthly time report</h2>
            <p class="page-description">Exact working, idle, namaz and toilet break time for every staff member. Choose the formula used to generate the monthly total.</p>
        </div>
        <div class="toolbar">
            <a class="button button-secondary" href="{{ route('admin.reports.index', ['date' => $month->format('Y-m-d')]) }}">Daily report</a>
            <a class="button button-secondary" href="{{ route('admin.reports.monthly.export', request()->query()) }}">Export CSV</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-pad">
            <form class="toolbar" method="GET" action="{{ route('admin.reports.monthly') }}">
                <div>
                    <label class="tiny muted strong" for="month" style="display:block;margin-bottom:5px">Report month</label>
                    <input class="input input-sm" id="month" type="month" name="month" value="{{ $month->format('Y-m') }}">
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
                <div>
                    <label class="tiny muted strong" for="mode" style="display:block;margin-bottom:5px">Total time formula</label>
                    <select class="input input-sm" id="mode" name="mode">
                        @foreach($modes as $value => $label)
                            <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="align-self:flex-end">
                    <button class="button button-sm" type="submit">Generate monthly report</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-6 mb-3">
        <div class="card stat-card tone-green">
            <div class="stat-label">Exact working</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['active_seconds']) }}</div>
            <div class="stat-note">Keyboard / mouse active time</div>
        </div>
        <div class="card stat-card tone-orange">
            <div class="stat-label">Idle</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['idle_seconds']) }}</div>
            <div class="stat-note">After 5 minutes without input</div>
        </div>
        <div class="card stat-card tone-purple">
            <div class="stat-label">Namaz total</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['namaz_seconds']) }}</div>
            <div class="stat-note">Asor + Maghrib</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Toilet total</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['toilet_seconds']) }}</div>
            <div class="stat-note">All staff this month</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Generated total</div>
            <div class="stat-value">{{ \App\Support\Format::duration($totals['total_seconds']) }}</div>
            <div class="stat-note">{{ $modes[$mode] }}</div>
        </div>
        <div class="card stat-card tone-gray">
            <div class="stat-label">Screenshots</div>
            <div class="stat-value">{{ $totals['screenshots'] }}</div>
            <div class="stat-note">{{ $totals['staff'] }} staff member(s)</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-head">
            <div>
                <h3 class="card-title">{{ $selectedUser ? $selectedUser->name : 'All staff' }} · {{ $month->format('F Y') }}</h3>
                <div class="card-subtitle">Total formula: {{ $modes[$mode] }}</div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Staff</th>
                    <th>Exact working</th>
                    <th>Idle</th>
                    <th>Asor</th>
                    <th>Maghrib</th>
                    <th>Namaz total</th>
                    <th>Toilet</th>
                    <th>Total ({{ $modes[$mode] }})</th>
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
                        <td class="num strong">{{ \App\Support\Format::duration($row['active_seconds']) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($row['idle_seconds']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($row['namaz']['asor']) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($row['namaz']['maghrib']) }}</td>
                        <td class="num">{{ \App\Support\Format::duration($row['namaz']['total']) }}</td>
                        <td class="num tone-blue">{{ \App\Support\Format::duration($row['toilet_seconds']) }}</td>
                        <td class="num strong">{{ \App\Support\Format::duration($row['total_seconds']) }}</td>
                        <td class="num small">{{ number_format($row['keystrokes']) }} / {{ number_format($row['clicks']) }}</td>
                        <td class="num">{{ $row['screenshots'] }}</td>
                        <td class="text-right">
                            <a class="button button-secondary button-sm" href="{{ route('admin.reports.monthly', ['month' => $month->format('Y-m'), 'user_id' => $row['user']->id, 'mode' => $mode]) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="empty">No staff accounts yet.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($selectedUser && $summary)
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Daily breakdown · {{ $selectedUser->name }}</h3>
                    <div class="card-subtitle">{{ $month->format('F Y') }} · {{ $modes[$mode] }}</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Exact working</th><th>Idle</th><th>Namaz</th><th>Toilet</th><th>Total</th></tr></thead>
                    <tbody>
                    @foreach($summary['days'] as $day)
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
    @endif
@endsection

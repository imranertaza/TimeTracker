@extends('layouts.app')

@section('title', 'My timesheet')
@section('subtitle', $summary['date']->format('l, d F Y') . ' · Asia/Dhaka')

@section('content')
    @php
        $maxWeek = max(1, collect($week)->max(fn ($day) => $day['active_seconds'] + $day['idle_seconds']));
        $status = $summary['status'];
    @endphp

    <div class="page-head">
        <div>
            <h2 class="page-title">Hello, {{ auth()->user()->name }}</h2>
            <p class="page-description">Your live work summary and daily activity history.</p>
        </div>
        <form class="toolbar" method="GET" action="{{ route('dashboard') }}">
            <input class="input input-sm" type="date" name="date" value="{{ $summary['date']->format('Y-m-d') }}">
            <button class="button button-secondary button-sm" type="submit">View day</button>
            <a class="button button-secondary button-sm" href="{{ route('reports.mine', ['date' => $summary['date']->format('Y-m-d'), 'month' => $summary['date']->format('Y-m')]) }}">My reports</a>
        </form>
    </div>

    <section class="hero mb-3">
        <div class="hero-kicker">Today · Dhaka time</div>
        <h3 class="hero-title">{{ \App\Support\Format::statusLabel($status) }}</h3>
        <p class="hero-subtitle">
            @if($status === 'running')
                The desktop tracker is running. Keep it open during your shift.
            @elseif($status === 'paused')
                You are currently on a namaz break.
            @else
                Start tracking from the desktop app inside an allowed work window.
            @endif
        </p>
        <div class="hero-stats">
            <div>
                <div class="hero-stat-label">Exact working</div>
                <div class="hero-stat-value">{{ \App\Support\Format::duration($summary['active_seconds'], true) }}</div>
            </div>
            <div>
                <div class="hero-stat-label">Idle</div>
                <div class="hero-stat-value">{{ \App\Support\Format::duration($summary['idle_seconds'], true) }}</div>
            </div>
            <div>
                <div class="hero-stat-label">Namaz</div>
                <div class="hero-stat-value">{{ \App\Support\Format::duration($summary['namaz']['total'], true) }}</div>
            </div>
            <div>
                <div class="hero-stat-label">Tracked</div>
                <div class="hero-stat-value">{{ \App\Support\Format::duration($summary['tracked_seconds'], true) }}</div>
            </div>
        </div>
    </section>

    <div class="grid grid-4 mb-3">
        <div class="card stat-card tone-green">
            <div class="stat-label">Keyboard presses</div>
            <div class="stat-value">{{ number_format($summary['keystrokes']) }}</div>
            <div class="stat-note">Captured in active minutes</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Mouse clicks</div>
            <div class="stat-value">{{ number_format($summary['clicks']) }}</div>
            <div class="stat-note">Counted without storing content</div>
        </div>
        <div class="card stat-card tone-purple">
            <div class="stat-label">Asor break</div>
            <div class="stat-value">{{ \App\Support\Format::duration($summary['namaz']['asor']) }}</div>
            <div class="stat-note">{{ \App\Support\WorkSchedule::breakRules(auth()->user())['asor']['minutes'] }} minute allowance</div>
        </div>
        <div class="card stat-card tone-orange">
            <div class="stat-label">Maghrib break</div>
            <div class="stat-value">{{ \App\Support\Format::duration($summary['namaz']['maghrib']) }}</div>
            <div class="stat-note">{{ \App\Support\WorkSchedule::breakRules(auth()->user())['maghrib']['minutes'] }} minute allowance</div>
        </div>
    </div>

    <div class="grid grid-main mb-3">
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Last 7 days</h3>
                    <div class="card-subtitle">Active vs idle time</div>
                </div>
                <div class="toolbar small muted">
                    <span><span class="badge badge-success">Active</span></span>
                    <span><span class="badge badge-warning">Idle</span></span>
                </div>
            </div>
            <div class="card-pad">
                <div class="bars">
                    @foreach($week as $day)
                        @php
                            $activeHeight = ($day['active_seconds'] / $maxWeek) * 100;
                            $idleHeight = ($day['idle_seconds'] / $maxWeek) * 100;
                        @endphp
                        <div class="bar-col" title="{{ $day['date']->format('d M') }}: {{ \App\Support\Format::duration($day['active_seconds']) }} active, {{ \App\Support\Format::duration($day['idle_seconds']) }} idle">
                            <div class="bar-stack">
                                <div class="bar" style="height: {{ max(1, $activeHeight) }}%"></div>
                                <div class="bar idle" style="height: {{ max(1, $idleHeight) }}%"></div>
                            </div>
                            <div class="bar-label">{{ $day['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card" id="schedule">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Office schedule</h3>
                    <div class="card-subtitle">Tracking is blocked outside these windows</div>
                </div>
            </div>
            <div class="card-pad">
                <div class="timeline">
                    @foreach(\App\Support\WorkSchedule::windows(auth()->user()) as $index => $window)
                        <div class="timeline-item">
                            <span class="timeline-dot" @if($index > 0) style="background:#e99b32" @endif></span>
                            <div>
                                <div class="timeline-title">{{ $index === 0 ? 'Morning window' : 'Office window '.($index + 1) }}</div>
                                <div class="timeline-meta">{{ $window['start'] }} – {{ $window['end'] }}</div>
                            </div>
                        </div>
                    @endforeach
                    @foreach(\App\Support\WorkSchedule::breakRules(auth()->user()) as $rule)
                        <div class="timeline-item">
                            <span class="timeline-dot" style="background:#8a6cc0"></span>
                            <div><div class="timeline-title">{{ $rule['label'] }}</div><div class="timeline-meta">{{ $rule['minutes'] }} minute break allowance</div></div>
                        </div>
                    @endforeach
                </div>
                <div class="divider"></div>
                <div class="notice {{ $schedule['is_open'] ? '' : 'notice-warn' }}">
                    <span>◉</span>
                    <span>
                        @if($schedule['is_open'])
                            Tracking is allowed right now.
                        @else
                            Tracking is closed. Next allowed start:
                            <strong>{{ \App\Support\WorkSchedule::local($schedule['next_start'])->format('d M, h:i A') }}</strong>.
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-head">
            <div>
                <h3 class="card-title">Work sessions</h3>
                <div class="card-subtitle">All desktop sessions recorded on this date</div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Started</th>
                    <th>Ended</th>
                    <th>Status</th>
                    <th>Active</th>
                    <th>Idle</th>
                    <th>Namaz</th>
                    <th>Shots</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($summary['sessions'] as $session)
                    <tr>
                        <td class="num">{{ \App\Support\Format::time($session->started_at) }}</td>
                        <td class="num">{{ \App\Support\Format::time($session->ended_at) }}</td>
                        <td><span class="badge {{ \App\Support\Format::statusClass($session->status) }}">{{ \App\Support\Format::statusLabel($session->status) }}</span></td>
                        <td class="num strong">{{ \App\Support\Format::duration($session->active_seconds) }}</td>
                        <td class="num tone-orange">{{ \App\Support\Format::duration($session->idle_seconds) }}</td>
                        <td class="num tone-purple">{{ \App\Support\Format::duration($session->break_seconds) }}</td>
                        <td class="num">{{ $session->screenshots_count }}</td>
                        <td class="text-right"><a class="button button-secondary button-sm" href="{{ route('sessions.show', $session) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty"><div class="empty-icon">◷</div>No sessions for this date.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Namaz breaks</h3>
                    <div class="card-subtitle">Total {{ \App\Support\Format::duration($summary['namaz']['total']) }} today</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Type</th><th>Started</th><th>Duration</th></tr></thead>
                    <tbody>
                    @forelse($summary['breaks'] as $break)
                        <tr>
                            <td class="strong">{{ $break->label }}</td>
                            <td class="num">{{ \App\Support\Format::time($break->started_at) }}</td>
                            <td class="num">{{ \App\Support\Format::duration($break->duration_seconds) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><div class="empty">No namaz breaks recorded.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">My screenshots</h3>
                    <div class="card-subtitle">Latest random captures</div>
                </div>
            </div>
            <div class="card-pad">
                @if($screenshots->isEmpty())
                    <div class="empty"><div class="empty-icon">▧</div>No screenshots yet.</div>
                @else
                    <div class="gallery">
                        @foreach($screenshots as $shot)
                            <a class="shot" href="{{ route('screenshots.show', $shot) }}" target="_blank" rel="noopener">
                                <img src="{{ route('screenshots.show', $shot) }}" alt="Work screenshot" loading="lazy">
                                <span class="shot-meta">{{ \App\Support\Format::time($shot->taken_at) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

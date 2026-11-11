@extends('layouts.app')

@section('title', 'Session details')
@section('subtitle', $session->user->name . ' · ' . \App\Support\Format::dateTime($session->started_at))

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Session breakdown</h2>
            <p class="page-description">
                {{ $session->user->name }} · {{ \App\Support\Format::dateTimeWithSeconds($session->started_at) }}
                @if($session->ended_at) to {{ \App\Support\Format::timeWithSeconds($session->ended_at) }} @endif
            </p>
        </div>
        <a class="button button-secondary" href="{{ url()->previous() }}">Back</a>
    </div>

    <div class="grid grid-5 mb-3">
        <div class="card stat-card tone-green">
            <div class="stat-label">Active</div>
            <div class="stat-value">{{ \App\Support\Format::duration($session->active_seconds) }}</div>
        </div>
        <div class="card stat-card tone-orange">
            <div class="stat-label">Idle</div>
            <div class="stat-value">{{ \App\Support\Format::duration($session->idle_seconds) }}</div>
        </div>
        <div class="card stat-card tone-purple">
            <div class="stat-label">Namaz break</div>
            <div class="stat-value">{{ \App\Support\Format::duration($session->break_seconds) }}</div>
        </div>
        <div class="card stat-card tone-blue">
            <div class="stat-label">Screenshots</div>
            <div class="stat-value">{{ $screenshots->count() }}</div>
        </div>
        <div class="card stat-card tone-gray">
            <div class="stat-label">Status</div>
            <div class="stat-value" style="font-size:19px;margin-top:12px">
                <span class="badge {{ \App\Support\Format::statusClass($session->status) }}">{{ \App\Support\Format::statusLabel($session->status) }}</span>
            </div>
        </div>
    </div>

    <div class="grid grid-main mb-3">
        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Minute activity</h3>
                    <div class="card-subtitle">Keyboard and mouse counts with active/idle split</div>
                </div>
                <span class="tiny muted">Dhaka time</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Minute</th><th>Keystrokes</th><th>Clicks</th><th>Active</th><th>Idle</th><th>Classification</th></tr></thead>
                    <tbody>
                    @forelse($activity as $minute)
                        <tr>
                            <td class="num">{{ \App\Support\Format::time($minute->minute_start) }}</td>
                            <td class="num">{{ number_format($minute->keystrokes) }}</td>
                            <td class="num">{{ number_format($minute->clicks) }}</td>
                            <td class="num tone-green">{{ $minute->active_seconds }}s</td>
                            <td class="num tone-orange">{{ $minute->idle_seconds }}s</td>
                            <td>
                                @if($minute->is_idle)
                                    <span class="badge badge-warning">Idle</span>
                                @else
                                    <span class="badge badge-success">Active</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">No activity minutes synced.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h3 class="card-title">Session info</h3>
                    <div class="card-subtitle">Device and timing metadata</div>
                </div>
            </div>
            <div class="card-pad">
                <div class="detail-list">
                    <div class="detail-row"><span class="detail-label">Staff</span><span class="detail-value">{{ $session->user->name }}</span></div>
                    <div class="detail-row"><span class="detail-label">Device</span><span class="detail-value">{{ $session->device_name ?: '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Started</span><span class="detail-value">{{ \App\Support\Format::dateTimeWithSeconds($session->started_at) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Ended</span><span class="detail-value">{{ \App\Support\Format::dateTimeWithSeconds($session->ended_at) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Elapsed</span><span class="detail-value">{{ \App\Support\Format::elapsed($session->started_at, $session->ended_at) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Exact working</span><span class="detail-value tone-green">{{ \App\Support\Format::duration($session->active_seconds) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Idle</span><span class="detail-value tone-orange">{{ \App\Support\Format::duration($session->idle_seconds) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Tracked</span><span class="detail-value">{{ \App\Support\Format::duration($session->tracked_seconds) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Session UUID</span><span class="detail-value mono tiny">{{ $session->uuid }}</span></div>
                </div>

                <div class="divider"></div>
                <h4 class="card-title mb-2">Namaz breaks</h4>
                @forelse($breaks as $break)
                    <div class="detail-row">
                        <span class="detail-label">{{ $break->label }} · {{ \App\Support\Format::time($break->started_at) }}</span>
                        <span class="detail-value">{{ \App\Support\Format::duration($break->duration_seconds) }}</span>
                    </div>
                @empty
                    <div class="small muted">No namaz break in this session.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h3 class="card-title">Screenshots</h3>
                <div class="card-subtitle">Random captures taken while this session was running</div>
            </div>
        </div>
        <div class="card-pad">
            @if($screenshots->isEmpty())
                <div class="empty"><div class="empty-icon">▧</div>No screenshots for this session.</div>
            @else
                <div class="gallery">
                    @foreach($screenshots as $shot)
                        <a class="shot" href="{{ route('screenshots.show', $shot) }}" target="_blank" rel="noopener">
                            <img src="{{ route('screenshots.show', $shot) }}" alt="Session screenshot" loading="lazy">
                            <span class="shot-meta">{{ \App\Support\Format::time($shot->taken_at) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection

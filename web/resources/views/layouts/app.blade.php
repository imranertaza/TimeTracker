<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@php
    $user = auth()->user();
    $schedule = \App\Support\WorkSchedule::status(null, $user);
@endphp
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">OT</span>
            <span>
                <span class="brand-name">Office Tracker</span><br>
                <span class="brand-sub">Dhaka · Bangladesh</span>
            </span>
        </a>

        <div class="nav-label">Workspace</div>
        <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <span class="nav-icon">◫</span><span class="nav-text">Dashboard</span>
        </a>

        @if($user->isAdmin())
            <div class="nav-label">Administration</div>
            <a class="nav-link {{ request()->routeIs('admin.reports.index') ? 'active' : '' }}" href="{{ route('admin.reports.index') }}">
                <span class="nav-icon">▤</span><span class="nav-text">Daily reports</span>
            </a>
            <a class="nav-link {{ request()->routeIs('admin.reports.monthly') ? 'active' : '' }}" href="{{ route('admin.reports.monthly') }}">
                <span class="nav-icon">▦</span><span class="nav-text">Monthly reports</span>
            </a>
            <a class="nav-link {{ request()->routeIs('admin.staff.*') ? 'active' : '' }}" href="{{ route('admin.staff.index') }}">
                <span class="nav-icon">♟</span><span class="nav-text">Staff accounts</span>
            </a>
            <a class="nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}" href="{{ route('admin.settings.edit') }}">
                <span class="nav-icon">⚙</span><span class="nav-text">Office settings</span>
            </a>
        @else
            <div class="nav-label">My work</div>
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <span class="nav-icon">◷</span><span class="nav-text">My timesheet</span>
            </a>
            <a class="nav-link {{ request()->routeIs('reports.mine') ? 'active' : '' }}" href="{{ route('reports.mine') }}">
                <span class="nav-icon">▦</span><span class="nav-text">My reports</span>
            </a>
        @endif

        <div class="nav-label">Office rules</div>
        <a class="nav-link" href="{{ route('dashboard') }}#schedule">
            <span class="nav-icon">◔</span><span class="nav-text">Work schedule</span>
        </a>

        <div class="sidebar-footer">
            <div class="user-chip">
                <span class="avatar">{{ strtoupper(substr($user->name, 0, 2)) }}</span>
                <span>
                    <span class="user-name">{{ $user->name }}</span><br>
                    <span class="user-role">{{ $user->role }}</span>
                </span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div>
                <h1 class="topbar-title">@yield('title', 'Dashboard')</h1>
                <div class="topbar-subtitle">@yield('subtitle', 'Bangladesh Standard Time')</div>
            </div>
            <div class="topbar-right">
                <div class="clock-box">
                    <div class="clock-time" id="live-clock">{{ \App\Support\WorkSchedule::now()->format('h:i:s A') }}</div>
                    <div class="clock-zone">Asia/Dhaka</div>
                </div>
                <span class="badge {{ $schedule['is_open'] ? 'badge-success' : 'badge-muted' }}">
                    {{ $schedule['is_open'] ? 'Office open' : 'Office closed' }}
                </span>
            </div>
        </header>

        <main class="content">
            @if(session('success'))
                <div class="flash flash-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="flash flash-error">{{ session('error') }}</div>
            @endif
            @if($errors->any() && ! request()->routeIs('login'))
                <div class="flash flash-error">
                    Please correct the highlighted fields and try again.
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    (function () {
        const clock = document.getElementById('live-clock');
        if (!clock) return;
        const tick = () => {
            clock.textContent = new Intl.DateTimeFormat('en-US', {
                timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: true
            }).format(new Date());
        };
        tick();
        setInterval(tick, 1000);
    })();
</script>
@stack('scripts')
</body>
</html>

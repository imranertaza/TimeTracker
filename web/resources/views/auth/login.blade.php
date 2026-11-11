@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
<div class="login-wrap">
    <section class="login-story">
        <div>
            <div class="brand">
                <span class="brand-mark">OT</span>
                <span>
                    <span class="brand-name" style="color:#fff">Office Tracker</span><br>
                    <span class="brand-sub">Workforce control centre</span>
                </span>
            </div>

            <h1 class="login-headline">Every working minute, accounted for.</h1>
            <p class="login-copy">
                A complete office time-tracking system for Bangladesh. The desktop app captures activity,
                idle time, prayer breaks and screenshots — this panel turns it into clear daily reports.
            </p>

            <div class="feature-list">
                <div class="feature"><span class="feature-dot"></span> Office-hour enforcement (9:00 AM – 6:30 PM)</div>
                <div class="feature"><span class="feature-dot"></span> Keyboard, mouse and idle-time monitoring</div>
                <div class="feature"><span class="feature-dot"></span> Random screenshots every 10 minutes</div>
                <div class="feature"><span class="feature-dot"></span> Asor and Maghrib namaz break reporting</div>
                <div class="feature"><span class="feature-dot"></span> Admin and staff views with API sync</div>
            </div>
        </div>
        <div class="login-footer">All timestamps use Asia/Dhaka (UTC+6).</div>
    </section>

    <section class="login-panel">
        <h2 class="login-title">Welcome back</h2>
        <p class="login-subtitle">Sign in to the web control panel.</p>

        @if($errors->any())
            <div class="flash flash-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <div class="field check">
                <input id="remember" name="remember" type="checkbox" value="1">
                <label for="remember" style="margin:0;font-weight:500">Keep me signed in</label>
            </div>
            <button class="button w-full" type="submit">Sign in to dashboard</button>
        </form>

        <div class="demo-box">
            <strong>Demo accounts</strong><br>
            Admin: admin@officetracker.test / Admin@12345<br>
            Staff: staff@officetracker.test / Staff@12345
        </div>
    </section>
</div>
@endsection

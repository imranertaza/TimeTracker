@extends('layouts.app')

@section('title', 'Staff accounts')
@section('subtitle', 'Create, edit and deactivate desktop tracker accounts')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="page-title">Staff accounts</h2>
            <p class="page-description">Each staff member signs in to the desktop app with these credentials.</p>
        </div>
        <a class="button" href="{{ route('admin.staff.create') }}">+ Add staff</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h3 class="card-title">{{ $staff->total() }} staff members</h3>
                <div class="card-subtitle">Inactive accounts cannot log in or sync data.</div>
            </div>
            <form class="toolbar" method="GET" action="{{ route('admin.staff.index') }}">
                <input class="input input-sm" type="search" name="search" value="{{ $search }}" placeholder="Search name or email">
                <button class="button button-secondary button-sm" type="submit">Search</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Office time</th>
                    <th>Status</th>
                    <th>Last heartbeat</th>
                    <th>Created</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($staff as $member)
                    <tr>
                        <td>
                            <div class="person">
                                <span class="avatar avatar-sm">{{ strtoupper(substr($member->name, 0, 2)) }}</span>
                                <div><div class="person-name">{{ $member->name }}</div><div class="person-meta">Staff</div></div>
                            </div>
                        </td>
                        <td>{{ $member->email }}</td>
                        <td class="small">
                            <div class="strong">{{ \App\Support\WorkSchedule::describeWindows($member) }}</div>
                            <span class="badge {{ $member->office_windows ? 'badge-warning' : 'badge-muted' }}">{{ $member->office_windows ? 'Custom' : 'Global' }}</span>
                        </td>
                        <td><span class="badge {{ $member->is_active ? 'badge-success' : 'badge-danger' }}">{{ $member->is_active ? 'Active' : 'Disabled' }}</span></td>
                        <td class="small muted">{{ \App\Support\Format::dateTime($member->last_seen_at) }}</td>
                        <td class="small muted">{{ \App\Support\Format::day($member->created_at) }}</td>
                        <td class="text-right">
                            <a class="button button-secondary button-sm" href="{{ route('admin.staff.edit', $member) }}">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty">No staff found.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($staff->hasPages())
            <div class="card-pad">
                {{ $staff->links() }}
            </div>
        @endif
    </div>
@endsection

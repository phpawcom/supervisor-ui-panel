@extends('whm.layout')
@section('title', 'Settings — Supervisor Manager WHM')

@section('content')

{{-- ── Statistics ──────────────────────────────────────────────────── --}}
<div class="whm-stat-grid">
    <div class="whm-stat-box"><div class="n">{{ $stats['total_workers'] }}</div><div class="l">Total Workers</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['active_workers'] }}</div><div class="l">Running</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['reverb_workers'] }}</div><div class="l">Reverb Workers</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['total_apps'] }}</div><div class="l">Managed Apps</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['total_users'] }}</div><div class="l">Active Users</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['ports_used'] }}</div><div class="l">Ports Used</div></div>
    <div class="whm-stat-box"><div class="n">{{ $stats['ports_free'] }}</div><div class="l">Ports Free</div></div>
</div>

{{-- ── Port Range Info ─────────────────────────────────────────────── --}}
<div class="whm-panel" style="margin-bottom:16px;">
    <div class="whm-panel-head">
        Port Allocation Range
        <span style="font-weight:normal; font-size:.85em;">
            {{ $port_usage['range_start'] }} – {{ $port_usage['range_end'] }}
            ({{ $port_usage['total'] }} total)
        </span>
    </div>
    <div class="whm-panel-body">
        <div style="background:#e8eef4; height:8px; border-radius:4px; overflow:hidden; margin-bottom:8px;">
            @php $pct = $port_usage['total'] > 0 ? ($port_usage['used'] / $port_usage['total']) * 100 : 0; @endphp
            <div style="height:100%; width:{{ round($pct) }}%; background:{{ $pct > 80 ? '#dc3545' : '#0064a3' }}; border-radius:4px;"></div>
        </div>
        <small>{{ $port_usage['used'] }} of {{ $port_usage['total'] }} ports allocated ({{ round($pct, 1) }}%)</small>
        &nbsp;·&nbsp;
        <a href="{{ route('whm.ports.index') }}">View port details →</a>
    </div>
</div>

{{-- ── Global Settings Form ────────────────────────────────────────── --}}
<div class="whm-panel">
    <div class="whm-panel-head">Global Plugin Settings</div>
    <div class="whm-panel-body">
        <form id="global-settings-form">
            @csrf
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td style="padding:8px 0; width:220px; vertical-align:top; padding-right:16px;">
                        <label><strong>Reverb Port Range Start</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Must be below End</p>
                    </td>
                    <td style="padding:8px 0;">
                        <input type="number" name="reverb_port_start" class="form-control"
                               value="{{ config('supervisor_plugin.reverb.port_range_start') }}"
                               min="10000" max="60000" style="width:140px;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0; vertical-align:top; padding-right:16px;">
                        <label><strong>Reverb Port Range End</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Must be above Start</p>
                    </td>
                    <td style="padding:8px 0;">
                        <input type="number" name="reverb_port_end" class="form-control"
                               value="{{ config('supervisor_plugin.reverb.port_range_end') }}"
                               min="10001" max="60001" style="width:140px;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0; vertical-align:top; padding-right:16px;">
                        <label><strong>SSL Mode</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Controls ws:// vs wss:// selection</p>
                    </td>
                    <td style="padding:8px 0;">
                        <select name="reverb_ssl_mode" class="form-control" style="width:200px;">
                            @foreach (['auto' => 'Auto-detect SSL', 'force_https' => 'Force WSS (HTTPS)', 'force_http' => 'Force WS (HTTP)'] as $val => $label)
                                <option value="{{ $val }}" {{ config('supervisor_plugin.reverb.ssl_mode') === $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0; vertical-align:top; padding-right:16px;">
                        <label><strong>Reserved Ports</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Comma-separated ports to exclude</p>
                    </td>
                    <td style="padding:8px 0;">
                        <input type="text" name="reverb_reserved_ports" class="form-control"
                               value="{{ implode(',', config('supervisor_plugin.reverb.reserved_ports', [])) }}"
                               placeholder="20010,20020" style="width:300px;">
                    </td>
                </tr>
            </table>
            <div style="margin-top:12px;">
                <button type="submit" style="background:#0064a3; color:#fff; border:none; padding:7px 18px; border-radius:3px; cursor:pointer;">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ── Workers by User ─────────────────────────────────────────────── --}}
<div class="whm-panel">
    <div class="whm-panel-head">All Workers by User</div>
    <div class="whm-panel-body" style="padding:0;">
        @if ($workers->isEmpty())
            <div style="padding:16px; color:#666;">No workers configured on this server.</div>
        @else
        <table class="whm-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Worker</th>
                    <th>Type</th>
                    <th>App Path</th>
                    <th>State</th>
                    <th>Port</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workers as $user => $userWorkers)
                    @foreach ($userWorkers as $w)
                    <tr>
                        @if ($loop->first)
                        <td rowspan="{{ $userWorkers->count() }}" style="background:#f0f4f8; font-weight:600; vertical-align:top; border-right:2px solid #c8d8e8;">
                            {{ $user }}
                        </td>
                        @endif
                        <td>{{ $w->worker_name }}</td>
                        <td><span class="whm-badge">{{ strtoupper($w->type) }}</span></td>
                        <td><small>{{ $w->app?->app_path ?? '—' }}</small></td>
                        <td>
                            <span class="whm-badge {{ $w->desired_state === 'running' ? 'whm-badge-on' : 'whm-badge-off' }}">
                                {{ strtoupper($w->desired_state) }}
                            </span>
                        </td>
                        <td>{{ $w->assignedPort?->port ?? '—' }}</td>
                        <td><small>{{ $w->created_at?->toDateString() }}</small></td>
                    </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

@if ($lve_available)
<div class="whm-panel">
    <div class="whm-panel-head">CloudLinux LVE — Active</div>
    <div class="whm-panel-body">
        <div class="whm-alert whm-alert-info" style="margin:0;">
            CloudLinux LVE integration is enabled on this server. Per-user resource limits are being enforced
            and displayed to users in the cPanel plugin dashboard.
        </div>
    </div>
</div>
@endif

@endsection

@section('scripts')
<script>
document.getElementById('global-settings-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));

    try {
        const res  = await whmFetch('{{ route("whm.settings.update") }}', {
            method: 'POST', body: JSON.stringify(data),
        });
        const json = await res.json();
        showFlash(json.success ? json.message : (json.error || 'Failed.'), json.success ? 'success' : 'error');
    } catch (err) {
        showFlash('Network error: ' + err.message, 'error');
    }
});
</script>
@endsection

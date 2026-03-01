@extends('whm.layout')
@section('title', 'Package Limits — Supervisor Manager WHM')

@section('content')

<div class="whm-panel">
    <div class="whm-panel-head">
        Package Limits
        <span style="font-size:.8em; font-weight:normal;">Configure supervisor worker limits per hosting package</span>
    </div>
    <div class="whm-panel-body" style="padding:0;">
        <table class="whm-table">
            <thead>
                <tr>
                    <th>Package Name</th>
                    <th>Total</th>
                    <th>Queue</th>
                    <th>Scheduler</th>
                    <th>Reverb</th>
                    <th>Reverb</th>
                    <th>Multi-App</th>
                    <th>Max Apps</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($packages as $pkg)
                <tr>
                    <td><strong>{{ $pkg['name'] }}</strong></td>
                    <td>{{ $pkg['limits']['max_workers_total'] }}</td>
                    <td>{{ $pkg['limits']['max_queue_workers'] }}</td>
                    <td>{{ $pkg['limits']['max_scheduler_workers'] }}</td>
                    <td>{{ $pkg['limits']['max_reverb_workers'] }}</td>
                    <td>
                        <span class="whm-badge {{ $pkg['limits']['reverb_enabled'] ? 'whm-badge-on' : 'whm-badge-off' }}">
                            {{ $pkg['limits']['reverb_enabled'] ? 'Enabled' : 'Disabled' }}
                        </span>
                    </td>
                    <td>
                        <span class="whm-badge {{ $pkg['limits']['multi_app_enabled'] ? 'whm-badge-on' : 'whm-badge-off' }}">
                            {{ $pkg['limits']['multi_app_enabled'] ? 'Yes' : 'No' }}
                        </span>
                    </td>
                    <td>{{ $pkg['limits']['max_apps'] }}</td>
                    <td>
                        @if ($pkg['configured'])
                            <span class="whm-badge whm-badge-on">Custom</span>
                        @else
                            <span style="color:#999; font-size:.85em;">Default</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('whm.packages.edit', $pkg['name']) }}"
                           style="color:#0064a3; margin-right:8px;">Edit</a>
                        @if ($pkg['configured'])
                            <a href="#" onclick="resetPackage('{{ addslashes($pkg['name']) }}'); return false;"
                               style="color:#dc3545;">Reset</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" style="text-align:center; color:#999; padding:20px;">
                        No WHM packages found. Create packages in WHM first.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="whm-panel">
    <div class="whm-panel-head">Default Limits (applied when no package-specific config exists)</div>
    <div class="whm-panel-body">
        <table style="border-collapse:collapse; width:auto;">
            @foreach ($defaults as $key => $val)
            <tr>
                <td style="padding:4px 16px 4px 0; font-weight:600; text-transform:capitalize;">
                    {{ str_replace('_', ' ', $key) }}
                </td>
                <td style="padding:4px 0;">
                    @if (is_bool($val))
                        <span class="whm-badge {{ $val ? 'whm-badge-on' : 'whm-badge-off' }}">
                            {{ $val ? 'Yes' : 'No' }}
                        </span>
                    @else
                        {{ $val }}
                    @endif
                </td>
            </tr>
            @endforeach
        </table>
        <p style="margin-top:10px; color:#666; font-size:.85em;">
            Edit <code>config/supervisor_plugin.php → default_limits</code> to change these defaults.
        </p>
    </div>
</div>

@endsection

@section('scripts')
<script>
function resetPackage(name) {
    if (!confirm(`Reset limits for package "${name}" to defaults?\n\nCustom limits will be removed.`)) return;

    whmFetch(`${WHM_BASE}/packages/${encodeURIComponent(name)}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(d => {
            showFlash(d.message || (d.success ? 'Reset.' : 'Failed.'), d.success ? 'success' : 'error');
            if (d.success) setTimeout(() => location.reload(), 1200);
        });
}
</script>
@endsection

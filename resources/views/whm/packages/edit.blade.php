@extends('whm.layout')
@section('title', 'Edit Package: ' . $package_name . ' — Supervisor Manager WHM')

@section('content')

<div style="margin-bottom:12px;">
    <a href="{{ route('whm.packages.index') }}" style="color:#0064a3;">← Back to Package List</a>
</div>

<div class="whm-panel">
    <div class="whm-panel-head">
        Package Limits: <em>{{ $package_name }}</em>
    </div>
    <div class="whm-panel-body">

        <form id="package-form">
            @csrf
            @method('PUT')

            <table style="width:100%; border-collapse:collapse;">

                <tr style="border-bottom:1px solid #eee;">
                    <td colspan="2" style="padding:8px 0 4px;">
                        <strong style="font-size:1.05em; color:#0064a3;">Worker Limits</strong>
                    </td>
                </tr>

                @php
                    $fields = [
                        'max_workers_total'        => ['label' => 'Max Total Workers',    'help' => 'Maximum combined workers of all types', 'min' => 0, 'max' => 50],
                        'max_queue_workers'        => ['label' => 'Max Queue Workers',     'help' => 'Queue workers allowed for this package', 'min' => 0, 'max' => 20],
                        'max_scheduler_workers'    => ['label' => 'Max Scheduler Workers', 'help' => 'Scheduler workers allowed', 'min' => 0, 'max' => 5],
                        'max_reverb_workers'       => ['label' => 'Max Reverb Workers',    'help' => 'WebSocket Reverb workers allowed', 'min' => 0, 'max' => 10],
                        'max_apps'                 => ['label' => 'Max Laravel Apps',      'help' => 'Max apps a user can register (requires Multi-App)', 'min' => 1, 'max' => 20],
                    ];
                @endphp

                @foreach ($fields as $name => $field)
                <tr>
                    <td style="padding:10px 20px 10px 0; width:240px; vertical-align:top;">
                        <label for="{{ $name }}"><strong>{{ $field['label'] }}</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">{{ $field['help'] }}</p>
                    </td>
                    <td style="padding:10px 0; vertical-align:top;">
                        <input type="number" id="{{ $name }}" name="{{ $name }}"
                               class="form-control"
                               value="{{ old($name, $limit->$name ?? $defaults[$name] ?? 0) }}"
                               min="{{ $field['min'] }}" max="{{ $field['max'] }}"
                               style="width:100px;">
                    </td>
                </tr>
                @endforeach

                <tr style="border-top:2px solid #eee; border-bottom:1px solid #eee;">
                    <td colspan="2" style="padding:8px 0 4px;">
                        <strong style="font-size:1.05em; color:#0064a3;">Feature Flags</strong>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 20px 10px 0; vertical-align:top;">
                        <label><strong>Reverb Enabled</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Allow Reverb WebSocket workers on this package</p>
                    </td>
                    <td style="padding:10px 0; vertical-align:middle;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                            <input type="hidden" name="reverb_enabled" value="0">
                            <input type="checkbox" name="reverb_enabled" value="1" id="reverb_enabled"
                                   {{ old('reverb_enabled', $limit->reverb_enabled ?? $defaults['reverb_enabled']) ? 'checked' : '' }}
                                   style="width:16px; height:16px;">
                            Enable Reverb for this package
                        </label>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 20px 10px 0; vertical-align:top;">
                        <label><strong>Multi-App Support</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Allow users to register multiple Laravel apps</p>
                    </td>
                    <td style="padding:10px 0; vertical-align:middle;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                            <input type="hidden" name="multi_app_enabled" value="0">
                            <input type="checkbox" name="multi_app_enabled" value="1" id="multi_app_enabled"
                                   {{ old('multi_app_enabled', $limit->multi_app_enabled ?? $defaults['multi_app_enabled']) ? 'checked' : '' }}
                                   style="width:16px; height:16px;">
                            Enable multi-app support
                        </label>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 20px 10px 0; vertical-align:top;">
                        <label for="notes"><strong>Admin Notes</strong></label>
                        <p style="font-size:.8em; color:#666; margin:2px 0 0;">Optional internal notes (not shown to users)</p>
                    </td>
                    <td style="padding:10px 0;">
                        <textarea name="notes" id="notes" class="form-control"
                                  rows="2" maxlength="500"
                                  style="width:400px; resize:vertical;">{{ old('notes', $limit->notes ?? '') }}</textarea>
                    </td>
                </tr>

            </table>

            <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
                <button type="submit"
                        style="background:#0064a3; color:#fff; border:none; padding:8px 20px; border-radius:3px; cursor:pointer; font-size:.95em;">
                    Save Limits
                </button>
                <a href="{{ route('whm.packages.index') }}"
                   style="color:#666; text-decoration:none; padding:8px 12px;">Cancel</a>

                @if ($limit->exists)
                <button type="button" onclick="resetToDefaults()"
                        style="background:#dc3545; color:#fff; border:none; padding:8px 16px; border-radius:3px; cursor:pointer; margin-left:auto; font-size:.9em;">
                    Reset to Defaults
                </button>
                @endif
            </div>

        </form>

    </div>
</div>

@endsection

@section('scripts')
<script>
document.getElementById('package-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const fd   = new FormData(this);
    const data = {};

    for (const [k, v] of fd.entries()) {
        if (k === '_method' || k === '_token') continue;
        // Handle checkboxes: last value wins (hidden 0, then checked 1 if checked)
        data[k] = (k === 'reverb_enabled' || k === 'multi_app_enabled')
            ? (fd.getAll(k).includes('1') ? true : false)
            : v;
    }

    // Re-process booleans explicitly
    data['reverb_enabled']    = document.getElementById('reverb_enabled').checked;
    data['multi_app_enabled'] = document.getElementById('multi_app_enabled').checked;

    try {
        const res  = await whmFetch(
            `${WHM_BASE}/packages/{{ urlencode($package_name) }}`,
            { method: 'PUT', body: JSON.stringify(data) }
        );
        const json = await res.json();

        if (json.success) {
            showFlash(json.message || 'Saved.', 'success');
        } else {
            const errors = json.errors
                ? Object.values(json.errors).flat().join('<br>')
                : (json.error || 'Failed to save.');
            showFlash(errors, 'error');
        }
    } catch (err) {
        showFlash('Network error: ' + err.message, 'error');
    }
});

function resetToDefaults() {
    if (!confirm('Reset limits for "{{ addslashes($package_name) }}" to server defaults?')) return;

    whmFetch(`${WHM_BASE}/packages/{{ urlencode($package_name) }}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(d => {
            showFlash(d.message || 'Reset.', d.success ? 'success' : 'error');
            if (d.success) setTimeout(() => location.href = '{{ route("whm.packages.index") }}', 1200);
        });
}
</script>
@endsection

@extends('cpanel.layout')
@section('title', 'Dashboard — Supervisor Manager')

@section('breadcrumb')
    <li class="breadcrumb-item active">Supervisor Manager</li>
@endsection

@section('content')

{{-- ── Resource usage stats ───────────────────────────────────────────── --}}
<div class="lsp-stat-grid" style="margin-bottom:20px;">
    <div class="lsp-stat-box">
        <div class="num">{{ $usage['current']['total'] }}</div>
        <div class="lbl">Total Workers</div>
        <small class="text-muted">/ {{ $usage['limits']['max_workers_total'] }} max</small>
    </div>
    <div class="lsp-stat-box">
        <div class="num">{{ $usage['current']['queue'] }}</div>
        <div class="lbl">Queue Workers</div>
        <small class="text-muted">/ {{ $usage['limits']['max_queue_workers'] }} max</small>
    </div>
    <div class="lsp-stat-box">
        <div class="num">{{ $usage['current']['scheduler'] }}</div>
        <div class="lbl">Schedulers</div>
        <small class="text-muted">/ {{ $usage['limits']['max_scheduler_workers'] }} max</small>
    </div>
    <div class="lsp-stat-box">
        <div class="num">{{ $usage['current']['reverb'] }}</div>
        <div class="lbl">Reverb Workers</div>
        <small class="text-muted">/ {{ $usage['limits']['max_reverb_workers'] }} max</small>
    </div>
    <div class="lsp-stat-box">
        <div class="num" style="font-size:1em;">{{ $usage['package'] }}</div>
        <div class="lbl">Hosting Plan</div>
    </div>
</div>

{{-- ── LVE Resource panel (CloudLinux only) ───────────────────────────── --}}
@if ($lve_available)
<div class="panel panel-default" style="margin-bottom:20px;">
    <div class="panel-heading">
        <h4 class="panel-title">
            <span class="glyphicon glyphicon-dashboard"></span>
            CloudLinux Resource Usage
            <small style="float:right;font-weight:normal;">
                <span id="lve-refresh-time"></span>
                <a href="#" onclick="refreshLve(); return false;" style="margin-left:8px;">
                    <span class="glyphicon glyphicon-refresh"></span> Refresh
                </a>
            </small>
        </h4>
    </div>
    <div class="panel-body" id="lve-panel">
        @if ($lve_usage['available'])
            <div class="row">
                <div class="col-sm-4">
                    <strong>CPU</strong>
                    <div class="lsp-progress-bar" style="margin-top:4px;">
                        @php
                            $cpuPct = ($lve_limits['cpu_limit'] ?? 0) > 0
                                ? round(($lve_usage['cpu_usage'] / $lve_limits['cpu_limit']) * 100)
                                : 0;
                            $cpuColor = $cpuPct > 80 ? '#dc3545' : ($cpuPct > 60 ? '#ffc107' : '#28a745');
                        @endphp
                        <div class="lsp-progress-fill" style="width:{{ $cpuPct }}%; background:{{ $cpuColor }};"></div>
                    </div>
                    <small>{{ $cpuPct }}% of limit</small>
                </div>
                <div class="col-sm-4">
                    <strong>Memory</strong>
                    <div class="lsp-progress-bar" style="margin-top:4px;">
                        @php
                            $memPct = ($lve_limits['mem_limit'] ?? 0) > 0
                                ? round(($lve_usage['mem_usage'] / $lve_limits['mem_limit']) * 100)
                                : 0;
                            $memColor = $memPct > 80 ? '#dc3545' : ($memPct > 60 ? '#ffc107' : '#28a745');
                        @endphp
                        <div class="lsp-progress-fill" style="width:{{ $memPct }}%; background:{{ $memColor }};"></div>
                    </div>
                    <small>{{ $memPct }}% — {{ round(($lve_usage['mem_usage'] ?? 0) / 1024) }} MB used</small>
                </div>
                <div class="col-sm-4">
                    <strong>Entry Processes</strong>
                    <div style="margin-top:4px;">
                        <span class="badge">{{ $lve_usage['ep_usage'] ?? 0 }}</span>
                        / {{ $lve_limits['ep_limit'] ?? '∞' }}
                    </div>
                </div>
            </div>
        @else
            <em class="text-muted">LVE data unavailable — ensure this account has LVE enabled.</em>
        @endif
    </div>
</div>
@endif

{{-- ── Running workers table ──────────────────────────────────────────── --}}
<div class="panel panel-primary">
    <div class="panel-heading">
        <h4 class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
            <span>
                <span class="glyphicon glyphicon-tasks"></span> Worker Status
            </span>
            <span>
                <a href="{{ route('cpanel.workers.create') }}" class="btn btn-default btn-xs">
                    <span class="glyphicon glyphicon-plus"></span> New Worker
                </a>
                <a href="#" onclick="refreshStatus(); return false;" class="btn btn-default btn-xs" style="margin-left:4px;">
                    <span class="glyphicon glyphicon-refresh" id="refresh-icon"></span> Refresh
                </a>
            </span>
        </h4>
    </div>
    <div class="panel-body" style="padding:0;">
        @if (empty($worker_statuses))
            <div style="padding:20px; text-align:center; color:#6c757d;">
                <span class="glyphicon glyphicon-info-sign" style="font-size:2em; display:block; margin-bottom:8px;"></span>
                No workers configured.
                <a href="{{ route('cpanel.workers.create') }}">Create your first worker →</a>
            </div>
        @else
        <div style="overflow-x:auto;">
            <table class="table table-striped table-hover" style="margin:0;" id="workers-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>CPU %</th>
                        <th>Mem %</th>
                        <th>Port</th>
                        <th>Last Restart</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($worker_statuses as $ws)
                    <tr data-worker-id="{{ $ws['worker_id'] ?? '' }}" data-process="{{ $ws['process_name'] }}">
                        <td>
                            <a href="{{ route('cpanel.workers.show', $ws['worker_id']) }}">
                                {{ $ws['worker_name'] }}
                            </a>
                            <br><small class="text-muted">{{ basename($ws['app_path']) }}</small>
                        </td>
                        <td>
                            <span class="badge badge-{{ $ws['type'] === 'queue' ? 'info' : ($ws['type'] === 'reverb' ? 'warning' : 'default') }}">
                                {{ strtoupper($ws['type']) }}
                            </span>
                        </td>
                        <td>
                            @php
                                $status = $ws['status'] ?? 'UNKNOWN';
                                $badge  = match(strtoupper($status)) {
                                    'RUNNING'  => 'running',
                                    'STOPPED'  => 'stopped',
                                    'FATAL'    => 'fatal',
                                    'STARTING' => 'starting',
                                    default    => 'unknown',
                                };
                            @endphp
                            <span class="lsp-badge lsp-badge-{{ $badge }}">{{ $status }}</span>
                        </td>
                        <td>{{ $ws['cpu'] !== null ? number_format($ws['cpu'], 1) . '%' : '—' }}</td>
                        <td>{{ $ws['memory'] !== null ? number_format($ws['memory'], 1) . '%' : '—' }}</td>
                        <td>
                            @if ($ws['port'])
                                <code>{{ $ws['protocol'] }}://...:<strong>{{ $ws['port'] }}</strong></code>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $ws['last_restart'] ?? '—' }}</small></td>
                        <td>
                            <div class="lsp-card-actions">
                                @php $processName = $ws['process_name']; $wid = $ws['worker_id']; @endphp
                                <button onclick="workerAction('{{ $wid }}', 'restart')"
                                        class="btn btn-xs btn-warning" title="Restart">
                                    <span class="glyphicon glyphicon-repeat"></span>
                                </button>
                                <button onclick="workerAction('{{ $wid }}', 'stop')"
                                        class="btn btn-xs btn-default" title="Stop">
                                    <span class="glyphicon glyphicon-stop"></span>
                                </button>
                                <button onclick="workerAction('{{ $wid }}', 'start')"
                                        class="btn btn-xs btn-success" title="Start">
                                    <span class="glyphicon glyphicon-play"></span>
                                </button>
                                <a href="{{ route('cpanel.workers.show', $wid) }}"
                                   class="btn btn-xs btn-info" title="Details">
                                    <span class="glyphicon glyphicon-search"></span>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@endsection

@section('scripts')
<script>
let refreshTimer = null;

function refreshStatus() {
    const icon = document.getElementById('refresh-icon');
    if (icon) icon.classList.add('spinning');

    pluginFetch('{{ route("cpanel.status.poll") }}')
        .then(r => r.json())
        .then(data => {
            updateWorkerTable(data.statuses);
            if (icon) icon.classList.remove('spinning');
        })
        .catch(() => { if (icon) icon.classList.remove('spinning'); });
}

function updateWorkerTable(statuses) {
    statuses.forEach(ws => {
        const row = document.querySelector(`tr[data-worker-id="${ws.worker_id}"]`);
        if (!row) return;

        const statusCell = row.querySelector('td:nth-child(3)');
        if (statusCell) {
            const badge = ws.status?.toLowerCase() || 'unknown';
            statusCell.innerHTML = `<span class="lsp-badge lsp-badge-${badge}">${ws.status || 'UNKNOWN'}</span>`;
        }

        const cpuCell = row.querySelector('td:nth-child(4)');
        if (cpuCell) cpuCell.textContent = ws.cpu != null ? ws.cpu.toFixed(1) + '%' : '—';

        const memCell = row.querySelector('td:nth-child(5)');
        if (memCell) memCell.textContent = ws.memory != null ? ws.memory.toFixed(1) + '%' : '—';
    });
}

function workerAction(workerId, action) {
    const labels = { restart: 'Restart', stop: 'Stop', start: 'Start' };
    if (!confirm(`${labels[action] || action} this worker?`)) return;

    const url = `${PLUGIN_BASE}/workers/${workerId}/${action}`;

    pluginFetch(url, { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert(`Worker ${action}ed successfully.`, 'success');
                setTimeout(refreshStatus, 1500);
            } else {
                showAlert(data.error || 'Action failed.', 'danger');
            }
        })
        .catch(err => showAlert('Network error: ' + err.message, 'danger'));
}

@if ($lve_available)
function refreshLve() {
    pluginFetch('{{ route("cpanel.status.lve") }}')
        .then(r => r.json())
        .then(data => {
            document.getElementById('lve-refresh-time').textContent = 'Updated: ' + new Date().toLocaleTimeString();
        });
}
// Auto-refresh LVE every 15s
setInterval(refreshLve, 15000);
@endif

// Auto-refresh worker statuses every 10 seconds
refreshTimer = setInterval(refreshStatus, 10000);

// Spinning icon CSS
const style = document.createElement('style');
style.textContent = `@keyframes spin { to { transform:rotate(360deg); } } .spinning { display:inline-block; animation:spin .7s linear infinite; }`;
document.head.appendChild(style);
</script>
@endsection

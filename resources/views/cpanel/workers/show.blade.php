@extends('cpanel.layout')
@section('title', $worker->worker_name . ' — Supervisor Manager')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('cpanel.dashboard') }}">Supervisor Manager</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cpanel.workers.index') }}">Workers</a></li>
    <li class="breadcrumb-item active">{{ $worker->worker_name }}</li>
@endsection

@section('content')

<div class="row">

    {{-- ── Left column: info + controls ────────────────────────────── --}}
    <div class="col-md-6">

        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
                <h4 class="panel-title" style="margin:0;">
                    {{ $worker->worker_name }}
                    <small>
                        <span class="badge">{{ strtoupper($worker->type) }}</span>
                    </small>
                </h4>
                @if ($status)
                    @php
                        $badge = match(strtoupper($status['status'] ?? '')) {
                            'RUNNING'  => 'running',
                            'STOPPED'  => 'stopped',
                            'FATAL'    => 'fatal',
                            'STARTING' => 'starting',
                            default    => 'unknown',
                        };
                    @endphp
                    <span class="lsp-badge lsp-badge-{{ $badge }}" id="live-status">
                        {{ strtoupper($status['status'] ?? 'UNKNOWN') }}
                    </span>
                @endif
            </div>
            <div class="panel-body">
                <table class="table table-condensed" style="margin-bottom:0;">
                    <tr>
                        <th style="width:40%">Process Name</th>
                        <td><code>{{ $worker->process_name }}</code></td>
                    </tr>
                    <tr>
                        <th>Application</th>
                        <td>{{ $worker->app?->app_name }} <br><small class="text-muted">{{ $worker->app?->app_path }}</small></td>
                    </tr>
                    <tr>
                        <th>Conf File</th>
                        <td><code>{{ $worker->conf_path }}</code></td>
                    </tr>
                    @if ($worker->type === 'queue')
                    <tr>
                        <th>Queue Connection</th>
                        <td><code>{{ $worker->worker_config['queue_connection'] ?? 'default' }}</code></td>
                    </tr>
                    <tr>
                        <th>Processes (numprocs)</th>
                        <td>{{ $worker->worker_config['numprocs'] ?? 1 }}</td>
                    </tr>
                    <tr>
                        <th>Max Tries</th>
                        <td>{{ $worker->worker_config['tries'] ?? 3 }}</td>
                    </tr>
                    <tr>
                        <th>Timeout</th>
                        <td>{{ $worker->worker_config['timeout'] ?? 60 }}s</td>
                    </tr>
                    <tr>
                        <th>Memory Limit</th>
                        <td>{{ $worker->worker_config['memory'] ?? 128 }} MB</td>
                    </tr>
                    @endif
                    @if ($worker->type === 'reverb' && $worker->assignedPort)
                    <tr>
                        <th>WebSocket URL</th>
                        <td>
                            <code>{{ $worker->assignedPort->getWebSocketUrl() }}</code>
                            @if ($worker->assignedPort->ssl_detected)
                                <span class="label label-success">SSL ✓</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Port</th>
                        <td>{{ $worker->assignedPort->port }}</td>
                    </tr>
                    @endif
                    @if ($status)
                    <tr>
                        <th>PID</th>
                        <td id="live-pid">{{ $status['pid'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>Uptime</th>
                        <td id="live-uptime">{{ $status['uptime'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>CPU</th>
                        <td id="live-cpu">{{ $status['cpu'] !== null ? number_format($status['cpu'], 1) . '%' : '—' }}</td>
                    </tr>
                    <tr>
                        <th>Memory</th>
                        <td id="live-mem">{{ $status['memory'] !== null ? number_format($status['memory'], 1) . '%' : '—' }}</td>
                    </tr>
                    @endif
                    <tr>
                        <th>Last Restarted</th>
                        <td>{{ $worker->last_restarted_at?->diffForHumans() ?? 'Never' }}</td>
                    </tr>
                    <tr>
                        <th>Created</th>
                        <td>{{ $worker->created_at?->toDateTimeString() }}</td>
                    </tr>
                </table>
            </div>
            <div class="panel-footer">
                <div class="lsp-card-actions">
                    <button onclick="workerAction('restart')" class="btn btn-sm btn-warning">
                        <span class="glyphicon glyphicon-repeat"></span> Restart
                    </button>
                    <button onclick="workerAction('stop')" class="btn btn-sm btn-default">
                        <span class="glyphicon glyphicon-stop"></span> Stop
                    </button>
                    <button onclick="workerAction('start')" class="btn btn-sm btn-success">
                        <span class="glyphicon glyphicon-play"></span> Start
                    </button>
                    <button onclick="deleteWorker()" class="btn btn-sm btn-danger" style="margin-left:auto;">
                        <span class="glyphicon glyphicon-trash"></span> Delete Worker
                    </button>
                </div>
            </div>
        </div>

    </div>{{-- /.col-md-6 --}}

    {{-- ── Right column: logs ────────────────────────────────────────── --}}
    <div class="col-md-6">

        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
                <h4 class="panel-title" style="margin:0;">Log Output (last 50 lines)</h4>
                <a href="#" onclick="loadLogs(); return false;" class="btn btn-xs btn-default">
                    <span class="glyphicon glyphicon-refresh"></span> Refresh Logs
                </a>
            </div>
            <div class="panel-body" style="padding:8px;">
                <div class="lsp-log-viewer" id="stdout-log">Loading…</div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">Error Log</h4>
            </div>
            <div class="panel-body" style="padding:8px;">
                <div class="lsp-log-viewer" id="stderr-log" style="max-height:200px;">Loading…</div>
            </div>
        </div>

    </div>{{-- /.col-md-6 --}}

</div>{{-- /.row --}}

@endsection

@section('scripts')
<script>
const WORKER_ID = {{ $worker->id }};

function workerAction(action) {
    const labels = { restart: 'Restart', stop: 'Stop', start: 'Start' };
    if (!confirm(`${labels[action]} this worker?`)) return;

    pluginFetch(`${PLUGIN_BASE}/workers/${WORKER_ID}/${action}`, { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert(`Worker ${action}ed.`, 'success');
                setTimeout(refreshStatus, 1500);
            } else {
                showAlert(d.error || 'Action failed.', 'danger');
            }
        });
}

function deleteWorker() {
    if (!confirm('Delete this worker?\n\nThis action cannot be undone. The Supervisor config and port assignment will be removed.')) return;

    pluginFetch(`${PLUGIN_BASE}/workers/${WORKER_ID}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert('Worker deleted. Redirecting…', 'success');
                setTimeout(() => location.href = '{{ route("cpanel.workers.index") }}', 1500);
            } else {
                showAlert(d.error || 'Delete failed.', 'danger');
            }
        });
}

function refreshStatus() {
    pluginFetch('{{ route("cpanel.status.poll") }}')
        .then(r => r.json())
        .then(data => {
            const ws = data.statuses.find(s => s.worker_id == WORKER_ID);
            if (!ws) return;

            const badge   = ws.status?.toLowerCase() || 'unknown';
            const el      = document.getElementById('live-status');
            if (el) {
                el.className  = `lsp-badge lsp-badge-${badge}`;
                el.textContent = ws.status || 'UNKNOWN';
            }

            const set = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };
            set('live-pid',    ws.pid    ?? '—');
            set('live-uptime', ws.uptime ?? '—');
            set('live-cpu',    ws.cpu    != null ? ws.cpu.toFixed(1) + '%'    : '—');
            set('live-mem',    ws.memory != null ? ws.memory.toFixed(1) + '%' : '—');
        });
}

function loadLogs() {
    const stdEl = document.getElementById('stdout-log');
    const errEl = document.getElementById('stderr-log');
    if (stdEl) stdEl.textContent = 'Loading…';
    if (errEl) errEl.textContent = 'Loading…';

    pluginFetch(`${PLUGIN_BASE}/workers/${WORKER_ID}/logs?lines=50`)
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                if (stdEl) stdEl.textContent = d.logs.stdout || '(no output)';
                if (errEl) errEl.textContent = d.logs.stderr || '(no errors)';
                // Scroll to bottom
                if (stdEl) stdEl.scrollTop = stdEl.scrollHeight;
                if (errEl) errEl.scrollTop = errEl.scrollHeight;
            } else {
                if (stdEl) stdEl.textContent = 'Error: ' + (d.error || 'Failed to load logs');
            }
        });
}

// Initial load
loadLogs();
refreshStatus();
// Auto-refresh status every 8s, logs every 20s
setInterval(refreshStatus, 8000);
setInterval(loadLogs,      20000);

const style = document.createElement('style');
style.textContent = `@keyframes spin { to { transform:rotate(360deg); } } .spinning { display:inline-block; animation:spin .7s linear infinite; }`;
document.head.appendChild(style);
</script>
@endsection

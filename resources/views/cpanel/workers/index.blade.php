@extends('cpanel.layout')
@section('title', 'Workers — Supervisor Manager')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('cpanel.dashboard') }}">Supervisor Manager</a></li>
    <li class="breadcrumb-item active">Workers</li>
@endsection

@section('content')

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <div>
        <strong>{{ $workers->count() }}</strong> worker(s) configured
        @foreach (['queue','scheduler','reverb'] as $t)
            &nbsp;·&nbsp;<span class="badge">{{ $workers->where('type',$t)->count() }} {{ $t }}</span>
        @endforeach
    </div>
    @php $canCreate = $usage['current']['total'] < $usage['limits']['max_workers_total']; @endphp
    @if ($canCreate)
        <a href="{{ route('cpanel.workers.create') }}" class="btn btn-primary btn-sm">
            <span class="glyphicon glyphicon-plus"></span> New Worker
        </a>
    @else
        <button class="btn btn-default btn-sm" disabled title="Worker limit reached">
            <span class="glyphicon glyphicon-ban-circle"></span> Limit Reached
        </button>
    @endif
</div>

@if ($workers->isEmpty())
    <div class="alert alert-info">
        <span class="glyphicon glyphicon-info-sign"></span>
        No workers configured yet.
        @if ($canCreate)
            <a href="{{ route('cpanel.workers.create') }}">Create your first worker</a> to get started.
        @endif
    </div>
@else

@foreach (['queue' => 'Queue Workers', 'scheduler' => 'Scheduler Workers', 'reverb' => 'Reverb Workers'] as $type => $label)
@php $typeWorkers = $workers->where('type', $type); @endphp
@if ($typeWorkers->isNotEmpty())
<div class="panel panel-default" style="margin-bottom:16px;">
    <div class="panel-heading">
        <h4 class="panel-title">
            <span class="glyphicon glyphicon-{{ $type === 'queue' ? 'transfer' : ($type === 'reverb' ? 'signal' : 'time') }}"></span>
            {{ $label }}
        </h4>
    </div>
    <div class="panel-body" style="padding:0;">
        <table class="table table-hover" style="margin:0;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Application</th>
                    @if ($type === 'reverb')<th>Port / URL</th>@endif
                    @if ($type === 'queue')<th>Connection</th>@endif
                    <th>State</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($typeWorkers as $worker)
                <tr>
                    <td>
                        <a href="{{ route('cpanel.workers.show', $worker) }}">
                            <strong>{{ $worker->worker_name }}</strong>
                        </a>
                        <br><small class="text-muted"><code>{{ $worker->process_name }}</code></small>
                    </td>
                    <td>
                        {{ $worker->app?->app_name ?? '—' }}
                        <br><small class="text-muted">{{ $worker->app?->app_path }}</small>
                    </td>
                    @if ($type === 'reverb')
                    <td>
                        @if ($worker->assignedPort)
                            <code>{{ $worker->assignedPort->getWebSocketUrl() }}</code>
                            @if ($worker->assignedPort->ssl_detected)
                                <span class="label label-success" style="margin-left:4px;">SSL</span>
                            @endif
                        @else
                            <span class="text-muted">Allocating…</span>
                        @endif
                    </td>
                    @endif
                    @if ($type === 'queue')
                    <td><code>{{ $worker->worker_config['queue_connection'] ?? 'default' }}</code></td>
                    @endif
                    <td>
                        @php $ds = $worker->desired_state; @endphp
                        <span class="lsp-badge lsp-badge-{{ $ds === 'running' ? 'running' : 'stopped' }}">
                            {{ strtoupper($ds) }}
                        </span>
                    </td>
                    <td><small>{{ $worker->created_at?->diffForHumans() }}</small></td>
                    <td>
                        <div class="lsp-card-actions">
                            <button onclick="workerAction({{ $worker->id }}, 'restart')"
                                    class="btn btn-xs btn-warning" title="Restart">
                                <span class="glyphicon glyphicon-repeat"></span>
                            </button>
                            <button onclick="workerAction({{ $worker->id }}, 'stop')"
                                    class="btn btn-xs btn-default" title="Stop">
                                <span class="glyphicon glyphicon-stop"></span>
                            </button>
                            <button onclick="workerAction({{ $worker->id }}, 'start')"
                                    class="btn btn-xs btn-success" title="Start">
                                <span class="glyphicon glyphicon-play"></span>
                            </button>
                            <a href="{{ route('cpanel.workers.show', $worker) }}"
                               class="btn btn-xs btn-info" title="View details">
                                <span class="glyphicon glyphicon-search"></span>
                            </a>
                            <button onclick="deleteWorker({{ $worker->id }}, '{{ addslashes($worker->worker_name) }}')"
                                    class="btn btn-xs btn-danger" title="Delete">
                                <span class="glyphicon glyphicon-trash"></span>
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endforeach

@endif

@endsection

@section('scripts')
<script>
function workerAction(id, action) {
    const labels = { restart: 'Restart', stop: 'Stop', start: 'Start' };
    if (!confirm(`${labels[action] || action} this worker?`)) return;

    pluginFetch(`${PLUGIN_BASE}/workers/${id}/${action}`, { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert(`Worker ${action}ed successfully.`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(d.error || 'Action failed.', 'danger');
            }
        });
}

function deleteWorker(id, name) {
    if (!confirm(`Delete worker "${name}"?\n\nThis will stop the process and remove the Supervisor config.`)) return;

    pluginFetch(`${PLUGIN_BASE}/workers/${id}`, { method: 'DELETE' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert('Worker deleted.', 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showAlert(d.error || 'Delete failed.', 'danger');
            }
        });
}
</script>
@endsection

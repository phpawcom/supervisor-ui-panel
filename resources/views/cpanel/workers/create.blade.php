@extends('cpanel.layout')
@section('title', 'New Worker — Supervisor Manager')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('cpanel.dashboard') }}">Supervisor Manager</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cpanel.workers.index') }}">Workers</a></li>
    <li class="breadcrumb-item active">New Worker</li>
@endsection

@section('content')

@if (!$lve['safe'])
<div class="alert alert-warning">
    <span class="glyphicon glyphicon-warning-sign"></span>
    <strong>Resource Warning:</strong> {{ $lve['reason'] }}
    You may still create a worker, but performance may be impacted.
</div>
@endif

<div class="row">
    <div class="col-md-8">

    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <span class="glyphicon glyphicon-plus"></span> Create New Supervisor Worker
            </h4>
        </div>
        <div class="panel-body">

            @if ($apps->isEmpty())
            <div class="alert alert-warning">
                <span class="glyphicon glyphicon-warning-sign"></span>
                No Laravel applications detected under your home directory.
                Ensure your app has an <code>artisan</code> file and <code>bootstrap/app.php</code>.
            </div>
            @endif

            <form id="create-worker-form">
                @csrf

                {{-- ── Laravel Application ─────────────────────── --}}
                <div class="form-group">
                    <label for="app_id">Laravel Application <span class="text-danger">*</span></label>
                    <select class="form-control" id="app_id" name="app_id" required>
                        <option value="">— Select application —</option>
                        @foreach ($apps as $app)
                            <option value="{{ $app->id ?? $app['id'] }}"
                                    data-features="{{ json_encode($app->detected_features ?? $app['features'] ?? []) }}">
                                {{ $app->app_name ?? $app['name'] }}
                                ({{ $app->app_path ?? $app['path'] }})
                            </option>
                        @endforeach
                    </select>
                    <p class="help-block">The Laravel project directory to manage.</p>
                </div>

                {{-- ── Worker Type ─────────────────────────────── --}}
                <div class="form-group">
                    <label>Worker Type <span class="text-danger">*</span></label>
                    <div>
                        <label class="radio-inline">
                            <input type="radio" name="type" value="queue" required
                                   {{ $limits['max_queue_workers'] > $usage['current']['queue'] ? '' : 'disabled' }}>
                            <span class="glyphicon glyphicon-transfer"></span> Queue Worker
                            @if ($limits['max_queue_workers'] <= $usage['current']['queue'])
                                <small class="text-danger">(limit reached)</small>
                            @endif
                        </label>
                        <label class="radio-inline">
                            <input type="radio" name="type" value="scheduler"
                                   {{ $limits['max_scheduler_workers'] > $usage['current']['scheduler'] ? '' : 'disabled' }}>
                            <span class="glyphicon glyphicon-time"></span> Scheduler
                            @if ($limits['max_scheduler_workers'] <= $usage['current']['scheduler'])
                                <small class="text-danger">(limit reached)</small>
                            @endif
                        </label>
                        <label class="radio-inline" id="reverb-radio-label"
                               title="{{ !$limits['reverb_enabled'] ? 'Reverb not enabled on your plan' : '' }}">
                            <input type="radio" name="type" value="reverb"
                                   {{ ($limits['reverb_enabled'] && $limits['max_reverb_workers'] > $usage['current']['reverb']) ? '' : 'disabled' }}>
                            <span class="glyphicon glyphicon-signal"></span> Reverb WebSocket
                            @if (!$limits['reverb_enabled'])
                                <small class="text-danger">(not available on plan)</small>
                            @elseif ($limits['max_reverb_workers'] <= $usage['current']['reverb'])
                                <small class="text-danger">(limit reached)</small>
                            @endif
                        </label>
                    </div>
                </div>

                {{-- ── Worker Name ──────────────────────────────── --}}
                <div class="form-group">
                    <label for="name">Worker Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name"
                           placeholder="e.g. Default Queue, Email Processor" maxlength="100" required>
                    <p class="help-block">A descriptive name for this worker (alphanumeric, spaces, hyphens, underscores).</p>
                </div>

                {{-- ── Queue-specific options ───────────────────── --}}
                <div id="queue-options" style="display:none;">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong>Queue Configuration</strong></div>
                        <div class="panel-body">
                            <div class="form-group">
                                <label for="queue_connection">Queue Connection</label>
                                <input type="text" class="form-control" id="queue_connection"
                                       name="queue_connection" placeholder="default" maxlength="64">
                                <p class="help-block">Queue connection name from your <code>config/queue.php</code>.</p>
                            </div>
                            <div class="row">
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="numprocs">Processes</label>
                                        <select class="form-control" id="numprocs" name="numprocs">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <option value="{{ $i }}" {{ $i === 1 ? 'selected' : '' }}>{{ $i }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="tries">Max Tries</label>
                                        <input type="number" class="form-control" id="tries"
                                               name="tries" value="3" min="1" max="10">
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="form-group">
                                        <label for="timeout">Timeout (sec)</label>
                                        <input type="number" class="form-control" id="timeout"
                                               name="timeout" value="60" min="10" max="3600">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="memory">Memory Limit (MB)</label>
                                <input type="number" class="form-control" id="memory"
                                       name="memory" value="128" min="64" max="1024">
                                <p class="help-block">Worker will restart if it exceeds this memory limit.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Reverb-specific options ──────────────────── --}}
                <div id="reverb-options" style="display:none;">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong>Reverb Configuration</strong></div>
                        <div class="panel-body">
                            <div class="alert alert-info" style="margin-bottom:10px;">
                                <span class="glyphicon glyphicon-info-sign"></span>
                                A port from the configured range will be automatically assigned.
                                SSL detection is automatic based on your domain's certificate.
                            </div>
                            <div class="form-group">
                                <label for="domain">Primary Domain</label>
                                <input type="text" class="form-control" id="domain"
                                       name="domain" placeholder="yourdomain.com" maxlength="255">
                                <p class="help-block">Domain used for WebSocket URL and SSL detection. Leave blank to auto-detect.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Submit ────────────────────────────────────── --}}
                <div class="form-group" style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        <span class="glyphicon glyphicon-ok"></span> Create Worker
                    </button>
                    <a href="{{ route('cpanel.workers.index') }}" class="btn btn-default" style="margin-left:8px;">
                        Cancel
                    </a>
                </div>

            </form>

        </div>{{-- /.panel-body --}}
    </div>{{-- /.panel --}}

    </div>{{-- /.col-md-8 --}}

    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading"><h5 class="panel-title">Plan Limits</h5></div>
            <div class="panel-body">
                <table class="table table-condensed" style="margin:0;">
                    <tr>
                        <td>Total Workers</td>
                        <td>{{ $usage['current']['total'] }} / {{ $limits['max_workers_total'] }}</td>
                    </tr>
                    <tr>
                        <td>Queue Workers</td>
                        <td>{{ $usage['current']['queue'] }} / {{ $limits['max_queue_workers'] }}</td>
                    </tr>
                    <tr>
                        <td>Schedulers</td>
                        <td>{{ $usage['current']['scheduler'] }} / {{ $limits['max_scheduler_workers'] }}</td>
                    </tr>
                    <tr>
                        <td>Reverb Workers</td>
                        <td>
                            @if ($limits['reverb_enabled'])
                                {{ $usage['current']['reverb'] }} / {{ $limits['max_reverb_workers'] }}
                            @else
                                <span class="text-muted">Not available</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="panel panel-info">
            <div class="panel-heading"><h5 class="panel-title">Notes</h5></div>
            <div class="panel-body" style="font-size:.85em;">
                <ul style="padding-left:16px; margin:0;">
                    <li>Workers run as your cPanel user.</li>
                    <li>Logs are stored in <code>~/logs/supervisor/</code>.</li>
                    <li>Workers auto-restart on failure.</li>
                    <li>Reverb requires <code>laravel/reverb</code> in your app.</li>
                </ul>
            </div>
        </div>
    </div>

</div>{{-- /.row --}}

@endsection

@section('scripts')
<script>
// Show/hide type-specific options
document.querySelectorAll('input[name="type"]').forEach(radio => {
    radio.addEventListener('change', function () {
        document.getElementById('queue-options').style.display   = this.value === 'queue'     ? '' : 'none';
        document.getElementById('reverb-options').style.display  = this.value === 'reverb'    ? '' : 'none';
    });
});

// Form submission
document.getElementById('create-worker-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="glyphicon glyphicon-refresh spinning"></span> Creating…';

    const data = Object.fromEntries(new FormData(this));

    try {
        const res = await pluginFetch('{{ route("cpanel.workers.store") }}', {
            method:  'POST',
            body:    JSON.stringify(data),
        });
        const json = await res.json();

        if (json.success) {
            showAlert(`Worker "${data.name}" created successfully. Redirecting…`, 'success');
            setTimeout(() => location.href = '{{ route("cpanel.workers.index") }}', 1800);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<span class="glyphicon glyphicon-ok"></span> Create Worker';

            if (json.errors) {
                const msgs = Object.values(json.errors).flat().join('<br>');
                showAlert(msgs, 'danger');
            } else {
                showAlert(json.error || 'Failed to create worker.', 'danger');
            }
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<span class="glyphicon glyphicon-ok"></span> Create Worker';
        showAlert('Network error: ' + err.message, 'danger');
    }
});

const style = document.createElement('style');
style.textContent = `@keyframes spin { to { transform:rotate(360deg); } } .spinning { display:inline-block; animation:spin .7s linear infinite; }`;
document.head.appendChild(style);
</script>
@endsection

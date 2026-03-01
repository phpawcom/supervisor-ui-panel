<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Supervisor Manager') — cPanel</title>

    {{--
        Jupiter theme CSS — loaded from cPanel's own static assets.
        The plugin is served inside Jupiter, so these paths are always valid.
    --}}
    <link rel="stylesheet" href="/themes/jupiter/css/master.css">
    <link rel="stylesheet" href="/themes/jupiter/css/components.css">

    <style>
        /* Plugin-specific overrides — minimal, respects Jupiter design tokens */
        .lsp-badge          { display:inline-block; padding:2px 8px; border-radius:3px; font-size:.8em; font-weight:600; }
        .lsp-badge-running  { background:#d4edda; color:#155724; }
        .lsp-badge-stopped  { background:#fff3cd; color:#856404; }
        .lsp-badge-fatal    { background:#f8d7da; color:#721c24; }
        .lsp-badge-unknown  { background:#e2e3e5; color:#383d41; }
        .lsp-badge-starting { background:#cce5ff; color:#004085; }

        .lsp-progress-bar   { height:6px; border-radius:3px; background:#e9ecef; overflow:hidden; }
        .lsp-progress-fill  { height:100%; border-radius:3px; transition:width .3s; }

        .lsp-log-viewer     {
            font-family:monospace; font-size:.78em; background:#1e1e2e;
            color:#cdd6f4; padding:12px; border-radius:4px;
            max-height:300px; overflow-y:auto; white-space:pre-wrap; word-break:break-all;
        }

        .lsp-card-actions   { display:flex; gap:6px; flex-wrap:wrap; }
        .lsp-stat-grid      { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; }
        .lsp-stat-box       { background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; padding:10px 14px; text-align:center; }
        .lsp-stat-box .num  { font-size:1.6em; font-weight:700; color:#0d6efd; }
        .lsp-stat-box .lbl  { font-size:.8em; color:#6c757d; margin-top:2px; }
    </style>
</head>
<body class="jupiter-body">

{{-- Jupiter top navigation bar is injected by cPanel's template engine --}}
{{-- In the real plugin deployment, this layout extends cPanel's master template --}}

<div id="body-content">
    <div class="container-fluid">

        {{-- Breadcrumb --}}
        <nav aria-label="breadcrumb" style="margin-bottom:12px;">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/cpanel/dashboard">
                        <span class="glyphicon glyphicon-home"></span> Dashboard
                    </a>
                </li>
                @hasSection('breadcrumb')
                    @yield('breadcrumb')
                @endif
            </ol>
        </nav>

        {{-- Flash messages --}}
        @if (session('success'))
            <div class="alert alert-success alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('error') }}
            </div>
        @endif

        {{-- Plugin header --}}
        <div class="page-header" style="margin-bottom:16px;">
            <h3>
                <span class="glyphicon glyphicon-cog" style="margin-right:6px;"></span>
                Supervisor Manager
                <small>— Worker management for your Laravel applications</small>
            </h3>
        </div>

        {{-- Sub-navigation --}}
        <ul class="nav nav-tabs" style="margin-bottom:20px;">
            <li role="presentation" class="{{ request()->routeIs('cpanel.dashboard') ? 'active' : '' }}">
                <a href="{{ route('cpanel.dashboard') }}">
                    <span class="glyphicon glyphicon-dashboard"></span> Overview
                </a>
            </li>
            <li role="presentation" class="{{ request()->routeIs('cpanel.workers.*') ? 'active' : '' }}">
                <a href="{{ route('cpanel.workers.index') }}">
                    <span class="glyphicon glyphicon-list"></span> Workers
                </a>
            </li>
        </ul>

        {{-- Main content --}}
        @yield('content')

    </div>{{-- /.container-fluid --}}
</div>{{-- /#body-content --}}

<script src="/themes/jupiter/js/master.js"></script>
<script>
    // Global AJAX setup: attach CSRF token and plugin auth token
    const PLUGIN_BASE = '{{ url("/cpanel") }}';
    const CSRF_TOKEN  = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function pluginFetch(url, options = {}) {
        const headers = Object.assign({
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN,
            'Accept': 'application/json',
        }, options.headers || {});

        return fetch(url, Object.assign(options, { headers }));
    }

    function showAlert(msg, type = 'success') {
        const div = document.createElement('div');
        div.className = `alert alert-${type} alert-dismissible`;
        div.role = 'alert';
        div.innerHTML = `<button type="button" class="close" data-dismiss="alert">&times;</button>${msg}`;
        document.querySelector('.container-fluid').insertBefore(div, document.querySelector('.page-header').nextSibling);
        setTimeout(() => div.remove(), 6000);
    }
</script>

@yield('scripts')
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Supervisor Manager') — WHM</title>

    {{-- WHM uses its own CSS. In production these are served by WHM's web server. --}}
    <link rel="stylesheet" href="/themes/whm/yui/assets/skins/sam/skin.css">
    <link rel="stylesheet" href="/themes/whm/css/main.css">

    <style>
        body            { font-family: Arial, Helvetica, sans-serif; font-size:13px; background:#f5f5f5; }
        .whm-container  { max-width:1100px; margin:0 auto; padding:16px; }

        .whm-page-title { font-size:1.4em; font-weight:600; color:#333; border-bottom:2px solid #0064a3; padding-bottom:8px; margin-bottom:20px; }
        .whm-page-title span { color:#0064a3; }

        .whm-panel      { background:#fff; border:1px solid #ddd; border-radius:3px; margin-bottom:16px; }
        .whm-panel-head { background:#0064a3; color:#fff; padding:8px 14px; font-weight:600; border-radius:3px 3px 0 0; display:flex; justify-content:space-between; align-items:center; }
        .whm-panel-body { padding:16px; }

        .whm-nav        { display:flex; gap:4px; margin-bottom:16px; flex-wrap:wrap; }
        .whm-nav a      { padding:6px 12px; background:#0064a3; color:#fff; text-decoration:none; border-radius:3px; font-size:.85em; }
        .whm-nav a:hover, .whm-nav a.active { background:#004d82; }

        .whm-stat-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:16px; }
        .whm-stat-box   { background:#f0f4f8; border:1px solid #d0dae4; border-radius:3px; padding:10px; text-align:center; }
        .whm-stat-box .n { font-size:1.8em; font-weight:700; color:#0064a3; }
        .whm-stat-box .l { font-size:.78em; color:#555; margin-top:2px; }

        .whm-table      { width:100%; border-collapse:collapse; }
        .whm-table th   { background:#e8eef4; padding:7px 10px; text-align:left; border-bottom:2px solid #c8d8e8; font-size:.85em; text-transform:uppercase; letter-spacing:.04em; }
        .whm-table td   { padding:7px 10px; border-bottom:1px solid #eee; font-size:.9em; }
        .whm-table tr:hover td { background:#f8fbfe; }

        .whm-badge          { display:inline-block; padding:2px 7px; border-radius:2px; font-size:.75em; font-weight:600; }
        .whm-badge-on       { background:#d4edda; color:#155724; }
        .whm-badge-off      { background:#f8d7da; color:#721c24; }

        .whm-alert      { padding:10px 14px; border-radius:3px; margin-bottom:12px; }
        .whm-alert-success { background:#d4edda; border:1px solid #c3e6cb; color:#155724; }
        .whm-alert-error   { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; }
        .whm-alert-info    { background:#d1ecf1; border:1px solid #bee5eb; color:#0c5460; }
    </style>
</head>
<body>
<div class="whm-container">

    <div class="whm-page-title">
        ⚙ <span>Supervisor Manager</span> — WHM Admin
    </div>

    <nav class="whm-nav">
        <a href="{{ route('whm.settings.index') }}"
           class="{{ request()->routeIs('whm.settings.*') ? 'active' : '' }}">
            Overview
        </a>
        <a href="{{ route('whm.packages.index') }}"
           class="{{ request()->routeIs('whm.packages.*') ? 'active' : '' }}">
            Package Limits
        </a>
        <a href="{{ route('whm.ports.index') }}"
           class="{{ request()->routeIs('whm.ports.*') ? 'active' : '' }}">
            Port Manager
        </a>
    </nav>

    {{-- Flash messages --}}
    <div id="flash-area"></div>

    @yield('content')

</div>{{-- /.whm-container --}}

<script>
    const WHM_BASE   = '{{ url("/whm") }}';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function whmFetch(url, options = {}) {
        const headers = Object.assign({
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN,
            'Accept':       'application/json',
        }, options.headers || {});
        return fetch(url, Object.assign(options, { headers }));
    }

    function showFlash(msg, type = 'success') {
        const el = document.getElementById('flash-area');
        el.innerHTML = `<div class="whm-alert whm-alert-${type}">${msg}</div>`;
        setTimeout(() => el.innerHTML = '', 6000);
    }
</script>

@yield('scripts')
</body>
</html>

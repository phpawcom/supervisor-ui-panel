<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin Version
    |--------------------------------------------------------------------------
    */
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    | Base directory for plugin data. All paths under this directory
    | must be owned by root and not writable by cPanel users.
    */
    'storage_path' => env('SUPERVISOR_PLUGIN_STORAGE', '/var/cpanel/laravel_supervisor_plugin'),

    'supervisor_conf_dir' => env('SUPERVISOR_CONF_DIR', '/etc/supervisor/conf.d'),

    'supervisorctl_bin' => env('SUPERVISORCTL_BIN', '/usr/bin/supervisorctl'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Port Allocation
    |--------------------------------------------------------------------------
    */
    'reverb' => [
        'port_range_start' => env('REVERB_PORT_START', 20000),
        'port_range_end'   => env('REVERB_PORT_END', 21000),
        'reserved_ports'   => array_map('intval', explode(',', env('REVERB_RESERVED_PORTS', ''))),
        'ssl_mode'         => env('REVERB_SSL_MODE', 'auto'), // auto | force_https | force_http
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Package Limits
    |--------------------------------------------------------------------------
    | Applied when no explicit limit exists for a WHM package name.
    */
    'default_limits' => [
        'max_workers_total'    => 3,
        'max_queue_workers'    => 2,
        'max_scheduler_workers' => 1,
        'max_reverb_workers'   => 0,
        'reverb_enabled'       => false,
        'multi_app_enabled'    => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Defaults
    |--------------------------------------------------------------------------
    */
    'worker_defaults' => [
        'queue' => [
            'numprocs'      => 1,
            'startsecs'     => 1,
            'stopwaitsecs'  => 30,
            'stopasgroup'   => true,
            'killasgroup'   => true,
        ],
        'scheduler' => [
            'numprocs'      => 1,
            'startsecs'     => 1,
            'stopwaitsecs'  => 10,
            'stopasgroup'   => true,
            'killasgroup'   => true,
        ],
        'reverb' => [
            'numprocs'      => 1,
            'startsecs'     => 3,
            'stopwaitsecs'  => 30,
            'stopasgroup'   => true,
            'killasgroup'   => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CloudLinux LVE Integration
    |--------------------------------------------------------------------------
    */
    'lve' => [
        'enabled'      => env('LVE_INTEGRATION', true),
        'lvectl_bin'   => env('LVECTL_BIN', '/usr/sbin/lvectl'),
        'warn_cpu_pct' => env('LVE_WARN_CPU_PCT', 80),
        'warn_mem_pct' => env('LVE_WARN_MEM_PCT', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Settings
    |--------------------------------------------------------------------------
    */
    'log' => [
        'tail_lines' => 50,
        'max_size'   => '10MB',
    ],

    /*
    |--------------------------------------------------------------------------
    | cPanel / WHM Auth
    |--------------------------------------------------------------------------
    */
    'cpanel' => [
        'base_path'        => env('CPANEL_BASE', '/usr/local/cpanel'),
        'plugin_name'      => 'supervisormanager',
        'plugin_display'   => 'Supervisor Manager',
        'shared_secret'    => env('CPANEL_PLUGIN_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP Binary
    |--------------------------------------------------------------------------
    */
    'php_bin' => env('PLUGIN_PHP_BIN', '/usr/bin/php'),

    /*
    |--------------------------------------------------------------------------
    | Privileged Helper
    |--------------------------------------------------------------------------
    | Root-only PHP script that performs privileged operations.
    | Called via sudo with strict argument validation.
    */
    'helper_script' => env('SUPERVISOR_HELPER', '/usr/local/cpanel/3rdparty/laravel_supervisor_plugin/scripts/supervisor_helper.php'),

];

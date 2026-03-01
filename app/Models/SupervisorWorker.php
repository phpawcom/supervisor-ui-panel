<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupervisorWorker extends Model
{
    protected $fillable = [
        'managed_laravel_app_id',
        'cpanel_user',
        'type',
        'worker_name',
        'conf_filename',
        'conf_path',
        'process_name',
        'worker_config',
        'desired_state',
        'autostart',
        'autorestart',
        'log_path',
        'error_log_path',
        'last_started_at',
        'last_restarted_at',
        'last_status',
    ];

    protected $casts = [
        'worker_config'      => 'array',
        'autostart'          => 'boolean',
        'autorestart'        => 'boolean',
        'last_started_at'    => 'datetime',
        'last_restarted_at'  => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(ManagedLaravelApp::class, 'managed_laravel_app_id');
    }

    public function assignedPort(): HasOne
    {
        return $this->hasOne(AssignedPort::class);
    }

    public function isReverb(): bool
    {
        return $this->type === 'reverb';
    }

    public function isQueue(): bool
    {
        return $this->type === 'queue';
    }

    public function isScheduler(): bool
    {
        return $this->type === 'scheduler';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignedPort extends Model
{
    protected $fillable = [
        'supervisor_worker_id',
        'cpanel_user',
        'port',
        'domain',
        'ssl_detected',
        'protocol',
        'is_active',
    ];

    protected $casts = [
        'ssl_detected' => 'boolean',
        'is_active'    => 'boolean',
        'port'         => 'integer',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(SupervisorWorker::class, 'supervisor_worker_id');
    }

    public function getWebSocketUrl(): string
    {
        return "{$this->protocol}://{$this->domain}:{$this->port}";
    }
}

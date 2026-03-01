<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedLaravelApp extends Model
{
    protected $fillable = [
        'cpanel_user',
        'app_name',
        'app_path',
        'php_binary',
        'artisan_path',
        'environment',
        'is_active',
        'detected_features',
        'last_scanned_at',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'detected_features' => 'array',
        'last_scanned_at'   => 'datetime',
    ];

    public function workers(): HasMany
    {
        return $this->hasMany(SupervisorWorker::class);
    }

    public function activeWorkers(): HasMany
    {
        return $this->hasMany(SupervisorWorker::class)->where('desired_state', 'running');
    }

    /**
     * Safely derive a short app identifier from its path.
     * Used in supervisor conf filenames.
     */
    public function getSafeAppIdentifier(): string
    {
        return preg_replace('/[^a-z0-9_]/', '_', strtolower($this->app_name));
    }

    /**
     * Return the log directory for this account.
     */
    public function getLogDirectory(): string
    {
        return "/home/{$this->cpanel_user}/logs/supervisor";
    }
}

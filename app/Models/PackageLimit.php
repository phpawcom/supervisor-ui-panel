<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageLimit extends Model
{
    protected $fillable = [
        'package_name',
        'max_workers_total',
        'max_queue_workers',
        'max_scheduler_workers',
        'max_reverb_workers',
        'reverb_enabled',
        'multi_app_enabled',
        'max_apps',
        'notes',
    ];

    protected $casts = [
        'reverb_enabled'    => 'boolean',
        'multi_app_enabled' => 'boolean',
        'max_workers_total'        => 'integer',
        'max_queue_workers'        => 'integer',
        'max_scheduler_workers'    => 'integer',
        'max_reverb_workers'       => 'integer',
        'max_apps'                 => 'integer',
    ];

    /**
     * Return a limits array, falling back to config defaults for missing values.
     */
    public function toLimitsArray(): array
    {
        $defaults = config('supervisor_plugin.default_limits');
        return [
            'max_workers_total'        => $this->max_workers_total    ?? $defaults['max_workers_total'],
            'max_queue_workers'        => $this->max_queue_workers    ?? $defaults['max_queue_workers'],
            'max_scheduler_workers'    => $this->max_scheduler_workers ?? $defaults['max_scheduler_workers'],
            'max_reverb_workers'       => $this->max_reverb_workers   ?? $defaults['max_reverb_workers'],
            'reverb_enabled'           => $this->reverb_enabled       ?? $defaults['reverb_enabled'],
            'multi_app_enabled'        => $this->multi_app_enabled    ?? $defaults['multi_app_enabled'],
            'max_apps'                 => $this->max_apps             ?? 1,
        ];
    }
}

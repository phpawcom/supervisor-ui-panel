<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_laravel_app_id')
                ->constrained('managed_laravel_apps')
                ->cascadeOnDelete();
            $table->string('cpanel_user', 64)->index();
            $table->enum('type', ['queue', 'scheduler', 'reverb']);
            $table->string('worker_name', 128);          // human-friendly name
            $table->string('conf_filename', 256)->unique(); // {user}_{app}_{type}_{index}.conf
            $table->string('conf_path', 512);
            $table->string('process_name', 256)->unique(); // supervisorctl process name
            $table->json('worker_config');                  // queue connection, numprocs, etc.
            $table->enum('desired_state', ['running', 'stopped'])->default('running');
            $table->boolean('autostart')->default(true);
            $table->boolean('autorestart')->default(true);
            $table->string('log_path', 512);
            $table->string('error_log_path', 512);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_restarted_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->timestamps();

            $table->index(['cpanel_user', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_workers');
    }
};

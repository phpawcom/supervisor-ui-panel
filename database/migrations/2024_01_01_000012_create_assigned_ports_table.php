<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assigned_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervisor_worker_id')
                ->constrained('supervisor_workers')
                ->cascadeOnDelete();
            $table->string('cpanel_user', 64)->index();
            $table->unsignedInteger('port')->unique();
            $table->string('domain', 255)->nullable();
            $table->boolean('ssl_detected')->default(false);
            $table->enum('protocol', ['ws', 'wss'])->default('ws');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assigned_ports');
    }
};

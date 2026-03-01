<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_limits', function (Blueprint $table) {
            $table->id();
            $table->string('package_name', 128)->unique()->index();
            $table->unsignedSmallInteger('max_workers_total')->default(3);
            $table->unsignedSmallInteger('max_queue_workers')->default(2);
            $table->unsignedSmallInteger('max_scheduler_workers')->default(1);
            $table->unsignedSmallInteger('max_reverb_workers')->default(0);
            $table->boolean('reverb_enabled')->default(false);
            $table->boolean('multi_app_enabled')->default(false);
            $table->unsignedSmallInteger('max_apps')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_limits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_laravel_apps', function (Blueprint $table) {
            $table->id();
            $table->string('cpanel_user', 64)->index();
            $table->string('app_name', 128);
            $table->string('app_path', 512);
            $table->string('php_binary', 256)->default('/usr/bin/php');
            $table->string('artisan_path', 512);
            $table->string('environment', 32)->default('production');
            $table->boolean('is_active')->default(true);
            $table->json('detected_features')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['cpanel_user', 'app_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_laravel_apps');
    }
};

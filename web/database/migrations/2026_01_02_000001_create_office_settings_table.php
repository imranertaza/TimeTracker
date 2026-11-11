<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_settings', function (Blueprint $table) {
            $table->id();
            $table->json('windows');
            $table->unsignedSmallInteger('idle_threshold_minutes')->default(5);
            $table->unsignedSmallInteger('screenshot_interval_minutes')->default(10);
            $table->unsignedSmallInteger('asor_namaz_minutes')->default(20);
            $table->unsignedSmallInteger('maghrib_namaz_minutes')->default(15);
            $table->string('total_time_mode', 32)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_settings');
    }
};

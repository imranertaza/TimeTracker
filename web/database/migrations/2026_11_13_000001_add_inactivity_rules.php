<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->string('inactivity_action', 16)->default('idle')->after('idle_threshold_minutes');
            $table->unsignedSmallInteger('stop_after_minutes')->default(15)->after('inactivity_action');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('inactivity_action', 16)->nullable()->after('office_windows');
            $table->unsignedSmallInteger('idle_threshold_minutes')->nullable()->after('inactivity_action');
            $table->unsignedSmallInteger('stop_after_minutes')->nullable()->after('idle_threshold_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn(['inactivity_action', 'stop_after_minutes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['inactivity_action', 'idle_threshold_minutes', 'stop_after_minutes']);
        });
    }
};

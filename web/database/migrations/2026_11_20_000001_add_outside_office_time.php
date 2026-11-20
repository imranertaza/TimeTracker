<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->boolean('away')->default(false)->after('status');
            $table->unsignedInteger('away_seconds')->default(0)->after('idle_seconds');
        });

        Schema::table('activity_minutes', function (Blueprint $table) {
            $table->unsignedInteger('away_seconds')->default(0)->after('idle_seconds');
        });

        Schema::table('office_settings', function (Blueprint $table) {
            $table->boolean('total_time_include_away')->default(false)->after('total_time_mode');
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn('total_time_include_away');
        });

        Schema::table('activity_minutes', function (Blueprint $table) {
            $table->dropColumn('away_seconds');
        });

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropColumn(['away', 'away_seconds']);
        });
    }
};

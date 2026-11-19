<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the behaviour of the action-dropdown version:
        // - action "idle" had no stop timer effect, so disable the stop timer.
        // - action "stop" had no idle timer effect, so disable the idle timer.
        DB::table('office_settings')
            ->where('inactivity_action', 'idle')
            ->update(['stop_after_minutes' => 0]);

        DB::table('office_settings')
            ->where('inactivity_action', 'stop')
            ->update(['idle_threshold_minutes' => 0]);

        DB::table('users')
            ->where('inactivity_action', 'idle')
            ->whereNotNull('stop_after_minutes')
            ->update(['stop_after_minutes' => 0]);

        DB::table('users')
            ->where('inactivity_action', 'stop')
            ->whereNotNull('idle_threshold_minutes')
            ->update(['idle_threshold_minutes' => 0]);

        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn('inactivity_action');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inactivity_action');
        });

        Schema::table('office_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('stop_after_minutes')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->string('inactivity_action', 16)->default('idle')->after('idle_threshold_minutes');
            $table->unsignedSmallInteger('stop_after_minutes')->default(15)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('inactivity_action', 16)->nullable()->after('office_windows');
        });
    }
};

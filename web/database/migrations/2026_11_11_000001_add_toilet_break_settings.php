<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('toilet_minutes')->default(15)->after('maghrib_namaz_minutes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('toilet_minutes')->nullable()->after('office_windows');
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn('toilet_minutes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('toilet_minutes');
        });
    }
};

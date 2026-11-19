<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('johor_namaz_minutes')->default(20)->after('asor_namaz_minutes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('asor_namaz_minutes')->nullable()->after('office_windows');
            $table->unsignedSmallInteger('johor_namaz_minutes')->nullable()->after('asor_namaz_minutes');
            $table->unsignedSmallInteger('maghrib_namaz_minutes')->nullable()->after('johor_namaz_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn('johor_namaz_minutes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['asor_namaz_minutes', 'johor_namaz_minutes', 'maghrib_namaz_minutes']);
        });
    }
};

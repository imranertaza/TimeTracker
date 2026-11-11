<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('minute_start');
            $table->unsignedInteger('keystrokes')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);
            $table->boolean('is_idle')->default(false);
            $table->timestamps();

            $table->unique(['work_session_id', 'minute_start']);
            $table->index(['user_id', 'minute_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_minutes');
    }
};

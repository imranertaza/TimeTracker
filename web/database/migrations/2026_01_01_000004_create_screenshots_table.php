<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamp('taken_at');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};

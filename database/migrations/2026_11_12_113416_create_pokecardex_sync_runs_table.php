<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pokecardex_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome')->nullable();
            $table->unsignedInteger('pokecardex_copies')->nullable();
            $table->unsignedInteger('cardtrader_copies')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'outcome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_sync_runs');
    }
};

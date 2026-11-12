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
        Schema::create('pokecardex_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('blueprint_id');
            $table->string('condition', 2);
            $table->string('language', 2);
            $table->boolean('reverse');
            $table->boolean('first_edition');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['user_id', 'blueprint_id', 'condition', 'language', 'reverse', 'first_edition'], 'pokecardex_sync_states_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_sync_states');
    }
};

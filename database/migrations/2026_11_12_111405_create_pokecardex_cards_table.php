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
        Schema::create('pokecardex_cards', function (Blueprint $table) {
            $table->unsignedInteger('id_card')->primary();
            $table->unsignedInteger('id_serie')->index();
            $table->string('num_card')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedInteger('id_cardmarket')->nullable()->index();
            $table->json('versions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_cards');
    }
};

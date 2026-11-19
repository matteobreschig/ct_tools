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
        Schema::create('pokecardex_card_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_card');
            $table->unsignedInteger('id_version');
            $table->unsignedInteger('blueprint_id')->index();
            $table->string('source');
            $table->timestamps();
            $table->unique(['id_card', 'id_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_card_matches');
    }
};

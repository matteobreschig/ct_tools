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
        Schema::create('pokecardex_series_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_serie');
            $table->unsignedInteger('id_version');
            $table->foreignId('expansion_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['id_serie', 'id_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_series_rules');
    }
};

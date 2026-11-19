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
        Schema::create('pokecardex_series', function (Blueprint $table) {
            $table->unsignedInteger('id_serie')->primary();
            $table->string('lang', 2)->default('en');
            $table->string('code')->nullable();
            $table->string('name_us')->nullable();
            $table->unsignedInteger('id_cardmarket')->nullable();
            $table->foreignId('expansion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expansion_source')->nullable();
            $table->timestamp('cards_loaded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokecardex_series');
    }
};

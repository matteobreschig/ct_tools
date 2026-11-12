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
        Schema::create('ct_expansion_blueprints', function (Blueprint $table) {
            $table->unsignedInteger('blueprint_id')->primary();
            $table->unsignedInteger('expansion_ct_id')->index();
            $table->string('name')->nullable();
            $table->string('collector_number')->nullable();
            $table->unsignedInteger('cardmarket_product_id')->nullable()->index();
            $table->json('cardmarket_product_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ct_expansion_blueprints');
    }
};

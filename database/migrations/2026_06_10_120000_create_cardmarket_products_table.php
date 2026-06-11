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
        Schema::create('cardmarket_products', function (Blueprint $table) {
            $table->id();
            $table->integer('cardmarket_product_id');
            $table->unique('cardmarket_product_id');
            $table->integer('cardmarket_metacard_id')->index();
            $table->string('name');
            $table->integer('cardmarket_expansion_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cardmarket_products');
    }
};

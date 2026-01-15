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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('product_id');
            $table->unique('product_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->bigInteger('price')->default(0);
            $table->boolean('sale')->default(false);
            $table->integer('blueprint_id');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('expansion_id')->nullable()->constrained('expansions')->nullOnDelete();
            $table->string('collector_number');
            $table->string('condition');
            $table->boolean('tournament_legal');
            $table->boolean('reverse');
            $table->string('language');
            $table->boolean('signed');
            $table->string('rarity');
            $table->boolean('altered');
            $table->boolean('first_edition');
            $table->dateTime('last_update');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

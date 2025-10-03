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
        Schema::create('tracked_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('blueprint_id');
            $table->string('blueprint_url');
            $table->string('blueprint_name')->nullable();
            $table->string('blueprint_expansion_code')->nullable();
            $table->string('blueprint_collector_number')->nullable();
            $table->boolean('active')->default(true);
            $table->bigInteger('price')->default(0);
            $table->integer('minimum_quantity')->default(1);
            $table->text('conditions')->nullable();
            $table->text('languages')->nullable();
            $table->boolean('ct_zero_only')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracked_blueprints');
    }
};

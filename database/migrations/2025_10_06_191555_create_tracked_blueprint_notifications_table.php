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
        Schema::create('tracked_blueprint_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_blueprint_id')->constrained('tracked_blueprints')->cascadeOnDelete();
            $table->bigInteger('price')->default(0);
            $table->dateTime('datetime')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracked_blueprint_notifications');
    }
};

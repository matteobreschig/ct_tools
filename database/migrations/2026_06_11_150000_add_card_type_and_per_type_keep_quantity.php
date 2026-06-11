<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipo di carta sul prodotto (pokemon/trainer/energy, da TCGdex) e
     * minimi di vendita dinamica distinti per tipo sull'utente.
     * Priorità: deroga del gruppo > minimo per tipo > minimo globale.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('card_type')->nullable()->index()->after('rarity');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('keep_quantity_pokemon')->nullable()->after('keep_quantity');
            $table->unsignedSmallInteger('keep_quantity_trainer')->nullable()->after('keep_quantity_pokemon');
            $table->unsignedSmallInteger('keep_quantity_energy')->nullable()->after('keep_quantity_trainer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['keep_quantity_pokemon', 'keep_quantity_trainer', 'keep_quantity_energy']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('card_type');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rimuove l'impianto di raggruppamento basato sull'idMetacard Cardmarket,
     * sostituito dal meta_name CardTrader. La vista card_groups usa già
     * meta_name, quindi droppare tabella e colonna è sicuro.
     */
    public function up(): void
    {
        Schema::dropIfExists('cardmarket_products');

        // L'indice va rimosso prima della colonna: MySQL lo fa da sé, SQLite
        // no e si ferma con "error in index ... after drop column".
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['cardmarket_metacard_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cardmarket_metacard_id');
        });
    }

    /**
     * Ricrea tabella e colonna (vuote: i dati vanno ri-sincronizzati).
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('cardmarket_metacard_id')->nullable()->index()->after('cardmarket_product_id');
        });

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
};

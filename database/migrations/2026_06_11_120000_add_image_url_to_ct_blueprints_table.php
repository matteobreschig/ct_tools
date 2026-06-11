<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immagine del blueprint CardTrader: ripiego per le carte assenti da
     * TCGdex (promo, Prize Pack...). Convenzione: NULL = non ancora
     * richiesta, '' = richiesta ma il blueprint non ha immagine.
     */
    public function up(): void
    {
        Schema::table('ct_blueprints', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ct_blueprints', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};

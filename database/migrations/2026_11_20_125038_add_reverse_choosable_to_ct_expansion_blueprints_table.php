<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Il catalogo delle espansioni si riscarica al prossimo giro, così la
     * colonna si riempie per tutti i blueprint già salvati.
     */
    public function up(): void
    {
        Schema::table('ct_expansion_blueprints', function (Blueprint $table) {
            $table->boolean('reverse_choosable')->nullable()->after('version');
        });

        DB::table('expansions')->update(['blueprints_loaded_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ct_expansion_blueprints', function (Blueprint $table) {
            $table->dropColumn('reverse_choosable');
        });
    }
};

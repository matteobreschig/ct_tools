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
        Schema::table('pokecardex_series', function (Blueprint $table) {
            $table->boolean('ignored')->default(false)->after('expansion_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pokecardex_series', function (Blueprint $table) {
            $table->dropColumn('ignored');
        });
    }
};

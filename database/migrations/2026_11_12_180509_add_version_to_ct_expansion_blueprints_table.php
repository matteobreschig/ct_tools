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
        Schema::table('ct_expansion_blueprints', function (Blueprint $table) {
            $table->string('version')->nullable()->after('collector_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ct_expansion_blueprints', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};

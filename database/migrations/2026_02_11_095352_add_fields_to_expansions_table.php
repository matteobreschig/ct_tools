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
        Schema::table('expansions', function (Blueprint $table) {
            $table->string('tcgdex_code')->nullable()->after('code');
            $table->string('name_en')->nullable()->after('name');
            $table->date('release_date')->nullable()->after('name_en');
            $table->text('image_url')->nullable()->after('release_date');
            $table->text('symbol_url')->nullable()->after('image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expansions', function (Blueprint $table) {
            $table->dropColumn('tcgdex_code');
            $table->dropColumn('name_en');
            $table->dropColumn('release_date');
            $table->dropColumn('image_url');
            $table->dropColumn('symbol_url');
        });
    }
};

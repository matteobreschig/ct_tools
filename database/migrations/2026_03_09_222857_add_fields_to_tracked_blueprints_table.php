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
        Schema::table('tracked_blueprints', function (Blueprint $table) {
            $table->dropColumn('blueprint_expansion_code');
            $table->foreignId('blueprint_expansion_id')->nullable()->after('blueprint_name')->constrained('expansions')->nullOnDelete();
            $table->text('blueprint_image_url')->nullable()->after('blueprint_collector_number');
            $table->integer('cardmarket_product_id')->nullable()->after('ct_zero_only');
            $table->bigInteger('cardmarket_price_avg')->nullable()->after('cardmarket_product_id');
            $table->bigInteger('cardmarket_price_low')->nullable()->after('cardmarket_price_avg');
            $table->bigInteger('cardmarket_price_trend')->nullable()->after('cardmarket_price_low');
            $table->bigInteger('cardmarket_price_avg1')->nullable()->after('cardmarket_price_trend');
            $table->bigInteger('cardmarket_price_avg7')->nullable()->after('cardmarket_price_avg1');
            $table->bigInteger('cardmarket_price_avg30')->nullable()->after('cardmarket_price_avg7');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracked_blueprints', function (Blueprint $table) {
            $table->string('blueprint_expansion_code')->nullable();
            $table->dropForeign(['blueprint_expansion_id']);
            $table->dropColumn('blueprint_expansion_id');
            $table->dropColumn('blueprint_image_url');
            $table->dropColumn('cardmarket_product_id');
            $table->dropColumn('cardmarket_price_avg');
            $table->dropColumn('cardmarket_price_low');
            $table->dropColumn('cardmarket_price_trend');
            $table->dropColumn('cardmarket_price_avg1');
            $table->dropColumn('cardmarket_price_avg7');
            $table->dropColumn('cardmarket_price_avg30');
        });
    }
};

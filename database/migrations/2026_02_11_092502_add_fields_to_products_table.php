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
        Schema::table('products', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('name');
            $table->integer('cardmarket_product_id')->nullable()->after('blueprint_id');
            $table->string('regulation_mark')->nullable()->after('condition');
            $table->boolean('tournament_extended_legal')->nullable()->after('tournament_legal');
            $table->bigInteger('cardmarket_price_avg')->nullable()->after('first_edition');
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
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_url');
            $table->dropColumn('cardmarket_product_id');
            $table->dropColumn('regulation_mark');
            $table->dropColumn('tournament_extended_legal');
            $table->dropColumn('cardmarket_price_avg');
            $table->dropColumn('cardmarket_price_low');
            $table->dropColumn('cardmarket_price_trend');
            $table->dropColumn('cardmarket_price_avg1');
            $table->dropColumn('cardmarket_price_avg7');
            $table->dropColumn('cardmarket_price_avg30');
        });
    }
};

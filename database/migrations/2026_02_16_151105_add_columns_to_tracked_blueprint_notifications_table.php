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
        Schema::table('tracked_blueprint_notifications', function (Blueprint $table) {
            $table->integer('product_id')->after('tracked_blueprint_id');
            $table->string('ct_user_name')->after('ct_zero');
            $table->string('ct_user_country_code')->after('ct_user_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracked_blueprint_notifications', function (Blueprint $table) {
            $table->dropColumn('product_id');
            $table->dropColumn('ct_user_name');
            $table->dropColumn('ct_user_country_code');
        });
    }
};

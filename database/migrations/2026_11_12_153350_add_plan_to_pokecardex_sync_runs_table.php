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
        Schema::table('pokecardex_sync_runs', function (Blueprint $table) {
            $table->longText('plan')->nullable()->after('message');
            $table->string('fingerprint', 40)->nullable()->after('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pokecardex_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['plan', 'fingerprint']);
        });
    }
};

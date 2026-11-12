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
        Schema::table('pokecardex_accounts', function (Blueprint $table) {
            $table->text('session_cookies')->nullable()->after('password');
            $table->string('decrypt_key')->nullable()->after('session_cookies');
            $table->timestamp('credentials_rejected_at')->nullable()->after('decrypt_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pokecardex_accounts', function (Blueprint $table) {
            $table->dropColumn(['session_cookies', 'decrypt_key', 'credentials_rejected_at']);
        });
    }
};

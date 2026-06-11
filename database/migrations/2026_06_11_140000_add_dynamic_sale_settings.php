<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vendita dinamica: minimo globale di copie da tenere per carta
     * (null = motore spento) e deroghe per singolo gruppo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('keep_quantity')->nullable()->after('telegram_chat_id');
        });

        Schema::create('card_group_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('group_key');
            $table->unsignedSmallInteger('keep_quantity');
            $table->timestamps();
            $table->unique(['user_id', 'group_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_group_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('keep_quantity');
        });
    }
};

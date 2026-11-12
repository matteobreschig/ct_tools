<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elimina le tabelle del monitoraggio Amazon, tolto dall'applicazione.
     * Le migrazioni che le creavano non esistono più, quindi su un'installazione
     * nuova le tabelle mancano e il drop non fa nulla.
     */
    public function up(): void
    {
        Schema::dropIfExists('amazon_watch_events');
        Schema::dropIfExists('amazon_watch_checks');
        Schema::dropIfExists('amazon_watches');
    }

    /**
     * Il monitoraggio Amazon non torna: niente da ricreare.
     */
    public function down(): void {}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceCardGroupsView('CREATE OR REPLACE VIEW card_groups AS
            SELECT
                p.user_id,
                p.cardmarket_metacard_id,
                MIN(p.name) AS name,
                MIN(p.image_url) AS image_url,
                COUNT(*) AS versions_count,
                SUM(p.quantity) AS total_quantity,
                SUM(CASE WHEN p.sale = 1 THEN p.quantity ELSE 0 END) AS on_sale_quantity,
                MIN(p.cardmarket_price_low) AS price_low
            FROM products p
            WHERE p.cardmarket_metacard_id IS NOT NULL
            GROUP BY p.user_id, p.cardmarket_metacard_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceCardGroupsView('DROP VIEW IF EXISTS card_groups');
    }

    /**
     * La vista card_groups usa sintassi MySQL (CREATE OR REPLACE, CONCAT,
     * COLLATE) che gli altri driver rifiutano. Sul SQLite dei test la vista
     * non serve, quindi si salta: in produzione gira MySQL e il comportamento
     * resta identico.
     */
    private function replaceCardGroupsView(string $sql): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement($sql);
    }
};

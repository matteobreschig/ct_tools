<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot del raggruppamento: la chiave diventa il `meta_name` del blueprint
     * CardTrader (identità della carta, sincrona, copre promo/Prize Pack),
     * al posto del metacard Cardmarket.
     */
    public function up(): void
    {
        Schema::table('ct_blueprints', function (Blueprint $table) {
            $table->string('meta_name')->nullable()->index()->after('blueprint_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_name')->nullable()->index()->after('cardmarket_metacard_id');
        });

        $this->replaceCardGroupsView("CREATE OR REPLACE VIEW card_groups AS
            SELECT
                CONCAT(
                    CASE WHEN p.meta_name IS NOT NULL THEN 'n:' ELSE 'b:' END,
                    p.user_id, ':',
                    COALESCE(p.meta_name, CAST(MAX(p.blueprint_id) AS CHAR))
                ) AS group_key,
                p.user_id,
                CASE WHEN p.meta_name IS NOT NULL THEN 'meta' ELSE 'blueprint' END AS group_type,
                p.meta_name,
                MAX(p.blueprint_id) AS blueprint_id,
                MIN(p.name) AS name,
                MIN(p.image_url) AS image_url,
                COUNT(*) AS versions_count,
                SUM(p.quantity) AS total_quantity,
                SUM(CASE WHEN p.sale = 1 THEN p.quantity ELSE 0 END) AS on_sale_quantity,
                MIN(p.cardmarket_price_low) AS price_low
            FROM products p
            GROUP BY
                p.user_id,
                p.meta_name,
                CASE WHEN p.meta_name IS NULL THEN p.blueprint_id ELSE NULL END");
    }

    /**
     * Ripristina la vista basata sul metacard Cardmarket e rimuove le colonne.
     */
    public function down(): void
    {
        $this->replaceCardGroupsView("CREATE OR REPLACE VIEW card_groups AS
            SELECT
                CONCAT(
                    CASE WHEN p.cardmarket_metacard_id IS NOT NULL THEN 'm:' ELSE 'b:' END,
                    p.user_id, ':',
                    COALESCE(p.cardmarket_metacard_id, MAX(p.blueprint_id))
                ) AS group_key,
                p.user_id,
                CASE WHEN p.cardmarket_metacard_id IS NOT NULL THEN 'metacard' ELSE 'blueprint' END AS group_type,
                p.cardmarket_metacard_id,
                MAX(p.blueprint_id) AS blueprint_id,
                MIN(p.name) AS name,
                MIN(p.image_url) AS image_url,
                COUNT(*) AS versions_count,
                SUM(p.quantity) AS total_quantity,
                SUM(CASE WHEN p.sale = 1 THEN p.quantity ELSE 0 END) AS on_sale_quantity,
                MIN(p.cardmarket_price_low) AS price_low
            FROM products p
            GROUP BY
                p.user_id,
                p.cardmarket_metacard_id,
                CASE WHEN p.cardmarket_metacard_id IS NULL THEN p.blueprint_id ELSE NULL END");

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('meta_name');
        });

        Schema::table('ct_blueprints', function (Blueprint $table) {
            $table->dropColumn('meta_name');
        });
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Vista unificata: raggruppa per metacard Cardmarket quando presente,
     * altrimenti ricade sul blueprint (carte senza mapping Cardmarket).
     * `group_key` è la chiave primaria sintetica, `group_type` distingue i due casi.
     */
    public function up(): void
    {
        DB::statement("CREATE OR REPLACE VIEW card_groups AS
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
    }

    /**
     * Ripristina la vista precedente (solo metacard).
     */
    public function down(): void
    {
        DB::statement('CREATE OR REPLACE VIEW card_groups AS
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
};

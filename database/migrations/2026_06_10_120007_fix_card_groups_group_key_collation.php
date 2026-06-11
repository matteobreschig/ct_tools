<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Il CONCAT che costruisce group_key risultava in collation utf8mb4_bin
     * (per via del CAST del blueprint_id), incompatibile col parametro del
     * binding. Forzo utf8mb4_unicode_ci, la collation della connessione.
     */
    public function up(): void
    {
        DB::statement("CREATE OR REPLACE VIEW card_groups AS
            SELECT
                CONCAT(
                    CASE WHEN p.meta_name IS NOT NULL THEN 'n:' ELSE 'b:' END,
                    p.user_id, ':',
                    COALESCE(p.meta_name, CAST(MAX(p.blueprint_id) AS CHAR))
                ) COLLATE utf8mb4_unicode_ci AS group_key,
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
     * Ripristina la vista senza il COLLATE esplicito.
     */
    public function down(): void
    {
        DB::statement("CREATE OR REPLACE VIEW card_groups AS
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
};

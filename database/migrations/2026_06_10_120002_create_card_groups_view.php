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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS card_groups');
    }
};

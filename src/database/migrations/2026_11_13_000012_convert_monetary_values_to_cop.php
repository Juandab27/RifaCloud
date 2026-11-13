<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time conversion from cents to whole Colombian pesos (COP).
 *
 * Before this migration, price_per_number, total_amount, price_at_purchase and
 * amount stored cents. After it, the same columns store integer COP. The
 * migration refuses to run if any historical value is not an exact multiple of
 * 100, so no amount is rounded or silently changed.
 *
 * This migration is part of the S11 money-unit change and must be deployed
 * together with the code that stops dividing/multiplying by 100.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'raffles.price_per_number',
        'orders.total_amount',
        'order_items.price_at_purchase',
        'payments.amount',
    ];

    public function up(): void
    {
        $this->assertExactDivision();

        DB::transaction(function (): void {
            DB::statement('UPDATE order_items SET price_at_purchase = price_at_purchase DIV 100');
            DB::statement('UPDATE orders SET total_amount = total_amount DIV 100');
            DB::statement('UPDATE payments SET amount = amount DIV 100');
            DB::statement('UPDATE raffles SET price_per_number = price_per_number DIV 100');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('UPDATE raffles SET price_per_number = price_per_number * 100');
            DB::statement('UPDATE payments SET amount = amount * 100');
            DB::statement('UPDATE orders SET total_amount = total_amount * 100');
            DB::statement('UPDATE order_items SET price_at_purchase = price_at_purchase * 100');
        });
    }

    private function assertExactDivision(): void
    {
        foreach (self::COLUMNS as $column) {
            [$table, $field] = explode('.', $column);

            if (DB::table($table)->whereRaw("{$field} % 100 <> 0")->exists()) {
                throw new RuntimeException(
                    "Cannot convert {$column} to COP: at least one value is not an exact multiple of 100. No data was changed.",
                );
            }
        }
    }
};

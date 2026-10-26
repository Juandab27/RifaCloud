<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffle_numbers', function (Blueprint $table) {
            // The column existed since Phase 1; the FK was deferred until the
            // orders table was introduced in Phase 2 (03-data-model.md).
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('raffle_numbers', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment service creates at most one payment record per order:
     * PENDING is resumed idempotently and UNKNOWN/final states block a new
     * checkout. Enforce that invariant at the database level as well.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });
    }
};

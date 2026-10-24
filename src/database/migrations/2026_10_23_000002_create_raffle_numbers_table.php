<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->constrained('raffles')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->enum('status', ['AVAILABLE', 'RESERVED', 'SOLD'])->default('AVAILABLE');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('sold_at')->nullable();

            // The FK to orders is added in Phase 2, once the orders table exists
            // (sequencing decision approved for Phase 1). Column only for now.
            $table->unsignedBigInteger('order_id')->nullable();

            $table->timestamps();

            $table->unique(['raffle_id', 'number']);
            $table->index(['raffle_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_numbers');
    }
};

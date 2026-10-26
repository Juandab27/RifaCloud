<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('raffle_number_id')->constrained('raffle_numbers')->restrictOnDelete();
            $table->unsignedInteger('price_at_purchase');
            $table->timestamps();

            $table->unique(['order_id', 'raffle_number_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

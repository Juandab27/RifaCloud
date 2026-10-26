<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('raffle_id')->constrained('raffles')->restrictOnDelete();
            $table->string('order_number', 50)->unique();
            $table->unsignedInteger('total_amount');
            $table->enum('status', [
                'PENDING_PAYMENT',
                'PAID',
                'REJECTED',
                'EXPIRED',
                'PAYMENT_REVIEW',
                'REQUIRES_REFUND',
            ])->default('PENDING_PAYMENT');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('raffle_id');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

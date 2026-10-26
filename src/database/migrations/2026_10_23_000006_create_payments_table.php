<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('provider', [
                'MERCADOPAGO',
                'PAYPAL',
            ]);
            $table->string('external_id', 255);
            $table->enum('status', [
                'PENDING',
                'APPROVED',
                'REJECTED',
                'UNKNOWN',
                'REFUND_REQUIRED',
            ])->default('PENDING');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->text('checkout_url')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

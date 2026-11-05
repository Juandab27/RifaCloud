<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency and audit trail for provider notifications
     * (03-data-model.md, payment_webhook_events; NFR-003 / AC-017).
     *
     * This migration is additive: it only creates a new table. Existing
     * orders, payments and raffle numbers are not modified.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', [
                'MERCADOPAGO',
                'PAYPAL',
            ]);
            $table->string('provider_event_id', 255);
            $table->string('event_type', 100);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->enum('status', [
                'RECEIVED',
                'PROCESSED',
                'FAILED',
                'IGNORED',
            ])->default('RECEIVED');
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('status');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

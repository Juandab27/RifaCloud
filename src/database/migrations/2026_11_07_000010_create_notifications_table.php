<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-010/NFR-005: asynchronous, retryable email queue. The scheduler is
     * the only writer; a delivery failure never changes order/payment state.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('body');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'RETRY'])->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['type', 'order_id']);
            $table->index('status');
            $table->index('order_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

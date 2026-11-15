<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive raffle proposals.
 *
 * A registered user submits a proposal; the contact snapshot is taken from
 * the authenticated user row by the backend, never from the request body.
 * Approval is only a moderation decision: it does not create a raffle, an
 * order, a payment or a notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description');

            // Snapshot of the proposer's contact at submission time. Kept so a
            // later profile change does not rewrite what was received.
            $table->string('contact_name', 255);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 32);

            // Consent evidence owned by this domain. The version is set by the
            // server, never accepted from the client.
            $table->string('privacy_version', 20);
            $table->timestamp('privacy_accepted_at');

            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])
                ->default('PENDING');

            // Internal moderation note, visible only to administrators.
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'raffle_proposals_status_index');
            $table->index(['user_id', 'created_at'], 'raffle_proposals_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_proposals');
    }
};

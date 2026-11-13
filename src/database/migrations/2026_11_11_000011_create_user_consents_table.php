<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S11: proof of informed consent for the privacy policy, the prototype terms
 * and the optional promotional communications.
 *
 * One row per user, consent type and document version. The unique key makes
 * the recorder idempotent, so a retried registration request cannot duplicate
 * evidence. `restrictOnDelete` deliberately prevents deleting a user while
 * consent proof exists: account deletion must first decide what evidence is
 * retained under the applicable legal/contractual obligations.
 *
 * ADDITIVE migration. It must not be applied to the main `rifacloud` schema
 * without explicit authorization. Applied to `rifacloud` on 2026-11-12 with
 * explicit authorization and verified with a controlled registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('document_version', 64);
            $table->boolean('accepted');
            $table->timestamp('accepted_at');
            $table->string('source', 32)->default('REGISTRATION');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'document_version']);
            $table->index(['type', 'document_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};

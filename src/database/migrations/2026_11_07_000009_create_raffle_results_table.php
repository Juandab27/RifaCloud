<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-014: one manually published result per raffle. The winning number is
     * validated against a SOLD raffle_numbers row by raffles-service; this
     * table is written only by raffles-service.
     */
    public function up(): void
    {
        Schema::create('raffle_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raffle_id')->unique()->constrained('raffles')->cascadeOnDelete();
            $table->unsignedInteger('winning_number');
            $table->foreignId('winning_raffle_number_id')->nullable()
                ->constrained('raffle_numbers')->nullOnDelete();
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at');
            $table->text('evidence_description')->nullable();
            $table->string('evidence_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raffle_results');
    }
};

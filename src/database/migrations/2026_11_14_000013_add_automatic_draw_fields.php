<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S12: additive fields for opt-in automatic sales closure and draw.
 *
 * No existing row is modified. Every historical raffle keeps
 * draw_mode=MANUAL and draw_at=NULL, and every existing result keeps
 * published_source=MANUAL and its original publisher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->enum('draw_mode', ['MANUAL', 'AUTOMATIC'])
                ->default('MANUAL')
                ->after('status');
            $table->timestamp('draw_at')->nullable()->after('ends_at');
            $table->string('draw_blocked_reason', 100)->nullable()->after('draw_at');
            $table->timestamp('draw_attempted_at')->nullable()->after('draw_blocked_reason');
            $table->unsignedInteger('draw_attempts')->default(0)->after('draw_attempted_at');

            $table->index(['draw_mode', 'draw_at', 'status'], 'raffles_draw_due_index');
        });

        Schema::table('raffle_results', function (Blueprint $table) {
            $table->foreignId('published_by')->nullable()->change();
            $table->enum('published_source', ['MANUAL', 'AUTOMATIC'])
                ->default('MANUAL')
                ->after('published_by');

            $table->foreignId('winner_order_id')->nullable()
                ->after('published_at')
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('winner_user_id')->nullable()
                ->after('winner_order_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('public_winner_name', 120)->nullable()->after('winner_user_id');
            $table->unsignedInteger('candidate_count')->nullable()->after('public_winner_name');
            $table->char('candidate_hash', 64)->nullable()->after('candidate_count');
            $table->unsignedInteger('selected_index')->nullable()->after('candidate_hash');
            $table->string('random_method', 50)->nullable()->after('selected_index');
            $table->json('draw_evidence')->nullable()->after('random_method');
            $table->string('draw_version', 20)->nullable()->after('draw_evidence');
        });
    }

    public function down(): void
    {
        Schema::table('raffle_results', function (Blueprint $table) {
            $table->dropForeign(['winner_order_id']);
            $table->dropForeign(['winner_user_id']);
            $table->dropColumn([
                'winner_order_id',
                'winner_user_id',
                'public_winner_name',
                'candidate_count',
                'candidate_hash',
                'selected_index',
                'random_method',
                'draw_evidence',
                'draw_version',
                'published_source',
            ]);

            // Automatic results have no publisher. This rollback intentionally
            // fails if any automatic result exists rather than inventing an
            // administrator identity.
            $table->foreignId('published_by')->nullable(false)->change();
        });

        Schema::table('raffles', function (Blueprint $table) {
            $table->dropIndex('raffles_draw_due_index');
            $table->dropColumn([
                'draw_mode',
                'draw_at',
                'draw_blocked_reason',
                'draw_attempted_at',
                'draw_attempts',
            ]);
        });
    }
};

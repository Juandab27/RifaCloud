<?php

namespace Tests\Feature\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency evidence for webhook idempotency.
 *
 * The test runner is a single PHP process, so two HTTP requests cannot be
 * executed literally at the same instant. These tests prove the database-level
 * serialization used by the service: the unique key blocks a second writer
 * until the first transaction finishes.
 */
class PaymentWebhookConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::purge('mysql_second');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_second');

        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_two_connections_cannot_process_the_same_event_key_at_the_same_time(): void
    {
        $attributes = $this->eventAttributes();

        $first = DB::connection();
        $second = DB::connection('mysql_second');

        $first->beginTransaction();

        try {
            $first->table('payment_webhook_events')->insert($attributes);

            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $second->beginTransaction();

            try {
                $second->table('payment_webhook_events')->insert($attributes);

                $this->fail('The second connection inserted the same event key while the first held it.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('1205', $exception->getMessage());
            } finally {
                if ($second->transactionLevel() > 0) {
                    $second->rollBack();
                }
            }
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public function test_the_unique_constraint_rejects_a_duplicate_event_key(): void
    {
        $attributes = $this->eventAttributes();

        DB::table('payment_webhook_events')->insert($attributes);

        $this->expectException(QueryException::class);

        DB::table('payment_webhook_events')->insert($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttributes(): array
    {
        $now = now();

        return [
            'provider' => 'MERCADOPAGO',
            'provider_event_id' => str_repeat('a', 64),
            'event_type' => 'payment',
            'payload' => json_encode(['mp_status' => 'approved']),
            'processed_at' => null,
            'status' => 'RECEIVED',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}

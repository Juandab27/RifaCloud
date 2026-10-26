<?php

namespace Tests\Feature\Orders;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use CreatesRaffles;
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

        // DatabaseTruncation does not wrap tests in a transaction, so clean up
        // explicitly to avoid leaking committed rows into later test classes.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_second_reservation_for_the_same_number_is_rejected(): void
    {
        $raffle = $this->createRaffle();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertCreated();

        $this->actingAs($secondUser)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NUMBERS_UNAVAILABLE');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(
            NumberStatus::RESERVED,
            RaffleNumber::query()->where('number', 1)->firstOrFail()->status,
        );
    }

    public function test_row_lock_blocks_a_concurrent_reservation(): void
    {
        $raffle = $this->createRaffle();
        $number = RaffleNumber::query()->where('number', 1)->firstOrFail();

        $first = DB::connection();
        $second = DB::connection('mysql_second');

        $first->beginTransaction();

        try {
            $locked = $first->table('raffle_numbers')
                ->where('id', $number->id)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($locked);

            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $second->beginTransaction();

            try {
                $second->table('raffle_numbers')
                    ->where('id', $number->id)
                    ->lockForUpdate()
                    ->first();

                $this->fail('The second connection acquired a row lock held by the first.');
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
    }
}

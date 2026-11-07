<?php

namespace Tests\Feature\Payments;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

/**
 * Concurrency evidence for payments.
 *
 * The test runner is a single PHP process and neither pcntl nor parallel is
 * available, so true simultaneous HTTP requests cannot be executed here. These
 * tests use a second database connection to prove the row lock used by both
 * checkout and expiry serializes writers, and they exercise both orderings of
 * the verification/expiry race deterministically.
 *
 * Not covered: two PHP requests executing literally at the same instant. The
 * lock-level test is the closest reproducible proof in this environment.
 */
class PaymentConcurrencyTest extends TestCase
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

        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_order_lock_serializes_concurrent_checkout_writers(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1]);

        $first = DB::connection();
        $second = DB::connection('mysql_second');

        $first->beginTransaction();

        try {
            $first->table('orders')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $second->beginTransaction();

            try {
                $second->table('orders')
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->first();

                $this->fail('The second connection acquired the order lock held by the first.');
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

    public function test_two_interleaved_checkout_connections_cannot_create_a_duplicate_payment(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1]);

        $first = DB::connection('mysql');
        $second = DB::connection('mysql_second');
        $service = app(PaymentService::class);

        $first->beginTransaction();

        try {
            DB::setDefaultConnection('mysql');

            // First checkout locks the order and inserts its PENDING payment,
            // but the outer transaction stays open (as if the request is still
            // in flight).
            $payment = $service->createCheckout($user, $order);

            DB::setDefaultConnection('mysql_second');
            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                // The second connection must not get past the order lock and
                // must not create another payment.
                $service->createCheckout($user, $order);

                $this->fail('The second checkout acquired the lock held by the first.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('1205', $exception->getMessage());
            }

            DB::setDefaultConnection('mysql');
            $first->commit();

            DB::setDefaultConnection('mysql_second');
            $reused = $service->createCheckout($user, $order);

            $this->assertSame($payment->id, $reused->id);
            $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        } finally {
            DB::setDefaultConnection('mysql');

            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }
    }

    public function test_repeated_checkout_never_creates_a_second_payment(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1]);

        $first = $this->createCheckout($user, $order);
        $second = $this->createCheckout($user, $order, false);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_verification_before_expiry_wins_and_expiry_does_not_release(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->travel(11)->minutes();

        $this->assertSame(
            0,
            app(\App\Modules\Orders\Services\ReservationExpiryService::class)->expire(),
        );

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentState::APPROVED, $payment->refresh()->status);
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());

        $this->travelBack();
    }

    public function test_expiry_before_verification_forces_refund_without_reassignment(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1, 2]);
        $payment = $this->createCheckout($user, $order);

        $this->travel(11)->minutes();

        $this->assertSame(
            1,
            app(\App\Modules\Orders\Services\ReservationExpiryService::class)->expire(),
        );

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'REQUIRES_REFUND')
            ->assertJsonPath('data.payments.0.status', 'REFUND_REQUIRED');

        $this->assertSame(OrderStatus::REQUIRES_REFUND, $order->refresh()->status);
        $this->assertSame(PaymentState::REFUND_REQUIRED, $payment->refresh()->status);
        $this->assertTrue((bool) ($payment->response_data['late_approval'] ?? false));
        $this->assertSame(0, RaffleNumber::query()->where('status', NumberStatus::SOLD->value)->count());
        $this->assertSame(2, RaffleNumber::query()->where('status', NumberStatus::AVAILABLE->value)->count());

        $this->travelBack();
    }

    private function createCheckout(User $user, Order $order, bool $expectCreated = true): Payment
    {
        $response = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/payments");

        if ($expectCreated) {
            $response->assertCreated();
        } else {
            $response->assertOk();
        }

        return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }
}

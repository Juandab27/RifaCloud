<?php

namespace Tests\Feature\Orders;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class ReservationExpiryTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_expired_pending_order_releases_numbers(): void
    {
        $this->travelTo(now()->startOfSecond());

        $raffle = $this->createRaffle();
        $order = $this->reservedOrder($raffle, [1, 2]);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 1')
            ->assertExitCode(0);

        $order->refresh();

        $this->assertSame(OrderStatus::EXPIRED, $order->status);

        foreach (RaffleNumber::query()->whereIn('number', [1, 2])->get() as $number) {
            $this->assertSame(NumberStatus::AVAILABLE, $number->status);
            $this->assertNull($number->reserved_at);
            $this->assertNull($number->reserved_until);
            $this->assertNull($number->order_id);
        }

        // Line items remain for audit and reconciliation (NFR-014).
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_expiry_is_idempotent(): void
    {
        $this->travelTo(now()->startOfSecond());

        $raffle = $this->createRaffle();
        $order = $this->reservedOrder($raffle, [1]);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')->assertExitCode(0);
        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::EXPIRED, $order->refresh()->status);
        $this->assertSame(NumberStatus::AVAILABLE, RaffleNumber::query()->where('number', 1)->firstOrFail()->status);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_active_reservation_is_not_released(): void
    {
        $this->travelTo(now()->startOfSecond());

        $raffle = $this->createRaffle();
        $order = $this->reservedOrder($raffle, [1]);

        $this->travel(5)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(NumberStatus::RESERVED, RaffleNumber::query()->where('number', 1)->firstOrFail()->status);
    }

    public function test_orders_in_review_or_refund_states_are_never_released(): void
    {
        $this->travelTo(now()->startOfSecond());

        $protectedStatuses = [
            OrderStatus::PAYMENT_REVIEW,
            OrderStatus::REQUIRES_REFUND,
            OrderStatus::PAID,
        ];

        /** @var array<int, Order> $orders */
        $orders = [];

        foreach ($protectedStatuses as $status) {
            $raffle = $this->createRaffle();
            $order = $this->reservedOrder($raffle, [1, 2]);
            $order->update(['status' => $status]);
            $orders[] = $order;
        }

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')->assertExitCode(0);

        foreach ($orders as $index => $order) {
            $order->refresh();

            $this->assertSame($protectedStatuses[$index], $order->status);
            $this->assertSame(
                2,
                RaffleNumber::query()
                    ->where('order_id', $order->id)
                    ->where('status', NumberStatus::RESERVED->value)
                    ->count(),
            );
        }
    }

    public function test_order_with_sold_numbers_is_not_expired_or_released(): void
    {
        $this->travelTo(now()->startOfSecond());

        $raffle = $this->createRaffle();
        $order = $this->reservedOrder($raffle, [1, 2]);

        RaffleNumber::query()->where('number', 1)->update([
            'status' => NumberStatus::SOLD->value,
            'sold_at' => now(),
        ]);

        $this->travel(11)->minutes();

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 0')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertSame(NumberStatus::SOLD, RaffleNumber::query()->where('number', 1)->firstOrFail()->status);
        $this->assertSame(NumberStatus::RESERVED, RaffleNumber::query()->where('number', 2)->firstOrFail()->status);
    }

    public function test_expiry_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'orders:expire-reservations',
            ));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
    }

    /**
     * @param  list<int>  $numbers
     */
    private function reservedOrder(Raffle $raffle, array $numbers): Order
    {
        $user = User::factory()->create();

        return app(OrderReservationService::class)->create($user, $raffle->id, $numbers);
    }
}

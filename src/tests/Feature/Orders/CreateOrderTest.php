<?php

namespace Tests\Feature\Orders;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Raffles\Enums\RaffleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_public_raffle_exposes_reservation_settings(): void
    {
        $raffle = $this->createRaffle();

        $this->getJson("/api/raffles/{$raffle->id}")
            ->assertOk()
            ->assertJsonPath('data.reservation_minutes', 10)
            ->assertJsonPath('data.max_numbers_per_order', 50);
    }

    public function test_guest_cannot_create_order(): void
    {
        $raffle = $this->createRaffle();

        $this->postJson('/api/orders', [
            'raffle_id' => $raffle->id,
            'numbers' => [1],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_authenticated_user_can_create_multi_number_order(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(pricePerNumber: 1500);

        $response = $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [7, 2, 5],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.total_amount', 4500)
            ->assertJsonPath('data.raffle.id', $raffle->id);

        $this->assertMatchesRegularExpression(
            '/^RC-\d{8}-[A-Z0-9]{6}$/',
            (string) $response->json('data.order_number'),
        );
        $this->assertCount(3, $response->json('data.numbers'));

        $order = Order::query()->firstOrFail();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(4500, $order->total_amount);
        $this->assertNotNull($order->expires_at);
        $this->assertSame([2, 5, 7], $order->raffleNumbers()->orderBy('number')->pluck('number')->all());
        $this->assertSame(3, $order->items()->count());
        $this->assertDatabaseCount('order_items', 3);

        foreach ($order->raffleNumbers as $number) {
            $this->assertSame(NumberStatus::RESERVED, $number->status);
            $this->assertSame($order->id, $number->order_id);
            $this->assertNotNull($number->reserved_at);
            $this->assertSame($order->expires_at->timestamp, $number->reserved_until->timestamp);
            $this->assertSame(1500, $number->pivot->price_at_purchase);
        }
    }

    public function test_two_numbers_of_one_thousand_total_two_thousand_cop(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(pricePerNumber: 1000);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1, 2],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 2000);

        $order = Order::query()->firstOrFail();

        $this->assertSame(2000, $order->total_amount);
        $this->assertSame(1000, (int) $order->items()->firstOrFail()->price_at_purchase);
    }

    public function test_reservation_duration_is_configurable(): void
    {
        config(['rifacloud.reservation_minutes' => 5]);
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $number = RaffleNumber::query()->where('number', 1)->firstOrFail();

        $this->assertSame(now()->addMinutes(5)->timestamp, $order->expires_at->timestamp);
        $this->assertSame($order->expires_at->timestamp, $number->reserved_until->timestamp);

        $this->travelBack();
    }

    public function test_number_already_reserved_is_rejected_without_partial_order(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $raffle = $this->createRaffle();

        $this->actingAs($firstUser)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [2],
            ])
            ->assertCreated();

        $this->actingAs($secondUser)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [2, 3],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NUMBERS_UNAVAILABLE')
            ->assertJsonPath('unavailable_numbers.0', 2);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(
            NumberStatus::RESERVED,
            RaffleNumber::query()->where('number', 2)->firstOrFail()->status,
        );
        $this->assertSame(
            NumberStatus::AVAILABLE,
            RaffleNumber::query()->where('number', 3)->firstOrFail()->status,
        );
    }

    public function test_sold_number_cannot_be_reserved(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        RaffleNumber::query()->where('number', 4)->update([
            'status' => NumberStatus::SOLD->value,
            'sold_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [4],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NUMBERS_UNAVAILABLE');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_numbers_are_rejected(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1, 1],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numbers.1');
    }

    public function test_numbers_outside_the_raffle_range_are_rejected(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 10);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [11],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numbers');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_numbers_from_another_raffle_are_rejected(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(minNumber: 1, maxNumber: 10);
        $this->createRaffle(minNumber: 100, maxNumber: 110);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [105],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numbers');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_cannot_be_created_for_unpublished_raffle(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(status: RaffleStatus::DRAFT);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RAFFLE_NOT_AVAILABLE');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_cannot_be_created_before_the_raffle_starts(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle();
        $raffle->update(['starts_at' => now()->addHour(), 'ends_at' => null]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RAFFLE_NOT_STARTED');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(
            NumberStatus::AVAILABLE,
            RaffleNumber::query()->where('number', 1)->firstOrFail()->status,
        );

        $this->travelBack();
    }

    public function test_order_cannot_be_created_after_the_raffle_ends(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle();
        $raffle->update(['starts_at' => null, 'ends_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RAFFLE_ENDED');

        $this->assertDatabaseCount('orders', 0);

        $this->travelBack();
    }

    public function test_null_raffle_dates_do_not_restrict_purchases(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        $this->assertNull($raffle->starts_at);
        $this->assertNull($raffle->ends_at);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT');
    }

    public function test_order_is_allowed_inside_the_raffle_window(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle();
        $raffle->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertCreated();

        $this->assertDatabaseCount('orders', 1);

        $this->travelBack();
    }

    public function test_order_is_allowed_exactly_at_the_window_boundaries(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();

        $startsNow = $this->createRaffle();
        $startsNow->update(['starts_at' => now(), 'ends_at' => now()->addHour()]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $startsNow->id,
                'numbers' => [1],
            ])
            ->assertCreated();

        $endsNow = $this->createRaffle();
        $endsNow->update(['starts_at' => now()->subHour(), 'ends_at' => now()]);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $endsNow->id,
                'numbers' => [1],
            ])
            ->assertCreated();

        $this->assertDatabaseCount('orders', 2);

        $this->travelBack();
    }

    public function test_order_cannot_be_created_when_the_raffle_schedule_is_invalid(): void
    {
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        // Bypass the service guard to simulate a pre-existing bad row.
        $raffle->forceFill([
            'starts_at' => now()->addHour(),
            'ends_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RAFFLE_SCHEDULE_INVALID');

        $this->assertDatabaseCount('orders', 0);

        $this->travelBack();
    }

    public function test_order_number_limit_is_enforced(): void
    {
        config(['rifacloud.order_max_numbers' => 2]);

        $user = User::factory()->create();
        $raffle = $this->createRaffle();

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'raffle_id' => $raffle->id,
                'numbers' => [1, 2, 3],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numbers');
    }

    public function test_owner_can_view_order_and_other_user_gets_not_found(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $raffle = $this->createRaffle();

        $order = app(OrderReservationService::class)->create($owner, $raffle->id, [1, 2]);

        $this->actingAs($owner)
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonCount(2, 'data.numbers');

        $forbidden = $this->actingAs($otherUser)
            ->getJson("/api/orders/{$order->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontrado.');

        $this->assertStringNotContainsString($order->order_number, $forbidden->getContent());
    }

    public function test_order_detail_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $raffle = $this->createRaffle();
        $order = app(OrderReservationService::class)->create($owner, $raffle->id, [1]);

        $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
    }

    public function test_price_is_snapshotted_in_order_items(): void
    {
        $user = User::factory()->create();
        $raffle = $this->createRaffle(pricePerNumber: 1000);

        $order = app(OrderReservationService::class)->create($user, $raffle->id, [1]);

        $raffle->update(['price_per_number' => 2500]);

        $this->assertSame(
            1000,
            (int) $order->items()->firstOrFail()->price_at_purchase,
        );
    }
}

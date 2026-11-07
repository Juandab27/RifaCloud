<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Contracts\ReservationExpiryClient;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\TestCase;

/**
 * S3 moved reservation expiry to orders-service. These tests verify that the
 * monolith command delegates to the internal client and fails closed instead of
 * running the old local expiry logic (which would create a second owner).
 *
 * The behavioural expiry rules (release, idempotency, protected states,
 * UNKNOWN payments, SOLD numbers and the advisory lock) are covered by
 * services/orders-service/tests/orders_integration.php against rifacloud_test.
 */
class ReservationExpiryTest extends TestCase
{
    public function test_expiry_command_delegates_to_orders_service(): void
    {
        $client = new class implements ReservationExpiryClient
        {
            public int $calls = 0;

            public function expire(): int
            {
                $this->calls++;

                return 3;
            }
        };

        $this->app->instance(ReservationExpiryClient::class, $client);

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Expired orders: 3')
            ->assertExitCode(0);

        $this->assertSame(1, $client->calls);
    }

    public function test_expiry_command_fails_closed_when_orders_service_is_unavailable(): void
    {
        $client = new class implements ReservationExpiryClient
        {
            public function expire(): int
            {
                throw new RuntimeException('orders-service is unreachable.');
            }
        };

        $this->app->instance(ReservationExpiryClient::class, $client);

        $this->artisan('orders:expire-reservations')
            ->expectsOutput('Reservation expiry failed: orders-service is unreachable.')
            ->assertExitCode(1);
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
}

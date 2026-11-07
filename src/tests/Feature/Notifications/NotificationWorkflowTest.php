<?php

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\CreatesRaffles;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use CreatesRaffles;
    use RefreshDatabase;

    public function test_a_paid_order_queues_one_confirmation_and_is_sent_afterwards(): void
    {
        [$order] = $this->paidOrder();

        $this->artisan('notifications:queue')->assertExitCode(0);
        $this->artisan('notifications:queue')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'type' => 'PURCHASE_CONFIRMATION',
            'order_id' => $order->id,
            'status' => 'PENDING',
            'recipient_email' => $order->user->email,
        ]);

        Mail::fake();
        $this->artisan('notifications:dispatch')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id,
            'type' => 'PURCHASE_CONFIRMATION',
            'status' => 'SENT',
            'attempts' => 1,
        ]);
    }

    public function test_a_refund_incident_queues_its_own_notification(): void
    {
        [$order] = $this->paidOrder();

        Order::query()->whereKey($order->id)->update(['status' => 'REQUIRES_REFUND']);

        $this->artisan('notifications:queue')->assertExitCode(0);
        $this->artisan('notifications:queue')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id,
            'type' => 'REFUND_INCIDENT',
            'status' => 'PENDING',
        ]);
        $this->assertSame(1, DB::table('notifications')
            ->where('order_id', $order->id)
            ->where('type', 'REFUND_INCIDENT')
            ->count());
    }

    public function test_a_mail_failure_marks_retry_without_touching_the_order(): void
    {
        [$order] = $this->paidOrder();

        $this->artisan('notifications:queue')->assertExitCode(0);

        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP down'));

        $this->artisan('notifications:dispatch')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id,
            'status' => 'RETRY',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PAID',
        ]);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function paidOrder(): array
    {
        $buyer = User::factory()->create();
        $raffle = $this->createRaffle(maxNumber: 2);
        $order = app(OrderReservationService::class)->create($buyer, $raffle->id, [1, 2]);

        $this->actingAs($buyer)
            ->postJson("/api/orders/{$order->id}/payments")
            ->assertCreated();

        $payment = Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

        config(['rifacloud.payment_fake_outcome' => 'APPROVED']);

        $this->actingAs($buyer)
            ->postJson("/api/orders/{$order->id}/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        return [$order->refresh(), $payment];
    }
}

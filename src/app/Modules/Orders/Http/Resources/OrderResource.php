<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'total_amount' => $this->total_amount,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'simulated_payments' => config('rifacloud.payment_gateway') === 'fake',
            'raffle' => $this->whenLoaded('raffle', fn (): array => [
                'id' => $this->raffle->id,
                'title' => $this->raffle->title,
            ]),
            'numbers' => $this->whenLoaded('raffleNumbers', fn (): array => $this->raffleNumbers
                ->map(fn ($number): array => [
                    'id' => $number->id,
                    'number' => $number->number,
                    'status' => $number->status->value,
                    'price_at_purchase' => (int) $number->pivot->price_at_purchase,
                ])
                ->values()
                ->all()),
            'payments' => $this->whenLoaded('payments', fn (): array => $this->payments
                ->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'provider' => $payment->provider->value,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'checkout_url' => $payment->checkout_url,
                    'simulated' => (bool) ($payment->response_data['simulated']
                        ?? config('rifacloud.payment_gateway') === 'fake'),
                    'verified_at' => $payment->verified_at?->toIso8601String(),
                    'created_at' => $payment->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()),
        ];
    }
}

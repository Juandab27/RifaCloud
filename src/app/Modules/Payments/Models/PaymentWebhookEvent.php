<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentWebhookStatus;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'processed_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentWebhookStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Modules\Payments\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'provider',
        'external_id',
        'status',
        'amount',
        'currency',
        'checkout_url',
        'verified_at',
        'response_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentState::class,
            'amount' => 'integer',
            'verified_at' => 'datetime',
            'response_data' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

<?php

namespace App\Modules\Orders\Models;

use App\Modules\Numbers\Models\RaffleNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'raffle_number_id',
        'price_at_purchase',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_at_purchase' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function raffleNumber(): BelongsTo
    {
        return $this->belongsTo(RaffleNumber::class);
    }
}

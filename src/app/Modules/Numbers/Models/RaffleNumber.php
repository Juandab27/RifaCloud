<?php

namespace App\Modules\Numbers\Models;

use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaffleNumber extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'raffle_id',
        'number',
        'status',
        'reserved_at',
        'reserved_until',
        'sold_at',
        'order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => NumberStatus::class,
            'reserved_at' => 'datetime',
            'reserved_until' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

<?php

namespace App\Modules\Orders\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'raffle_id',
        'order_number',
        'total_amount',
        'status',
        'expires_at',
        'paid_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'status' => OrderStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function raffleNumbers(): BelongsToMany
    {
        return $this->belongsToMany(
            RaffleNumber::class,
            'order_items',
            'order_id',
            'raffle_number_id',
        )->withPivot('price_at_purchase')->withTimestamps();
    }
}

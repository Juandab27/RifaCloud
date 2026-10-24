<?php

namespace App\Modules\Raffles\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Raffles\Enums\RaffleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Raffle extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'price_per_number',
        'min_number',
        'max_number',
        'status',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_per_number' => 'integer',
            'min_number' => 'integer',
            'max_number' => 'integer',
            'status' => RaffleStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(RaffleNumber::class);
    }

    public function totalNumbers(): int
    {
        return $this->max_number - $this->min_number + 1;
    }

    public function isPublished(): bool
    {
        return $this->status === RaffleStatus::PUBLISHED;
    }
}

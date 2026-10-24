<?php

namespace App\Modules\Raffles\Http\Resources;

use App\Modules\Raffles\Models\Raffle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Raffle
 */
class RaffleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price_per_number' => $this->price_per_number,
            'min_number' => $this->min_number,
            'max_number' => $this->max_number,
            'total_numbers' => $this->totalNumbers(),
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'numbers_count' => $this->whenCounted('numbers'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

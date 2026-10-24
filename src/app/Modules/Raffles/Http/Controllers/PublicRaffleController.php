<?php

namespace App\Modules\Raffles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Http\Resources\RaffleResource;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicRaffleController extends Controller
{
    /**
     * Public catalog: PUBLISHED raffles only (AC-006, AC-007).
     */
    public function index(): AnonymousResourceCollection
    {
        $raffles = Raffle::query()
            ->where('status', RaffleStatus::PUBLISHED)
            ->withCount('numbers')
            ->orderByDesc('created_at')
            ->paginate(15);

        return RaffleResource::collection($raffles);
    }

    public function show(Raffle $raffle): RaffleResource
    {
        abort_unless($raffle->isPublished(), 404);

        return new RaffleResource($raffle->loadCount('numbers'));
    }
}

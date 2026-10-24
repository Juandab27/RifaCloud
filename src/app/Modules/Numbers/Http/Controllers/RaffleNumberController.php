<?php

namespace App\Modules\Numbers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RaffleNumberController extends Controller
{
    /**
     * Public number availability for a PUBLISHED raffle (FR-005, AC-008).
     */
    public function index(Request $request, Raffle $raffle): JsonResponse
    {
        abort_unless($raffle->isPublished(), 404);

        $perPage = min(max($request->integer('per_page', 100), 1), 200);

        $numbers = $raffle->numbers()
            ->orderBy('number')
            ->paginate($perPage);

        $counts = $raffle->numbers()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'data' => $numbers->getCollection()
                ->map(fn ($number): array => [
                    'id' => $number->id,
                    'number' => $number->number,
                    'status' => $number->status->value,
                ])
                ->all(),
            'meta' => [
                'current_page' => $numbers->currentPage(),
                'last_page' => $numbers->lastPage(),
                'per_page' => $numbers->perPage(),
                'total' => $numbers->total(),
                'counts' => [
                    'available' => (int) ($counts[NumberStatus::AVAILABLE->value] ?? 0),
                    'reserved' => (int) ($counts[NumberStatus::RESERVED->value] ?? 0),
                    'sold' => (int) ($counts[NumberStatus::SOLD->value] ?? 0),
                ],
            ],
        ]);
    }
}

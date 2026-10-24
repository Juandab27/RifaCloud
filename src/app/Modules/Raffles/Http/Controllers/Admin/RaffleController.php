<?php

namespace App\Modules\Raffles\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Raffles\Http\Requests\RaffleRequest;
use App\Modules\Raffles\Http\Resources\RaffleResource;
use App\Modules\Raffles\Models\Raffle;
use App\Modules\Raffles\Services\RaffleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RaffleController extends Controller
{
    public function __construct(private readonly RaffleService $raffleService) {}

    public function index(): AnonymousResourceCollection
    {
        $raffles = Raffle::query()
            ->withCount('numbers')
            ->orderByDesc('created_at')
            ->paginate(15);

        return RaffleResource::collection($raffles);
    }

    public function store(RaffleRequest $request): JsonResponse
    {
        $raffle = $this->raffleService->create($request->validated(), $request->user());

        return (new RaffleResource($raffle->loadCount('numbers')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Raffle $raffle): RaffleResource
    {
        return new RaffleResource($raffle->loadCount('numbers'));
    }

    public function update(RaffleRequest $request, Raffle $raffle): RaffleResource
    {
        $updated = $this->raffleService->update($raffle, $request->validated());

        return new RaffleResource($updated->loadCount('numbers'));
    }

    public function destroy(Raffle $raffle): Response
    {
        $this->raffleService->delete($raffle);

        return response()->noContent();
    }

    public function publish(Raffle $raffle): RaffleResource
    {
        return new RaffleResource($this->raffleService->publish($raffle)->loadCount('numbers'));
    }

    public function close(Raffle $raffle): RaffleResource
    {
        return new RaffleResource($this->raffleService->close($raffle)->loadCount('numbers'));
    }
}

<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderReservationService $reservationService) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->reservationService->create(
            $request->user(),
            (int) $request->validated('raffle_id'),
            $request->validated('numbers'),
        );

        return (new OrderResource($order->load(['raffle', 'raffleNumbers', 'payments'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Order $order): OrderResource|JsonResponse
    {
        // Ownership is enforced on the backend (FR-003, AC-002). Returning 404
        // avoids disclosing the existence of another user's order. An explicit
        // response also prevents local debug mode from exposing a stack trace.
        if ($order->user_id !== $request->user()->id) {
            return response()->json(
                ['message' => 'No encontrado.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new OrderResource($order->load(['raffle', 'raffleNumbers', 'payments']));
    }
}

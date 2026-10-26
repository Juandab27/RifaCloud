<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function store(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->notFound();
        }

        $payment = $this->paymentService->createCheckout($request->user(), $order);

        $order->refresh()->load(['raffle', 'raffleNumbers', 'payments']);

        return (new OrderResource($order))
            ->response()
            ->setStatusCode($payment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function verify(Request $request, Order $order, Payment $payment): OrderResource|JsonResponse
    {
        if ($order->user_id !== $request->user()->id || $payment->order_id !== $order->id) {
            return $this->notFound();
        }

        $this->paymentService->verify($request->user(), $order, $payment);

        $order->refresh()->load(['raffle', 'raffleNumbers', 'payments']);

        return new OrderResource($order);
    }

    private function notFound(): JsonResponse
    {
        // Do not disclose another user's order or a mismatched payment.
        return response()->json(
            ['message' => 'No encontrado.'],
            Response::HTTP_NOT_FOUND,
        );
    }
}

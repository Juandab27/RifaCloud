<?php

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentReviewController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    /**
     * Resolve a PAYMENT_REVIEW incident. Route access is restricted to admins
     * by the "admin" middleware; the service re-checks the role as well.
     *
     * The browser never supplies the payment result: the service queries the
     * provider and applies the existing safety rules.
     */
    public function resolve(Request $request, Order $order, Payment $payment): OrderResource|JsonResponse
    {
        if ((int) $payment->order_id !== (int) $order->id) {
            return response()->json(
                ['message' => 'No encontrado.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->paymentService->resolveReview($request->user(), $order, $payment);

        $order->refresh()->load(['raffle', 'raffleNumbers', 'payments']);

        return new OrderResource($order);
    }
}

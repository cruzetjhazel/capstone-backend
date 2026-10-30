<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Payment\ManuallyVerifyPaymentAction;
use App\Actions\Payment\RecordOnsitePaymentAction;
use App\Actions\Payment\RejectPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManuallyVerifyPaymentRequest;
use App\Http\Requests\RecordOnsitePaymentRequest;
use App\Http\Requests\RejectPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        return $this->success(
            PaymentResource::collection(
                $request->user()->paymentsAsPhotographer()->with(['booking.client', 'booking.walkInClient', 'verifiedBy'])->latest()->get()
            )
        );
    }

    public function storeOnsite(RecordOnsitePaymentRequest $request, Booking $booking, RecordOnsitePaymentAction $action)
    {
        abort_unless($request->user()->isPhotographer(), 403);
        abort_unless($booking->photographer_id === $request->user()->id, 403);

        $payment = $action->execute($booking, $request->validated());

        return $this->success(new PaymentResource($payment), 'Onsite payment recorded.');
    }

    public function verify(ManuallyVerifyPaymentRequest $request, Payment $payment, ManuallyVerifyPaymentAction $action)
    {
                $this->authorize('verify', $payment);
        abort_unless($request->user()->isPhotographer() && $payment->photographer_id === $request->user()->id, 403);

        $payment = $action->execute($payment, $request->user(), $request->validated('notes'));

        return $this->success(new PaymentResource($payment), 'Payment verified. Booking is now confirmed.');
    }

    public function reject(RejectPaymentRequest $request, Payment $payment, RejectPaymentAction $action)
    {
                $this->authorize('reject', $payment);
        abort_unless($request->user()->isPhotographer() && $payment->photographer_id === $request->user()->id, 403);

        $payment = $action->execute($payment, $request->user(), $request->validated('notes'));

        return $this->success(new PaymentResource($payment), 'Payment rejected. The client may resubmit.');
    }
}
<?php

namespace App\Http\Controllers\Hooks;

use App\Domain\Billing\Razorpay\WebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Razorpay webhook: payment captured / failed, refund processed / failed. */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        $result = $processor->handle(
            (string) $request->getContent(),
            (string) $request->header('X-Razorpay-Signature', ''),
            $request->header('X-Razorpay-Event-Id'),
        );

        return response()->json(['status' => $result['result']], $result['status']);
    }
}

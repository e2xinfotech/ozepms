<?php

namespace App\Domain\Billing\Razorpay;

use App\Domain\Billing\PaymentService;
use App\Models\Payment;
use App\Models\PaymentGatewayEvent;
use App\Models\Property;
use App\Support\PropertyContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * Razorpay webhooks. Every call is logged in payment_gateway_events:
 *  * bad signature → stored under a random id (so a forged call can never block the real event
 *    with the same id) and refused with 400;
 *  * (gateway, event id) is unique → a replayed event that was processed is acknowledged and
 *    ignored; one that failed before is processed again;
 *  * payment.captured / order.paid capture the pending payment of the order, payment.failed marks
 *    it failed, refund.processed / refund.failed settle a pending refund.
 * Returns the HTTP status for the gateway (5xx makes Razorpay retry later).
 */
class WebhookProcessor
{
    public function __construct(
        private readonly RazorpayClient $client,
        private readonly PaymentService $payments,
        private readonly PropertyContext $context,
    ) {}

    /** @return array{status: int, result: string} */
    public function handle(string $body, string $signature, ?string $eventId): array
    {
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $type = mb_substr((string) ($payload['event'] ?? 'unknown'), 0, 60);

        if (! $this->client->enabled() || ! $this->client->validWebhookSignature($body, $signature)) {
            PaymentGatewayEvent::query()->create([
                'gateway' => 'razorpay',
                'event_id' => 'invalid:'.Str::ulid(),
                'event_type' => $type,
                'signature_valid' => false,
                'payload' => ['claimed_id' => mb_substr((string) $eventId, 0, 64), 'sha256' => hash('sha256', $body), 'size' => strlen($body)],
                'error' => 'Invalid signature',
            ]);

            return ['status' => 400, 'result' => 'invalid_signature'];
        }

        $eventId = mb_substr($eventId ?: (string) ($payload['id'] ?? ('sha:'.hash('sha256', $body))), 0, 64);
        $inserted = PaymentGatewayEvent::query()->insertOrIgnore([
            'gateway' => 'razorpay', 'event_id' => $eventId, 'event_type' => $type, 'signature_valid' => 1,
            'payload' => json_encode($payload), 'created_at' => now(),
        ]);
        $event = PaymentGatewayEvent::query()->where('gateway', 'razorpay')->where('event_id', $eventId)->firstOrFail();
        if ($inserted === 0 && $event->processed_at !== null) {
            return ['status' => 200, 'result' => 'duplicate'];
        }

        try {
            [$result, $propertyId] = $this->process($type, $payload);
            $event->forceFill(['processed_at' => now(), 'property_id' => $propertyId, 'error' => $result === 'ok' || $result === 'ignored' ? null : $result])->save();

            return ['status' => 200, 'result' => $result];
        } catch (Throwable $e) {
            report($e);
            $event->forceFill(['error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return ['status' => 500, 'result' => 'error'];
        } finally {
            $this->context->clear();
        }
    }

    /** @return array{0: string, 1: ?int} result and property id */
    private function process(string $type, array $payload): array
    {
        switch ($type) {
            case 'payment.captured':
            case 'order.paid':
            case 'payment.failed':
                $entity = $payload['payload']['payment']['entity'] ?? [];
                $orderId = (string) ($entity['order_id'] ?? '');
                $payment = $orderId === '' ? null : $this->find(fn ($q) => $q->where('kind', 'payment')->where('gateway', 'razorpay')->where('gateway_order_id', $orderId));
                if ($payment === null) {
                    return ['unknown_order', null];
                }
                if ($type === 'payment.failed') {
                    $this->payments->markFailed($payment, (string) ($entity['error_description'] ?? 'Payment failed'));
                } else {
                    try {
                        $this->payments->capture($payment, (string) $entity['id'], isset($entity['amount']) ? (int) $entity['amount'] : null);
                    } catch (RazorpayException $e) {
                        // Permanent (amount differs from the order): recorded for review, not retried.
                        return ['amount_mismatch', (int) $payment->property_id];
                    }
                }

                return ['ok', (int) $payment->property_id];

            case 'refund.processed':
            case 'refund.failed':
                $entity = $payload['payload']['refund']['entity'] ?? [];
                $refundId = (string) ($entity['id'] ?? '');
                $refund = $refundId === '' ? null : $this->find(fn ($q) => $q->where('kind', 'refund')->where('gateway', 'razorpay')->where('gateway_payment_id', $refundId));
                if ($refund === null) {
                    return ['unknown_refund', null];
                }
                $type === 'refund.processed' ? $this->payments->confirmRefund($refund) : $this->payments->failRefund($refund, 'Gateway refused the refund.');

                return ['ok', (int) $refund->property_id];
        }

        return ['ignored', null];
    }

    /**
     * Finds the payment across properties (a webhook belongs to no request property) and selects
     * its property for the rest of the processing.
     */
    private function find(callable $where): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = $where(Payment::acrossProperties())->first();
        if ($payment !== null) {
            $this->context->set(Property::query()->findOrFail($payment->property_id), null, true);
        }

        return $payment;
    }
}

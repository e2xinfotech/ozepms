<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Razorpay\RazorpayClient;
use App\Domain\Billing\Razorpay\RazorpayException;
use App\Infrastructure\Database\Tx;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Payments and refunds of a reservation.
 *
 * * Every write carries an idempotency key (unique per property): a double click or a retry
 *   returns the payment recorded the first time ($replayed tells the caller).
 * * Online payments (Razorpay): a pending payment row is stored first, then the gateway order is
 *   created outside any transaction, then the row gets the order id. The payment is captured by
 *   the Checkout callback (signature checked) or the webhook, whichever arrives first; both are
 *   idempotent on the gateway payment id.
 * * Only captured money counts on the folio; refunds reduce it.
 */
class PaymentService
{
    /** True when the last record()/refund()/startOnline() call returned an earlier payment. */
    public bool $replayed = false;

    public function __construct(
        private readonly FolioService $folios,
        private readonly AuditLogger $audit,
        private readonly RazorpayClient $razorpay,
    ) {}

    /**
     * Records money received at the desk.
     *
     * $data: method (cash|card|upi|bank_transfer|other), amount, reference?, notes?, is_deposit?,
     *   received_at? (Y-m-d, not in the future), idempotency_key?
     */
    public function record(Reservation $reservation, array $data, ?User $by = null): Payment
    {
        $this->replayed = false;
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, Payment::MANUAL_METHODS, true)) {
            throw ValidationException::withMessages(['method' => __('billing.errors.method')]);
        }
        $amount = $this->amount($reservation, $data['amount'] ?? null, 'amount');
        $key = $this->key($data);
        if ($key !== null && ($existing = $this->byKey($reservation, $key))) {
            return $this->replay($existing);
        }
        $folio = $this->folios->open($reservation);
        $receivedAt = $this->receivedAt($data['received_at'] ?? null, $reservation);

        return $this->guardKey($reservation, $key, fn () => Tx::run(function () use ($reservation, $folio, $data, $method, $amount, $key, $receivedAt, $by) {
            $folio = $this->folios->lock($folio);
            $payment = Payment::query()->create([
                'property_id' => $reservation->property_id,
                'reservation_id' => $reservation->id,
                'folio_id' => $folio->id,
                'kind' => 'payment',
                'method' => $method,
                'is_deposit' => array_key_exists('is_deposit', $data) && $data['is_deposit'] !== null
                    ? (bool) $data['is_deposit'] : in_array($reservation->status, ['inquiry', 'hold', 'pending', 'confirmed'], true),
                'amount' => $amount,
                'currency_code' => $reservation->currency_code,
                'status' => 'captured',
                'reference' => $this->text($data['reference'] ?? null, 100),
                'notes' => $this->text($data['notes'] ?? null, 255),
                'idempotency_key' => $key,
                'received_by' => $by?->id,
                'received_at' => $receivedAt,
            ]);
            $this->folios->refreshTotals($folio, $reservation);
            $this->audit->log('payment.recorded', $payment, ['after' => [
                'method' => $method, 'amount' => $amount, 'deposit' => $payment->is_deposit, 'reference' => $payment->reference,
            ]], $reservation->property_id, $by?->id);

            return $payment;
        }));
    }

    /**
     * Pays money back on a captured payment (part or all of what is left of it).
     * Gateway payments are refunded through the gateway (outside the transaction); the refund
     * stays pending until the gateway confirms it.
     *
     * $data: amount, notes?, method? (desk refunds may use another method, e.g. cash), idempotency_key?
     */
    public function refund(Payment $payment, array $data, ?User $by = null): Payment
    {
        $this->replayed = false;
        $reservation = Reservation::query()->where('property_id', $payment->property_id)->findOrFail($payment->reservation_id);
        if ($payment->kind !== 'payment' || ! in_array($payment->status, ['captured', 'partially_refunded'], true)) {
            throw ValidationException::withMessages(['payment' => __('billing.errors.not_refundable')]);
        }
        $amount = $this->amount($reservation, $data['amount'] ?? null, 'amount');
        $key = $this->key($data);
        if ($key !== null && ($existing = $this->byKey($reservation, $key))) {
            return $this->replay($existing);
        }
        $gateway = $payment->method === 'gateway' && $payment->gateway === 'razorpay';
        $method = $gateway ? 'gateway' : (string) ($data['method'] ?? $payment->method);
        if (! $gateway && ! in_array($method, Payment::MANUAL_METHODS, true)) {
            throw ValidationException::withMessages(['method' => __('billing.errors.method')]);
        }
        $folio = $this->folios->open($reservation);

        $refund = $this->guardKey($reservation, $key, fn () => Tx::run(function () use ($payment, $reservation, $folio, $amount, $data, $key, $method, $gateway, $by) {
            $folio = $this->folios->lock($folio);
            $parent = Payment::query()->where('property_id', $payment->property_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $left = Money::sub((string) $parent->amount, (string) $parent->refunded_amount);
            if (Money::compare($amount, $left) > 0) {
                throw ValidationException::withMessages(['amount' => __('billing.errors.refund_too_high', ['amount' => Money::forCurrency($left, (string) $parent->currency_code)])]);
            }
            $refund = Payment::query()->create([
                'property_id' => $parent->property_id,
                'reservation_id' => $parent->reservation_id,
                'folio_id' => $folio->id,
                'kind' => 'refund',
                'parent_payment_id' => $parent->id,
                'method' => $method,
                'gateway' => $gateway ? 'razorpay' : null,
                'amount' => $amount,
                'currency_code' => $parent->currency_code,
                'status' => $gateway ? 'pending' : 'captured',
                'reference' => $this->text($data['reference'] ?? null, 100),
                'notes' => $this->text($data['notes'] ?? null, 255),
                'idempotency_key' => $key,
                'received_by' => $by?->id,
                'received_at' => $gateway ? null : now(),
            ]);
            // Reserved on the parent now, so two refunds can never exceed the payment.
            $parent->refunded_amount = Money::round(Money::add((string) $parent->refunded_amount, $amount), 2);
            $parent->status = Money::compare((string) $parent->refunded_amount, (string) $parent->amount) >= 0 ? 'refunded' : 'partially_refunded';
            $parent->save();
            $this->folios->refreshTotals($folio, $reservation);
            $this->audit->log('payment.refunded', $refund, ['after' => ['amount' => $amount, 'of' => $parent->public_id, 'method' => $method]], $parent->property_id, $by?->id);

            return $refund;
        }));

        if ($gateway && ! $this->replayed && $refund->status === 'pending' && $refund->gateway_payment_id === null) {
            $this->sendGatewayRefund($payment, $refund, $reservation);
        }

        return $refund->fresh();
    }

    // ------------------------------------------------------------------ online payments

    public function onlineEnabled(): bool
    {
        return $this->razorpay->enabled();
    }

    /** Starts an online payment: pending row → gateway order (outside the transaction). */
    public function startOnline(Reservation $reservation, array $data, ?User $by = null): Payment
    {
        $this->replayed = false;
        if (! $this->razorpay->enabled()) {
            throw ValidationException::withMessages(['method' => __('billing.errors.online_disabled')]);
        }
        $amount = $this->amount($reservation, $data['amount'] ?? null, 'amount');
        $key = $this->key($data);
        $existing = $key !== null ? $this->byKey($reservation, $key) : null;
        if ($existing !== null && $existing->gateway_order_id !== null) {
            return $this->replay($existing);
        }
        if ($existing !== null && $existing->status === 'failed') {
            // A retry after the gateway could not be reached.
            $existing->forceFill(['status' => 'pending', 'failure_reason' => null])->save();
        }
        $folio = $this->folios->open($reservation);

        $payment = $existing ?? $this->guardKey($reservation, $key, fn () => Payment::query()->create([
            'property_id' => $reservation->property_id,
            'reservation_id' => $reservation->id,
            'folio_id' => $folio->id,
            'kind' => 'payment',
            'method' => 'gateway',
            'gateway' => 'razorpay',
            'is_deposit' => in_array($reservation->status, ['inquiry', 'hold', 'pending', 'confirmed'], true),
            'amount' => $amount,
            'currency_code' => $reservation->currency_code,
            'status' => 'pending',
            'idempotency_key' => $key,
            'received_by' => $by?->id,
        ]));
        if ($payment->gateway_order_id !== null) {
            return $payment;
        }

        try {
            $order = $this->razorpay->createOrder(
                $this->minor((string) $payment->amount, (string) $payment->currency_code),
                (string) $payment->currency_code,
                (string) $payment->public_id,
                ['booking_ref' => (string) $reservation->booking_ref, 'payment' => (string) $payment->public_id],
            );
        } catch (RazorpayException $e) {
            Log::warning('Razorpay order failed', ['payment' => $payment->public_id, 'error' => $e->getMessage()]);
            $payment->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
            throw ValidationException::withMessages(['amount' => __('billing.errors.gateway_unavailable')]);
        }
        $payment->forceFill(['gateway_order_id' => (string) $order['id']])->save();
        $this->audit->log('payment.online_started', $payment, ['after' => ['amount' => (string) $payment->amount, 'order' => $order['id']]], $payment->property_id, $by?->id);

        return $payment;
    }

    /** What the Razorpay Checkout script needs to open the payment window. */
    public function checkoutData(Payment $payment, Reservation $reservation): array
    {
        $guest = $reservation->primaryGuest;

        return [
            'key' => $this->razorpay->keyId(),
            'order_id' => $payment->gateway_order_id,
            'amount' => $this->minor((string) $payment->amount, (string) $payment->currency_code),
            'currency' => $payment->currency_code,
            'name' => (string) ($reservation->property?->name ?? config('ozepms.brand.name')),
            'description' => (string) $reservation->booking_ref,
            'prefill' => array_filter(['name' => $reservation->guest_name, 'email' => $guest?->email, 'contact' => $guest?->phone_e164 ?? $reservation->guest_phone]),
            'notes' => ['booking_ref' => (string) $reservation->booking_ref],
            'payment_id' => $payment->public_id,
        ];
    }

    /** Checkout returned: the signature proves the payment belongs to our order. */
    public function verifyOnline(Payment $payment, string $orderId, string $gatewayPaymentId, string $signature, ?User $by = null): Payment
    {
        if ($payment->gateway_order_id === null || ! hash_equals((string) $payment->gateway_order_id, $orderId)
            || ! $this->razorpay->validCheckoutSignature($orderId, $gatewayPaymentId, $signature)) {
            $this->audit->log('payment.signature_invalid', $payment, [], $payment->property_id, $by?->id);
            throw ValidationException::withMessages(['signature' => __('billing.errors.signature')]);
        }

        return $this->capture($payment, $gatewayPaymentId, null, $by);
    }

    /**
     * Marks a pending gateway payment as captured (idempotent: a second call with the same
     * gateway payment id changes nothing). $amountMinor, when given, must match the payment.
     */
    public function capture(Payment $payment, string $gatewayPaymentId, ?int $amountMinor = null, ?User $by = null): Payment
    {
        $reservation = Reservation::query()->where('property_id', $payment->property_id)->findOrFail($payment->reservation_id);
        $folio = $this->folios->open($reservation);

        return Tx::run(function () use ($payment, $reservation, $folio, $gatewayPaymentId, $amountMinor, $by) {
            $folio = $this->folios->lock($folio);
            $payment = Payment::query()->where('property_id', $payment->property_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, FolioService::RECEIVED, true)) {
                return $payment;
            }
            if ($amountMinor !== null && $amountMinor !== $this->minor((string) $payment->amount, (string) $payment->currency_code)) {
                throw new RazorpayException('Captured amount differs from the order amount.');
            }
            $payment->forceFill([
                'status' => 'captured',
                'gateway_payment_id' => $gatewayPaymentId,
                'reference' => $payment->reference ?? $gatewayPaymentId,
                'received_at' => now(),
                'failure_reason' => null,
            ])->save();
            $this->folios->refreshTotals($folio, $reservation);
            $this->audit->log('payment.captured', $payment, ['after' => ['amount' => (string) $payment->amount, 'gateway_payment_id' => $gatewayPaymentId]], $payment->property_id, $by?->id);

            return $payment;
        });
    }

    public function markFailed(Payment $payment, string $reason): void
    {
        Payment::query()->where('property_id', $payment->property_id)->whereKey($payment->id)->where('status', 'pending')
            ->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    /** The gateway confirmed a refund (webhook refund.processed). */
    public function confirmRefund(Payment $refund): void
    {
        $reservation = Reservation::query()->where('property_id', $refund->property_id)->findOrFail($refund->reservation_id);
        $folio = $this->folios->open($reservation);
        Tx::run(function () use ($refund, $reservation, $folio) {
            $folio = $this->folios->lock($folio);
            $updated = Payment::query()->where('property_id', $refund->property_id)->whereKey($refund->id)->where('status', 'pending')
                ->update(['status' => 'captured', 'received_at' => now(), 'updated_at' => now()]);
            if ($updated > 0) {
                $this->folios->refreshTotals($folio, $reservation);
                $this->audit->log('payment.refund_confirmed', $refund, [], $refund->property_id);
            }
        });
    }

    /** The gateway refused a refund: the reserved amount goes back to the payment. */
    public function failRefund(Payment $refund, string $reason): void
    {
        Tx::run(function () use ($refund, $reason) {
            $refund = Payment::query()->where('property_id', $refund->property_id)->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'pending') {
                return;
            }
            $parent = Payment::query()->where('property_id', $refund->property_id)->whereKey($refund->parent_payment_id)->lockForUpdate()->firstOrFail();
            $parent->refunded_amount = Money::round(Money::max('0', Money::sub((string) $parent->refunded_amount, (string) $refund->amount)), 2);
            $parent->status = Money::isZero((string) $parent->refunded_amount) ? 'captured' : 'partially_refunded';
            $parent->save();
            $refund->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->log('payment.refund_failed', $refund, ['after' => ['reason' => $reason]], $refund->property_id);
        });
    }

    /** Amount in minor units for the gateway (paise for INR). */
    public function minor(string $amount, string $currency): int
    {
        $places = Money::minorUnits($currency);

        return (int) Money::round(Money::mul($amount, bcpow('10', (string) $places)), 0);
    }

    // ------------------------------------------------------------------ internals

    private function sendGatewayRefund(Payment $payment, Payment $refund, Reservation $reservation): void
    {
        try {
            $result = $this->razorpay->refund((string) $payment->gateway_payment_id, $this->minor((string) $refund->amount, (string) $refund->currency_code),
                ['booking_ref' => (string) $reservation->booking_ref, 'refund' => (string) $refund->public_id]);
        } catch (RazorpayException $e) {
            Log::warning('Razorpay refund failed', ['refund' => $refund->public_id, 'error' => $e->getMessage()]);
            $this->failRefund($refund, $e->getMessage());
            throw ValidationException::withMessages(['amount' => __('billing.errors.gateway_unavailable')]);
        }
        $refund->forceFill(['gateway_payment_id' => (string) $result['id'], 'reference' => (string) $result['id']])->save();
        if (($result['status'] ?? '') === 'processed') {
            $this->confirmRefund($refund);
        } elseif (($result['status'] ?? '') === 'failed') {
            $this->failRefund($refund, 'Gateway refused the refund.');
        }
    }

    private function amount(Reservation $reservation, mixed $value, string $field): string
    {
        if (! Money::isDecimal($value)) {
            throw ValidationException::withMessages([$field => __('billing.errors.amount')]);
        }
        $places = Money::minorUnits((string) $reservation->currency_code);
        $amount = Money::round((string) $value, $places);
        if (! Money::isPositive($amount) || Money::compare($amount, '99999999.99') > 0) {
            throw ValidationException::withMessages([$field => __('billing.errors.amount')]);
        }

        return $amount;
    }

    private function receivedAt(?string $date, Reservation $reservation): \DateTimeInterface
    {
        if ($date === null || $date === '') {
            return now();
        }
        $property = $reservation->property ?? \App\Models\Property::query()->findOrFail($reservation->property_id);
        $day = CarbonImmutable::parse($date, $property->timezone ?: config('app.timezone'));
        if ($day->toDateString() > BusinessDate::localToday($property)->toDateString()) {
            throw ValidationException::withMessages(['received_at' => __('billing.errors.future_date')]);
        }

        return $day->toDateString() === BusinessDate::localToday($property)->toDateString() ? now() : $day->setTime(12, 0)->utc();
    }

    private function key(array $data): ?string
    {
        $key = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : '';

        return $key === '' ? null : mb_substr($key, 0, 64);
    }

    private function byKey(Reservation $reservation, string $key): ?Payment
    {
        $payment = Payment::query()->where('property_id', $reservation->property_id)->where('idempotency_key', $key)->first();
        if ($payment !== null && (int) $payment->reservation_id !== (int) $reservation->id) {
            throw ValidationException::withMessages(['idempotency_key' => __('billing.errors.key_reused')]);
        }

        return $payment;
    }

    private function replay(Payment $payment): Payment
    {
        $this->replayed = true;

        return $payment;
    }

    /** Runs a write; when a parallel request with the same key won the race, returns its row. */
    private function guardKey(Reservation $reservation, ?string $key, callable $write): Payment
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if ($key !== null && str_contains($e->getMessage(), 'uq_pay_idempotency') && ($existing = $this->byKey($reservation, $key))) {
                return $this->replay($existing);
            }
            throw $e;
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}

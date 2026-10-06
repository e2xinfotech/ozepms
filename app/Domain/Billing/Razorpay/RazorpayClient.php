<?php

namespace App\Domain\Billing\Razorpay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Razorpay REST API (orders, refunds) and its signatures.
 * Keys come from config('ozepms.billing.razorpay') (.env RAZORPAY_KEY_ID / _KEY_SECRET /
 * _WEBHOOK_SECRET). Never call it inside a database transaction.
 */
class RazorpayClient
{
    public function enabled(): bool
    {
        $c = config('ozepms.billing.razorpay');

        return ! empty($c['key_id']) && ! empty($c['key_secret']) && ! empty($c['webhook_secret']);
    }

    public function keyId(): string
    {
        return (string) config('ozepms.billing.razorpay.key_id');
    }

    /**
     * @param  array<string, string>  $notes
     * @return array<string, mixed> the order (id "order_…", amount, currency, status)
     */
    public function createOrder(int $amountMinor, string $currency, string $receipt, array $notes = []): array
    {
        return $this->post('/orders', [
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
            'receipt' => mb_substr($receipt, 0, 40),
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<string, string>  $notes
     * @return array<string, mixed> the refund (id "rfnd_…", status processed|pending|failed)
     */
    public function refund(string $paymentId, int $amountMinor, array $notes = []): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/refund', ['amount' => $amountMinor, 'notes' => $notes]);
    }

    /** Signature returned by Checkout: HMAC-SHA256(order_id|payment_id, key secret). */
    public function validCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) config('ozepms.billing.razorpay.key_secret'));

        return $signature !== '' && hash_equals($expected, $signature);
    }

    /** Webhook header X-Razorpay-Signature: HMAC-SHA256(raw body, webhook secret). */
    public function validWebhookSignature(string $body, string $signature): bool
    {
        $secret = (string) config('ozepms.billing.razorpay.webhook_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        if (! $this->enabled()) {
            throw new RazorpayException('Online payments are not configured.');
        }
        $c = config('ozepms.billing.razorpay');
        try {
            $response = Http::withBasicAuth((string) $c['key_id'], (string) $c['key_secret'])
                ->acceptJson()->asJson()->timeout((int) ($c['timeout'] ?? 15))
                ->post(rtrim((string) $c['base_url'], '/').$path, $body);
        } catch (ConnectionException $e) {
            throw new RazorpayException('Gateway unreachable: '.$e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            $error = $response->json('error.description') ?? $response->body();
            throw new RazorpayException('Gateway error '.$response->status().': '.mb_substr((string) $error, 0, 200));
        }

        return (array) $response->json();
    }
}

<?php

namespace App\Payments\Webhooks;

use App\Payments\ValueObjects\TransferUpdate;

/**
 * Paystack transfer callbacks.
 *
 * Signed with HMAC-SHA512 over the raw body, keyed by the same secret key used for
 * outbound calls, and delivered in the x-paystack-signature header.
 *
 * @see https://paystack.com/docs/payments/webhooks/
 */
final class PaystackWebhookHandler extends SignedWebhookHandler
{
    private const SIGNATURE_HEADER = 'x-paystack-signature';

    /**
     * Transfer events Paystack sends. Everything else — charges, subscriptions — is
     * not this application's business and is acknowledged without action.
     */
    private const SUCCESS_EVENTS = ['transfer.success'];

    private const FAILURE_EVENTS = ['transfer.failed', 'transfer.reversed'];

    public function name(): string
    {
        return 'paystack';
    }

    public function verify(string $payload, array $headers): bool
    {
        $secret = $this->secret('webhook_secret', 'secret_key');

        if ($secret === '') {
            return false;
        }

        return $this->signatureMatches(
            $this->header($headers, self::SIGNATURE_HEADER),
            hash_hmac('sha512', $payload, $secret),
        );
    }

    public function parse(array $payload): ?TransferUpdate
    {
        $event = mb_strtolower((string) ($payload['event'] ?? ''));
        $data = (array) ($payload['data'] ?? []);
        $reference = (string) ($data['reference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $providerReference = $this->stringOrNull($data['transfer_code'] ?? null);

        return match (true) {
            in_array($event, self::SUCCESS_EVENTS, true) => TransferUpdate::success($reference, $providerReference),
            in_array($event, self::FAILURE_EVENTS, true) => TransferUpdate::failure(
                $reference,
                $this->stringOrNull($data['reason'] ?? $data['message'] ?? null) ?? "Paystack reported [{$event}].",
                $providerReference,
            ),
            default => null,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

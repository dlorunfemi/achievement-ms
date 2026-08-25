<?php

namespace App\Payments\Webhooks;

use App\Payments\ValueObjects\TransferUpdate;

/**
 * Monnify disbursement callbacks.
 *
 * Signed with HMAC-SHA512 over the raw body, keyed by the client secret, in the
 * monnify-signature header. Monnify nests the payload under eventData rather than
 * data, and names its events in screaming snake case.
 *
 * @see https://developers.monnify.com/docs/webhooks/disbursement-webhook
 */
final class MonnifyWebhookHandler extends SignedWebhookHandler
{
    private const SIGNATURE_HEADER = 'monnify-signature';

    private const SUCCESS_EVENTS = ['successful_disbursement'];

    private const FAILURE_EVENTS = ['failed_disbursement', 'reversed_disbursement'];

    public function name(): string
    {
        return 'monnify';
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
        $event = mb_strtolower((string) ($payload['eventType'] ?? ''));
        $data = (array) ($payload['eventData'] ?? []);
        $reference = (string) ($data['reference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $providerReference = $this->stringOrNull($data['transactionReference'] ?? null);

        return match (true) {
            in_array($event, self::SUCCESS_EVENTS, true) => TransferUpdate::success($reference, $providerReference),
            in_array($event, self::FAILURE_EVENTS, true) => TransferUpdate::failure(
                $reference,
                $this->stringOrNull($data['narration'] ?? null) ?? "Monnify reported [{$event}].",
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

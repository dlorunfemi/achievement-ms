<?php

namespace App\Payments\Webhooks;

use App\Payments\ValueObjects\TransferUpdate;

/**
 * Flutterwave transfer callbacks.
 *
 * Flutterwave does not sign the body. It echoes back a secret hash configured in the
 * dashboard, in the verif-hash header, and equality with that string is the whole of
 * the authentication — which is why FLUTTERWAVE_WEBHOOK_HASH must be set to something
 * unguessable and is required rather than falling back to the API key.
 *
 * @see https://developer.flutterwave.com/docs/webhooks/
 */
final class FlutterwaveWebhookHandler extends SignedWebhookHandler
{
    private const SIGNATURE_HEADER = 'verif-hash';

    private const SETTLED = ['successful', 'success', 'completed'];

    private const IN_FLIGHT = ['new', 'pending', 'processing'];

    public function name(): string
    {
        return 'flutterwave';
    }

    public function verify(string $payload, array $headers): bool
    {
        $secret = $this->secret('webhook_hash');

        if ($secret === '') {
            return false;
        }

        return $this->signatureMatches($this->header($headers, self::SIGNATURE_HEADER), $secret);
    }

    public function parse(array $payload): ?TransferUpdate
    {
        $event = mb_strtolower((string) ($payload['event'] ?? $payload['event.type'] ?? ''));

        if (! str_starts_with($event, 'transfer')) {
            return null;
        }

        $data = (array) ($payload['data'] ?? []);
        $reference = (string) ($data['reference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $status = mb_strtolower((string) ($data['status'] ?? ''));
        $providerReference = $this->stringOrNull(isset($data['id']) ? (string) $data['id'] : null);

        return match (true) {
            in_array($status, self::SETTLED, true) => TransferUpdate::success($reference, $providerReference),
            in_array($status, self::IN_FLIGHT, true) => TransferUpdate::pending($reference, $providerReference),
            default => TransferUpdate::failure(
                $reference,
                $this->stringOrNull($data['complete_message'] ?? null) ?? "Flutterwave reported transfer status [{$status}].",
                $providerReference,
            ),
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

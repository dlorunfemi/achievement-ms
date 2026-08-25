<?php

namespace App\Payments\Webhooks;

use App\Payments\ValueObjects\TransferUpdate;

/**
 * An in-process provider callback for local development and tests.
 *
 * Deliberately still signed — HMAC-SHA512 over the raw body, keyed by a configured
 * secret — so the verification path is exercised without any real provider. A test
 * that could skip the signature would not be testing the endpoint that ships.
 */
final class FakeWebhookHandler extends SignedWebhookHandler
{
    public const SIGNATURE_HEADER = 'x-fake-signature';

    public function name(): string
    {
        return 'fake';
    }

    /**
     * The signature a caller must send for a given body.
     */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha512', $payload, $secret);
    }

    public function verify(string $payload, array $headers): bool
    {
        $secret = $this->secret('webhook_secret');

        if ($secret === '') {
            return false;
        }

        return $this->signatureMatches(
            $this->header($headers, self::SIGNATURE_HEADER),
            self::sign($payload, $secret),
        );
    }

    public function parse(array $payload): ?TransferUpdate
    {
        $reference = (string) ($payload['reference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $providerReference = $this->stringOrNull($payload['provider_reference'] ?? null);

        return match (mb_strtolower((string) ($payload['status'] ?? ''))) {
            'success', 'successful' => TransferUpdate::success($reference, $providerReference),
            'failed' => TransferUpdate::failure(
                $reference,
                $this->stringOrNull($payload['reason'] ?? null) ?? 'The fake provider reported a failure.',
                $providerReference,
            ),
            'pending' => TransferUpdate::pending($reference, $providerReference),
            default => null,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

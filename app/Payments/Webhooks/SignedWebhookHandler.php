<?php

namespace App\Payments\Webhooks;

use App\Payments\Contracts\WebhookHandler;

/**
 * Shared plumbing for the providers that sign their callbacks.
 *
 * Every comparison here goes through hash_equals: a plain === leaks how much of a
 * forged signature was correct through its timing, which is enough to reconstruct
 * one byte at a time.
 */
abstract class SignedWebhookHandler implements WebhookHandler
{
    /**
     * Create a new class instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config)
    {
        //
    }

    /**
     * Read a header case-insensitively. Providers are inconsistent about casing and
     * proxies rewrite it freely, so the name is never matched exactly.
     *
     * @param  array<string, list<string|null>|string|null>  $headers
     */
    protected function header(array $headers, string $name): ?string
    {
        $wanted = mb_strtolower($name);

        foreach ($headers as $key => $value) {
            if (mb_strtolower((string) $key) !== $wanted) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    /**
     * Compare a provided signature against the expected one without leaking timing.
     */
    protected function signatureMatches(?string $provided, string $expected): bool
    {
        if ($provided === null || $provided === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * The credential this provider signs with. Missing configuration must fail
     * closed: an empty secret would otherwise verify an empty signature.
     */
    protected function secret(string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $this->config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}

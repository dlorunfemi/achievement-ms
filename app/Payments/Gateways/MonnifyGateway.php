<?php

namespace App\Payments\Gateways;

use App\Payments\Exceptions\PaymentException;
use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Monnify Disbursements.
 *
 * Monnify is the only supported provider that does not use a static API key: every
 * call needs a bearer token obtained by exchanging the API key and secret. Tokens are
 * cached until shortly before they expire so a burst of payouts does not re-authenticate
 * on every transfer.
 *
 * Amounts are sent in major units (naira).
 *
 * @see https://developers.monnify.com/api/#disbursements
 */
final class MonnifyGateway extends HttpGateway
{
    private const TOKEN_CACHE_KEY = 'payments:monnify:access-token';

    /**
     * Seconds subtracted from the token's stated lifetime, so a token is never used
     * in the moments before it expires.
     */
    private const TOKEN_EXPIRY_MARGIN = 60;

    /**
     * Monnify disbursement states that mean the money has actually moved.
     */
    private const SETTLED = ['success', 'successful', 'completed'];

    /**
     * States Monnify reports while a disbursement is still in flight.
     */
    private const IN_FLIGHT = ['pending', 'processing', 'otp_email_dispatch', 'in_progress'];

    public function name(): string
    {
        return 'monnify';
    }

    protected function send(TransferRequest $request): PaymentResult
    {
        $body = $this->authorized()
            ->post($this->endpoint('/api/v2/disbursements/single'), [
                'amount' => $request->amount->toMajorUnits(),
                'reference' => $request->reference,
                'narration' => $request->narration,
                'destinationBankCode' => $request->recipient->bankCode,
                'destinationAccountNumber' => $request->recipient->accountNumber,
                'currency' => $request->amount->currency,
                'sourceAccountNumber' => $this->credentials()['source_account_number'] ?? '',
            ])
            ->throw()
            ->json();

        if (($body['requestSuccessful'] ?? false) !== true) {
            return PaymentResult::failure($this->errorFrom($body) ?? 'Monnify rejected the transfer.');
        }

        $reference = (string) ($body['responseBody']['reference'] ?? $request->reference);
        $status = mb_strtolower((string) ($body['responseBody']['status'] ?? 'pending'));

        return match (true) {
            in_array($status, self::SETTLED, true) => PaymentResult::success($reference),
            in_array($status, self::IN_FLIGHT, true) => PaymentResult::pending($reference),
            default => PaymentResult::failure(
                $this->errorFrom($body) ?? "Monnify reported transfer status [{$status}].",
                $reference,
            ),
        };
    }

    protected function lookUpAccount(string $accountNumber, string $bankCode): AccountResolution
    {
        $body = $this->authorized()
            ->get($this->endpoint('/api/v1/disbursements/account/validate'), [
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
            ])
            ->throw()
            ->json();

        if (($body['requestSuccessful'] ?? false) !== true) {
            return AccountResolution::unresolved(
                $accountNumber,
                $bankCode,
                $this->errorFrom($body) ?? 'Monnify could not resolve the account.',
            );
        }

        $name = $body['responseBody']['accountName'] ?? null;

        return AccountResolution::resolved(
            $accountNumber,
            $bankCode,
            is_string($name) && $name !== '' ? $name : null,
        );
    }

    protected function lookUpTransfer(string $reference): TransferUpdate
    {
        $body = $this->authorized()
            ->get($this->endpoint('/api/v2/disbursements/single/summary'), ['reference' => $reference])
            ->throw()
            ->json();

        if (($body['requestSuccessful'] ?? false) !== true) {
            return TransferUpdate::pending($reference);
        }

        $providerReference = is_string($body['responseBody']['reference'] ?? null)
            ? $body['responseBody']['reference']
            : null;
        $status = mb_strtolower((string) ($body['responseBody']['status'] ?? 'pending'));

        return match (true) {
            in_array($status, self::SETTLED, true) => TransferUpdate::success($reference, $providerReference),
            in_array($status, self::IN_FLIGHT, true) => TransferUpdate::pending($reference, $providerReference),
            default => TransferUpdate::failure(
                $reference,
                $this->errorFrom($body) ?? "Monnify reported transfer status [{$status}].",
                $providerReference,
            ),
        };
    }

    /**
     * Every Monnify call is bearer-authenticated, so the token is fetched here rather
     * than in each method. A token that cannot be obtained is a fault in this module,
     * not a provider decision, so it is thrown and turned into the calling method's
     * own shape of failure by HttpGateway.
     */
    protected function authorized(): PendingRequest
    {
        $token = $this->accessToken();

        if ($token === null) {
            throw new PaymentException('Could not authenticate with Monnify.');
        }

        return $this->client()->withToken($token);
    }

    /**
     * Fetch a bearer token, reusing the cached one until it is close to expiring.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $body = $this->client()
            ->withBasicAuth(
                (string) ($this->credentials()['api_key'] ?? ''),
                (string) ($this->credentials()['secret_key'] ?? ''),
            )
            ->post($this->endpoint('/api/v1/auth/login'))
            ->throw()
            ->json();

        if (($body['requestSuccessful'] ?? false) !== true) {
            return null;
        }

        $token = $body['responseBody']['accessToken'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $lifetime = (int) ($body['responseBody']['expiresIn'] ?? 0);

        if ($lifetime > self::TOKEN_EXPIRY_MARGIN) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, $lifetime - self::TOKEN_EXPIRY_MARGIN);
        }

        return $token;
    }
}

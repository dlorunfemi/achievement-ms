<?php

namespace App\Payments\Gateways;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\RecipientRegistration;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Shared plumbing for the HTTP-backed providers.
 *
 * Its job is to uphold the contract's promise that a gateway never throws for a
 * provider's "no": every network fault, HTTP error and unexpected body is funnelled
 * into whatever "we could not" looks like for the call being made, so the caller
 * records the reason and decides what to do next.
 */
abstract class HttpGateway implements PaymentGateway
{
    /**
     * Create a new class instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
    ) {
        //
    }

    /**
     * Perform the provider-specific transfer call.
     */
    abstract protected function send(TransferRequest $request): PaymentResult;

    /**
     * Perform the provider-specific name enquiry.
     */
    abstract protected function lookUpAccount(string $accountNumber, string $bankCode): AccountResolution;

    /**
     * Perform the provider-specific status enquiry for one transfer.
     */
    abstract protected function lookUpTransfer(string $reference): TransferUpdate;

    public function transfer(TransferRequest $request): PaymentResult
    {
        return $this->attempt(
            fn (): PaymentResult => $this->send($request),
            fn (string $reason): PaymentResult => PaymentResult::failure($reason),
        );
    }

    public function resolveAccount(string $accountNumber, string $bankCode): AccountResolution
    {
        return $this->attempt(
            fn (): AccountResolution => $this->lookUpAccount($accountNumber, $bankCode),
            fn (string $reason): AccountResolution => AccountResolution::unresolved($accountNumber, $bankCode, $reason),
        );
    }

    /**
     * Most providers take the bank details inline and have nothing to register.
     * Paystack overrides this.
     */
    public function ensureRecipient(RecipientAccount $account): RecipientRegistration
    {
        return RecipientRegistration::notRequired();
    }

    public function verifyTransfer(string $reference): TransferUpdate
    {
        return $this->attempt(
            fn (): TransferUpdate => $this->lookUpTransfer($reference),
            // Deliberately not a failure: an unreachable provider means we do not
            // know yet, and recording "failed" would write off money that may well
            // have moved. Pending leaves the payout for the next sweep.
            fn (string $reason): TransferUpdate => TransferUpdate::pending($reference),
        );
    }

    /**
     * Run a provider call, turning anything thrown into the caller's own shape of
     * "we could not".
     *
     * @template TResult
     *
     * @param  callable(): TResult  $call
     * @param  callable(string): TResult  $onFailure
     * @return TResult
     */
    protected function attempt(callable $call, callable $onFailure): mixed
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            return $onFailure("Could not reach {$this->name()}: {$e->getMessage()}");
        } catch (RequestException $e) {
            return $onFailure(
                $this->errorFrom($e->response->json()) ?? "{$this->name()} returned HTTP {$e->response->status()}.",
            );
        } catch (Throwable $e) {
            return $onFailure($e->getMessage());
        }
    }

    /**
     * A client with the timeouts and retry policy every provider call shares.
     */
    protected function client(): PendingRequest
    {
        return $this->http
            ->timeout((int) config('payments.http.timeout', 15))
            ->connectTimeout((int) config('payments.http.connect_timeout', 5))
            ->retry(
                (int) config('payments.http.retries', 2),
                (int) config('payments.http.retry_delay', 200),
                throw: false,
            )
            ->acceptJson();
    }

    /**
     * A client already carrying the provider's credentials.
     */
    protected function authorized(): PendingRequest
    {
        return $this->client()->withToken((string) ($this->credentials()['secret_key'] ?? ''));
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) ($this->credentials()['base_url'] ?? ''), '/').$path;
    }

    /**
     * Pull a human-readable reason out of a provider error body.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function errorFrom(?array $body): ?string
    {
        foreach (['message', 'responseMessage', 'error'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        return $this->config;
    }
}

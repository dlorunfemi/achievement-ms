<?php

namespace App\Payments\Gateways;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\RecipientRegistration;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;
use Illuminate\Support\Str;

/**
 * An in-process provider for local development and tests.
 *
 * Records every transfer so assertions can be made against it, and honours the
 * request reference the way a real provider does: a settled transfer is replayed
 * rather than sent again, while a failure stays retryable.
 */
final class FakeGateway implements PaymentGateway
{
    /**
     * Every transfer this gateway was asked to make, in order.
     *
     * @var list<TransferRequest>
     */
    public array $transfers = [];

    /**
     * Settled results, keyed by transfer reference.
     *
     * @var array<string, PaymentResult>
     */
    private array $settledByReference = [];

    /**
     * Recipient accounts this gateway was asked to register, in order.
     *
     * @var list<RecipientAccount>
     */
    public array $recipients = [];

    /**
     * Statuses reported by verifyTransfer, keyed by reference.
     *
     * @var array<string, TransferUpdate>
     */
    private array $updatesByReference = [];

    private ?string $failWith = null;

    private bool $holdPending = false;

    private ?string $resolvesAs = null;

    private ?string $resolutionFailure = null;

    private ?string $registrationFailure = null;

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Make every subsequent transfer fail, to exercise the failure and retry paths.
     */
    public function alwaysFail(string $reason = 'Insufficient balance'): self
    {
        $this->failWith = $reason;
        $this->holdPending = false;

        return $this;
    }

    /**
     * Accept transfers but never settle them, as an asynchronous provider would.
     */
    public function alwaysPend(): self
    {
        $this->holdPending = true;
        $this->failWith = null;

        return $this;
    }

    public function alwaysSucceed(): self
    {
        $this->failWith = null;
        $this->holdPending = false;

        return $this;
    }

    /**
     * Answer every name enquiry with this account name.
     *
     * The fake resolves accounts but invents no identity by default: a test that
     * cares what the bank calls the account says so here.
     */
    public function resolvesAs(?string $accountName): self
    {
        $this->resolvesAs = $accountName;
        $this->resolutionFailure = null;

        return $this;
    }

    /**
     * Make every name enquiry come back unresolved, as a wrong account number would.
     */
    public function failResolution(string $reason = 'Could not resolve account name'): self
    {
        $this->resolutionFailure = $reason;

        return $this;
    }

    /**
     * Refuse to register recipients, as a provider rejecting the bank details would.
     */
    public function failRegistration(string $reason = 'Recipient rejected'): self
    {
        $this->registrationFailure = $reason;

        return $this;
    }

    /**
     * Report a reference as settled the next time it is verified, standing in for the
     * provider finishing a transfer it had accepted but not completed.
     */
    public function settle(string $reference, ?string $providerReference = null): self
    {
        $this->updatesByReference[$reference] = TransferUpdate::success($reference, $providerReference);

        return $this;
    }

    public function failTransfer(string $reference, string $reason = 'Transfer reversed'): self
    {
        $this->updatesByReference[$reference] = TransferUpdate::failure($reference, $reason);

        return $this;
    }

    public function resolveAccount(string $accountNumber, string $bankCode): AccountResolution
    {
        if ($this->resolutionFailure !== null) {
            return AccountResolution::unresolved($accountNumber, $bankCode, $this->resolutionFailure);
        }

        return AccountResolution::resolved($accountNumber, $bankCode, $this->resolvesAs);
    }

    public function ensureRecipient(RecipientAccount $account): RecipientRegistration
    {
        if ($this->registrationFailure !== null) {
            return RecipientRegistration::failure($this->registrationFailure);
        }

        $this->recipients[] = $account;

        return RecipientRegistration::registered('fake_rcp_'.Str::uuid()->toString());
    }

    /**
     * A transfer nobody has said anything about is still in flight, which is what a
     * real provider reports for an instruction it has accepted and not yet settled.
     */
    public function verifyTransfer(string $reference): TransferUpdate
    {
        return $this->updatesByReference[$reference] ?? TransferUpdate::pending($reference);
    }

    public function transfer(TransferRequest $request): PaymentResult
    {
        if (isset($this->settledByReference[$request->reference])) {
            return $this->settledByReference[$request->reference];
        }

        $this->transfers[] = $request;

        $result = match (true) {
            $this->failWith !== null => PaymentResult::failure($this->failWith),
            $this->holdPending => PaymentResult::pending('fake_'.Str::uuid()->toString()),
            default => PaymentResult::success('fake_'.Str::uuid()->toString()),
        };

        // Only a settled transfer is replayed; a failure may legitimately be retried.
        if ($result->successful()) {
            $this->settledByReference[$request->reference] = $result;
        }

        return $result;
    }

    public function transferCount(): int
    {
        return count($this->transfers);
    }

    public function lastTransfer(): ?TransferRequest
    {
        return $this->transfers === [] ? null : $this->transfers[array_key_last($this->transfers)];
    }

    public function recipientCount(): int
    {
        return count($this->recipients);
    }
}

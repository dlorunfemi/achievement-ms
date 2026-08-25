<?php

namespace App\Payments\Gateways;

use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\RecipientAccount;
use App\Payments\ValueObjects\RecipientRegistration;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;

/**
 * Paystack Transfers.
 *
 * Paystack will not transfer to a raw account number: the recipient must first be
 * registered and exchanged for a recipient code. Callers that hold on to that code
 * pass it back on the request; callers that do not still work, because the code is
 * registered inline as a fallback.
 *
 * Amounts are sent in minor units (kobo). Transfers settle asynchronously, so an
 * accepted instruction reports as pending until Paystack confirms it.
 *
 * @see https://paystack.com/docs/api/transfer/
 */
final class PaystackGateway extends HttpGateway
{
    /**
     * Paystack transfer states that mean the money has actually moved.
     */
    private const SETTLED = ['success'];

    /**
     * States Paystack reports while a transfer is still in flight.
     */
    private const IN_FLIGHT = ['pending', 'otp', 'processing', 'received'];

    public function name(): string
    {
        return 'paystack';
    }

    public function ensureRecipient(RecipientAccount $account): RecipientRegistration
    {
        return $this->attempt(
            function () use ($account): RecipientRegistration {
                $code = $this->registerRecipient($account);

                return $code === null
                    ? RecipientRegistration::failure('Paystack would not register the recipient account.')
                    : RecipientRegistration::registered($code);
            },
            fn (string $reason): RecipientRegistration => RecipientRegistration::failure($reason),
        );
    }

    protected function send(TransferRequest $request): PaymentResult
    {
        $recipientCode = $request->recipient->providerToken
            ?? $this->registerRecipient($request->recipient);

        if ($recipientCode === null) {
            return PaymentResult::failure('Paystack would not register the recipient account.');
        }

        $body = $this->authorized()
            ->post($this->endpoint('/transfer'), [
                'source' => 'balance',
                'amount' => $request->amount->minorUnits,
                'currency' => $request->amount->currency,
                'recipient' => $recipientCode,
                'reason' => $request->narration,
                'reference' => $request->reference,
            ])
            ->throw()
            ->json();

        if (($body['status'] ?? false) !== true) {
            return PaymentResult::failure($this->errorFrom($body) ?? 'Paystack rejected the transfer.');
        }

        $reference = (string) ($body['data']['transfer_code'] ?? $request->reference);
        $status = mb_strtolower((string) ($body['data']['status'] ?? 'pending'));

        return match (true) {
            in_array($status, self::SETTLED, true) => PaymentResult::success($reference),
            in_array($status, self::IN_FLIGHT, true) => PaymentResult::pending($reference),
            default => PaymentResult::failure(
                $this->errorFrom($body) ?? "Paystack reported transfer status [{$status}].",
                $reference,
            ),
        };
    }

    protected function lookUpAccount(string $accountNumber, string $bankCode): AccountResolution
    {
        $body = $this->authorized()
            ->get($this->endpoint('/bank/resolve'), [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ])
            ->throw()
            ->json();

        if (($body['status'] ?? false) !== true) {
            return AccountResolution::unresolved(
                $accountNumber,
                $bankCode,
                $this->errorFrom($body) ?? 'Paystack could not resolve the account.',
            );
        }

        $name = $body['data']['account_name'] ?? null;

        return AccountResolution::resolved(
            $accountNumber,
            $bankCode,
            is_string($name) && $name !== '' ? $name : null,
        );
    }

    protected function lookUpTransfer(string $reference): TransferUpdate
    {
        $body = $this->authorized()
            ->get($this->endpoint('/transfer/verify/'.rawurlencode($reference)))
            ->throw()
            ->json();

        if (($body['status'] ?? false) !== true) {
            return TransferUpdate::pending($reference);
        }

        $providerReference = $body['data']['transfer_code'] ?? null;
        $status = mb_strtolower((string) ($body['data']['status'] ?? 'pending'));

        return match (true) {
            in_array($status, self::SETTLED, true) => TransferUpdate::success($reference, $providerReference),
            in_array($status, self::IN_FLIGHT, true) => TransferUpdate::pending($reference, $providerReference),
            default => TransferUpdate::failure(
                $reference,
                $this->errorFrom($body) ?? "Paystack reported transfer status [{$status}].",
                $providerReference,
            ),
        };
    }

    /**
     * Exchange the bank details for a Paystack recipient code.
     */
    private function registerRecipient(RecipientAccount $account): ?string
    {
        $body = $this->authorized()
            ->post($this->endpoint('/transferrecipient'), [
                'type' => 'nuban',
                'name' => $account->accountName,
                'account_number' => $account->accountNumber,
                'bank_code' => $account->bankCode,
                'currency' => $account->currency,
            ])
            ->throw()
            ->json();

        if (($body['status'] ?? false) !== true) {
            return null;
        }

        $code = $body['data']['recipient_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}

<?php

namespace App\Payments\Gateways;

use App\Payments\ValueObjects\AccountResolution;
use App\Payments\ValueObjects\PaymentResult;
use App\Payments\ValueObjects\TransferRequest;
use App\Payments\ValueObjects\TransferUpdate;

/**
 * Flutterwave Transfers.
 *
 * Unlike Paystack, Flutterwave accepts the bank details inline, so a transfer is a
 * single call. Amounts are sent in major units (naira, not kobo) — the conversion is
 * the reason Money owns both representations.
 *
 * @see https://developer.flutterwave.com/reference/create-transfer
 */
final class FlutterwaveGateway extends HttpGateway
{
    /**
     * Flutterwave transfer states that mean the money has actually moved.
     */
    private const SETTLED = ['successful', 'success', 'completed'];

    /**
     * States Flutterwave reports while a transfer is still queued or in flight.
     */
    private const IN_FLIGHT = ['new', 'pending', 'processing'];

    public function name(): string
    {
        return 'flutterwave';
    }

    protected function send(TransferRequest $request): PaymentResult
    {
        $body = $this->authorized()
            ->post($this->endpoint('/transfers'), [
                'account_bank' => $request->recipient->bankCode,
                'account_number' => $request->recipient->accountNumber,
                'amount' => $request->amount->toMajorUnits(),
                'currency' => $request->amount->currency,
                'debit_currency' => $request->amount->currency,
                'narration' => $request->narration,
                'reference' => $request->reference,
                'beneficiary_name' => $request->recipient->accountName,
            ])
            ->throw()
            ->json();

        if (mb_strtolower((string) ($body['status'] ?? '')) !== 'success') {
            return PaymentResult::failure($this->errorFrom($body) ?? 'Flutterwave rejected the transfer.');
        }

        $reference = (string) ($body['data']['reference'] ?? $request->reference);
        $status = mb_strtolower((string) ($body['data']['status'] ?? 'new'));

        return match (true) {
            in_array($status, self::SETTLED, true) => PaymentResult::success($reference),
            in_array($status, self::IN_FLIGHT, true) => PaymentResult::pending($reference),
            default => PaymentResult::failure(
                $body['data']['complete_message'] ?? "Flutterwave reported transfer status [{$status}].",
                $reference,
            ),
        };
    }

    protected function lookUpAccount(string $accountNumber, string $bankCode): AccountResolution
    {
        $body = $this->authorized()
            ->post($this->endpoint('/accounts/resolve'), [
                'account_number' => $accountNumber,
                'account_bank' => $bankCode,
            ])
            ->throw()
            ->json();

        if (mb_strtolower((string) ($body['status'] ?? '')) !== 'success') {
            return AccountResolution::unresolved(
                $accountNumber,
                $bankCode,
                $this->errorFrom($body) ?? 'Flutterwave could not resolve the account.',
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
            ->get($this->endpoint('/transfers'), ['reference' => $reference])
            ->throw()
            ->json();

        if (mb_strtolower((string) ($body['status'] ?? '')) !== 'success') {
            return TransferUpdate::pending($reference);
        }

        $transfer = $this->firstTransfer($body['data'] ?? null);

        if ($transfer === null) {
            return TransferUpdate::pending($reference);
        }

        $providerReference = is_string($transfer['reference'] ?? null) ? $transfer['reference'] : null;
        $status = mb_strtolower((string) ($transfer['status'] ?? 'new'));

        return match (true) {
            in_array($status, self::SETTLED, true) => TransferUpdate::success($reference, $providerReference),
            in_array($status, self::IN_FLIGHT, true) => TransferUpdate::pending($reference, $providerReference),
            default => TransferUpdate::failure(
                $reference,
                $transfer['complete_message'] ?? "Flutterwave reported transfer status [{$status}].",
                $providerReference,
            ),
        };
    }

    /**
     * Flutterwave answers a reference query with a list, and a single fetch with an
     * object. Both shapes arrive here.
     *
     * @return array<string, mixed>|null
     */
    private function firstTransfer(mixed $data): ?array
    {
        if (! is_array($data) || $data === []) {
            return null;
        }

        $transfer = array_is_list($data) ? $data[0] : $data;

        return is_array($transfer) ? $transfer : null;
    }
}

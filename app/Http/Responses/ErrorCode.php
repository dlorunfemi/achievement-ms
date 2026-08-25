<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The vocabulary of every failure this application can report over HTTP.
 *
 * A client should never have to match on an English sentence to decide what to do —
 * the message is for a human reading a log, the case below is for the code deciding
 * whether to retry, re-prompt or give up. Status lives here rather than at the call
 * site so a code and its status cannot drift apart between one controller and the next.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case ResourceNotFound = 'resource_not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case TooManyRequests = 'too_many_requests';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';

    case PayoutInFlight = 'payout_in_flight';
    case PayoutAlreadyPaid = 'payout_already_paid';
    case PayoutBadgeMissing = 'payout_badge_missing';
    case PayoutAccountMissing = 'payout_account_missing';

    case UnknownPaymentProvider = 'unknown_payment_provider';
    case InvalidWebhookSignature = 'invalid_webhook_signature';

    /**
     * The HTTP status this failure is reported with.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed, self::PayoutBadgeMissing, self::PayoutAccountMissing => 422,
            self::ResourceNotFound, self::UnknownPaymentProvider => 404,
            self::MethodNotAllowed => 405,
            self::TooManyRequests => 429,
            self::Unauthenticated, self::InvalidWebhookSignature => 401,
            self::Forbidden => 403,
            self::ServiceUnavailable => 503,
            self::PayoutInFlight, self::PayoutAlreadyPaid => 409,
            self::ServerError => 500,
        };
    }

    /**
     * What to say when the call site has nothing more specific to add.
     */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The given data was invalid.',
            self::ResourceNotFound => 'The requested resource does not exist.',
            self::MethodNotAllowed => 'That method is not supported for this route.',
            self::TooManyRequests => 'Too many requests. Slow down and try again shortly.',
            self::Unauthenticated => 'This request is not authenticated.',
            self::Forbidden => 'This request is not allowed.',
            self::ServerError => 'Something went wrong on our end.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
            self::PayoutInFlight => 'This transfer is in flight with the provider.',
            self::PayoutAlreadyPaid => 'Already paid.',
            self::PayoutBadgeMissing => 'The badge behind this payout no longer exists.',
            self::PayoutAccountMissing => 'This user has no payout account on file.',
            self::UnknownPaymentProvider => 'Unknown payment provider.',
            self::InvalidWebhookSignature => 'Invalid signature.',
        };
    }

    /**
     * Build the response for this failure.
     *
     * "message" and "code" are always present, in that order. Anything in $context is
     * merged after them: a conflict is far more useful when it also hands back the row
     * it refused to act on, and callers already depend on those payloads.
     *
     * @param  array<string, mixed>  $context
     */
    public function response(?string $message = null, array $context = [], ?int $status = null): JsonResponse
    {
        return response()->json([
            'message' => $message ?? $this->message(),
            'code' => $this->value,
            ...$context,
        ], $status ?? $this->status());
    }
}

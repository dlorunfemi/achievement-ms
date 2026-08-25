<?php

namespace App\Http\Controllers;

use App\Http\Responses\ErrorCode;
use App\Payments\Events\TransferUpdated;
use App\Payments\WebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Receive a provider callback and settle the payout it refers to.
     *
     * Status codes are the contract with the provider, not decoration: anything
     * outside 2xx puts the callback into their redelivery queue. So an event we do
     * not recognise is acknowledged rather than refused, and only a failed signature
     * or an unknown provider is rejected. The one thing this must never do is throw —
     * a 500 turns a stranger's malformed body into an infinite retry loop.
     */
    public function __invoke(Request $request, string $provider, WebhookManager $webhooks): JsonResponse
    {
        $handler = $webhooks->for($provider);

        if ($handler === null) {
            return ErrorCode::UnknownPaymentProvider->response("Unknown payment provider [{$provider}].");
        }

        if (! $handler->verify($request->getContent(), $request->headers->all())) {
            Log::warning('Rejected a payment webhook with an invalid signature.', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return ErrorCode::InvalidWebhookSignature->response();
        }

        $update = $handler->parse($this->decode($request));

        // Providers send far more than transfer outcomes. Acknowledge the rest so
        // they stop resending, but do not pretend anything happened.
        if ($update === null || ! $update->settled()) {
            return response()->json(['message' => 'Acknowledged, no action taken.'], 202);
        }

        TransferUpdated::dispatch($handler->name(), $update);

        return response()->json(['message' => 'Accepted.'], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $payload = $request->json()->all();

        return is_array($payload) ? $payload : [];
    }
}

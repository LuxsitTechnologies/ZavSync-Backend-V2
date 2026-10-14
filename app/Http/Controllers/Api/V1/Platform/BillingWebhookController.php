<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\BillingWebhookService;
use App\Services\Platform\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingWebhookController extends Controller
{
    public function __construct(private readonly StripeWebhookVerifier $verifier,
        private readonly BillingWebhookService $webhooks) {}

    public function handle(Request $request): JsonResponse
    {
        $event = $this->verifier->verify($request->getContent(), $request->header('Stripe-Signature'));

        return response()->json($this->webhooks->handle($event));
    }
}

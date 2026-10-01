<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rota pública (fora do grupo `auth` — é a Asaas chamando, não uma pessoa
 * logada). Autenticação é o header `asaas-access-token`, comparado contra
 * um valor que a gente mesmo escolhe ao cadastrar o webhook no painel da
 * Asaas — nunca confiar no payload sem conferir isso primeiro.
 */
class AsaasWebhookController extends Controller
{
    public function handle(Request $request, SubscriptionWebhookService $webhooks): Response
    {
        abort_unless(
            hash_equals((string) config('services.asaas.webhook_token'), (string) $request->header('asaas-access-token')),
            403,
        );

        $webhooks->handle($request->json()->all());

        return response()->noContent();
    }
}

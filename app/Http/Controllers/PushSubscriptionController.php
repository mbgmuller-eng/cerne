<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guarda a inscrição de push do navegador logado. Mesma forma de
 * ThemePreferenceController — o efeito imediato é do JS (resources/js/push.js),
 * esta rota só persiste pro servidor conseguir mandar notificação depois.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // O servidor ENVIA a notificação para este endereço: só https e só os serviços de push dos navegadores (senão vira requisição a rede interna).
            'endpoint' => ['required', 'string', 'max:500', 'url:https', function (string $atributo, mixed $valor, \Closure $falhar): void {
                $host = strtolower((string) parse_url((string) $valor, PHP_URL_HOST));
                $permitidos = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
                $sufixos = ['.push.apple.com', '.push.services.mozilla.com', '.notify.windows.com', '.push.microsoft.com', '.googleapis.com'];

                $ok = in_array($host, $permitidos, true) || collect($sufixos)->contains(fn (string $s) => str_ends_with($host, $s));

                if (! $ok) {
                    $falhar('Serviço de notificação não reconhecido.');
                }
            }],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        $request->user()->update(['notify_push_enabled' => true]);

        return response()->json(['ok' => true]);
    }
}

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
            'endpoint' => ['required', 'string'],
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

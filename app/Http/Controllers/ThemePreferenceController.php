<?php

namespace App\Http\Controllers;

use App\Enums\ThemePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persiste a preferência de tema (claro/escuro/sistema) do usuário
 * logado. O efeito visual imediato é só JS (ver resources/js/app.js,
 * que já aplica a classe antes desta requisição terminar) — esta rota
 * existe para o valor sobreviver entre aparelhos e sessões.
 */
class ThemePreferenceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', ThemePreference::rule()],
        ]);

        $request->user()->update(['theme' => $validated['theme']]);

        return response()->json(['ok' => true]);
    }
}

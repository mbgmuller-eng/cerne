<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Manifesto do PWA.
 *
 * Servido por rota e não por arquivo estático para que as URLs
 * acompanhem o APP_URL — na Hostinger o domínio muda entre o temporário e
 * o definitivo, e um manifesto com caminho fixo instalaria o atalho
 * apontando para o endereço errado.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'Cerne — Consultoria financeira',
            'short_name' => 'Cerne',
            'description' => 'Suas finanças, acompanhadas de perto.',
            'start_url' => route('dashboard'),
            'scope' => url('/'),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f5f5f4',
            'theme_color' => '#115e59',
            'lang' => 'pt-BR',
            'icons' => [
                [
                    'src' => asset('icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-maskable.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            'shortcuts' => [
                [
                    'name' => 'Fluxo de caixa',
                    'url' => route('cashflow.index'),
                ],
                [
                    'name' => 'Importar PDF',
                    'url' => route('documents.index'),
                ],
            ],
        ], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }
}

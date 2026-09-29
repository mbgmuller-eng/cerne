<?php

namespace App\Http\Controllers;

use App\Services\HealthCardService;
use Illuminate\Contracts\View\View;

/**
 * Página pública do QR Code de emergência — SEM login (quem escaneia é
 * um socorrista ou um familiar sem conta no Cerne). Por isso é uma view
 * solta, fora do layout autenticado: sem menu, sem dado financeiro por
 * perto, só o essencial pra emergência.
 *
 * `$token` errado ou já trocado (ver HealthCardService::regenerateEmergencyToken)
 * dá 404 — nunca uma tela "vazia" que confirmaria a existência do token.
 */
class HealthEmergencyController extends Controller
{
    public function show(string $token, HealthCardService $service): View
    {
        $dados = $service->emergencyPayload($token);

        abort_if($dados === null, 404);

        return view('health.emergency', $dados);
    }
}

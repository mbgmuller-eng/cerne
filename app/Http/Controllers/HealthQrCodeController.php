<?php

namespace App\Http\Controllers;

use App\Models\ProfileMember;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

/**
 * Imagem do QR Code de emergência — autenticado, só de quem é a ficha
 * (a pessoa dona ou o cônjuge, igual à Ficha de Saúde). Gera na hora, sem
 * guardar arquivo nenhum: são só bytes de imagem a partir da URL que já
 * carrega o token no banco.
 */
class HealthQrCodeController extends Controller
{
    public function show(string $memberId, HealthCardService $service): Response
    {
        $context = app(ProfileContext::class);
        abort_unless($context->isHealthParticipant(), 403);

        $membro = ProfileMember::query()->visibleHere()->findOrFail($memberId);

        $card = $service->ensureEmergencyToken($membro);

        // Cuidador de leitura não gera o código: só vê o QR se o dono já o criou.
        abort_if($card->emergency_token === null, 404);
        $url = route('health.emergency.show', $card->emergency_token);

        $resultado = (new Builder(writer: new PngWriter(), data: $url, size: 320, margin: 12))->build();

        return response($resultado->getString(), 200, [
            'Content-Type' => $resultado->getMimeType(),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

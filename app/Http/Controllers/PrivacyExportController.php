<?php

namespace App\Http\Controllers;

use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download da cópia dos dados. O link é assinado (expira com o arquivo), mas a assinatura sozinha não basta: o zip tem dado pessoal,
 * então quem baixa tem que estar logado como o dono, e o e-mail encaminhado a outra pessoa não abre nada.
 */
class PrivacyExportController extends Controller
{
    public function download(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->isAvailable() && Storage::disk('local')->exists($export->path), 404);

        $export->forceFill(['downloaded_at' => now()])->save();

        return Storage::disk('local')->download($export->path, 'meus-dados-cerne-'.now()->format('Y-m-d').'.zip');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\LeadAttachment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve o arquivo anexado a um lead (simulação em PDF, documento do contato) do disco privado. Só o dono do lead abre:
 * o lead não pertence a um perfil, então o isolamento é pelo `consultant_id` dele, checado aqui. Quem não é o dono
 * recebe 404, não 403, para não revelar que o arquivo existe.
 */
class LeadAttachmentController extends Controller
{
    public function show(LeadAttachment $attachment): Response
    {
        abort_unless($attachment->lead?->consultant_id === auth()->id(), 404);

        $disco = Storage::disk(config('cerne.lead_files.disk'));

        abort_unless($disco->exists($attachment->storage_path), 404);

        return response($disco->get($attachment->storage_path), 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_filename).'"',
            // Dado de um contato que ainda nem é cliente: nunca em cache compartilhado.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

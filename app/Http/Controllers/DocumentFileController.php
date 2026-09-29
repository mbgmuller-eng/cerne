<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve o arquivo de um documento (CNH, apólice, exame...) do disco
 * privado. O model binding já resolve `$document` sob BelongsToProfile +
 * DocumentVisibilityScope — um documento fora do alcance de quem pediu
 * nunca chega aqui, a rota devolve 404 antes de tentar abrir o arquivo.
 *
 * `attachment`, não `inline`: a tela busca isto via JS (baixarArquivo em
 * app.js) e nunca navega pra cá diretamente, mas se alguém colar a URL
 * direto no navegador, "baixar" em vez de "abrir dentro do app" é o
 * comportamento seguro por padrão — inline dentro de um PWA instalado no
 * iPhone prendia a pessoa na visualização, sem "voltar" nenhum.
 */
class DocumentFileController extends Controller
{
    public function show(Document $document): Response
    {
        $disco = Storage::disk(config('cerne.document_vault.disk'));

        abort_unless($disco->exists($document->storage_path), 404);

        return response($disco->get($document->storage_path), 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->original_filename).'"',
            // Privado: documento pessoal, não pode ficar em cache
            // compartilhado (proxy, CDN) nem num aparelho de uso comum.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

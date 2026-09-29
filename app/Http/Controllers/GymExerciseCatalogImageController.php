<?php

namespace App\Http\Controllers;

use App\Models\GymExerciseCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve a foto de um exercício do CATÁLOGO compartilhado — sem o escopo
 * de saúde pessoal (PersonalHealthScope): não é dado de ninguém, é
 * referência genérica que todo cliente autenticado pode ver ao montar o
 * próprio plano. `auth` (do grupo de rotas) já basta; não precisa do
 * dono do exercício, porque este exercício não tem dono.
 */
class GymExerciseCatalogImageController extends Controller
{
    public function show(Request $request, GymExerciseCatalog $exercise): Response
    {
        $caminho = $request->query('f') === '2' ? $exercise->image_path_2 : $exercise->image_path;

        abort_if($caminho === null, 404);

        $disco = Storage::disk(config('cerne.gym_images.disk'));

        abort_unless($disco->exists($caminho), 404);

        return response($disco->get($caminho), 200, [
            'Content-Type' => $disco->mimeType($caminho) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

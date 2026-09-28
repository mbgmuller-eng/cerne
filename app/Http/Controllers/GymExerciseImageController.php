<?php

namespace App\Http\Controllers;

use App\Models\GymExercise;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve UM quadro da foto de um exercício, do disco privado. O model
 * binding já resolve `$exercise` sob BelongsToProfile + PersonalHealthScope
 * — um id de outra pessoa (cônjuge, cliente de outro consultor) nunca
 * chega aqui; a rota nem tenta abrir o arquivo, devolve 404 antes disso.
 *
 * `?f=2` pede o segundo quadro (início/fim do movimento, ver
 * GymExercise::imageUrl()); qualquer outro valor cai no primeiro.
 */
class GymExerciseImageController extends Controller
{
    public function show(Request $request, GymExercise $exercise): Response
    {
        $caminho = $request->query('f') === '2' ? $exercise->image_path_2 : $exercise->image_path;

        abort_if($caminho === null, 404);

        $disco = Storage::disk(config('cerne.gym_images.disk'));

        abort_unless($disco->exists($caminho), 404);

        return response($disco->get($caminho), 200, [
            'Content-Type' => $disco->mimeType($caminho) ?: 'application/octet-stream',
            // Privado: é foto de dado de saúde, não pode ficar em cache
            // compartilhado (proxy, CDN) nem num aparelho de uso comum.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

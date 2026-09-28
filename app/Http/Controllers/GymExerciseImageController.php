<?php

namespace App\Http\Controllers;

use App\Models\GymExercise;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve a foto de UM exercício do disco privado. O model binding já
 * resolve `$exercise` sob BelongsToProfile + PersonalHealthScope — um id
 * de outra pessoa (cônjuge, cliente de outro consultor) nunca chega aqui;
 * a rota nem tenta abrir o arquivo, devolve 404 antes disso.
 */
class GymExerciseImageController extends Controller
{
    public function show(GymExercise $exercise): Response
    {
        abort_if($exercise->image_path === null, 404);

        $disco = Storage::disk(config('cerne.gym_images.disk'));

        abort_unless($disco->exists($exercise->image_path), 404);

        return response($disco->get($exercise->image_path), 200, [
            'Content-Type' => $disco->mimeType($exercise->image_path) ?: 'application/octet-stream',
            // Privado: é foto de dado de saúde, não pode ficar em cache
            // compartilhado (proxy, CDN) nem num aparelho de uso comum.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

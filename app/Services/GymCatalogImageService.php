<?php

namespace App\Services;

use App\Models\GymExerciseCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de referência do CATÁLOGO compartilhado (dois quadros: início e fim
 * do movimento). Usado pela tela de admin e pelo comando
 * cerne:gym-catalog-link-images — a gravação mora num lugar só pra os dois
 * caminhos nunca divergirem.
 *
 * Todo método que muda arquivo atualiza o registro (e portanto o
 * `updated_at`), que é o que o `?v=` de GymExerciseCatalog::imageUrl() usa
 * pra quebrar o cache do navegador. Arquivo antigo sempre sai do disco.
 */
class GymCatalogImageService
{
    /** Troca o quadro 1 e/ou 2. `null` deixa o quadro como está. */
    public function store(GymExerciseCatalog $exercicio, ?UploadedFile $quadro1, ?UploadedFile $quadro2 = null): GymExerciseCatalog
    {
        $campos = [];

        if ($quadro1 !== null) {
            $this->delete($exercicio->image_path);
            $campos['image_path'] = $this->save($quadro1);
        }
        if ($quadro2 !== null) {
            $this->delete($exercicio->image_path_2);
            $campos['image_path_2'] = $this->save($quadro2);
        }

        if ($campos !== []) {
            $exercicio->update($campos);
        }

        return $exercicio;
    }

    /**
     * Substitui o PAR inteiro (vínculo em lote): sem quadro 2 novo, o quadro
     * 2 antigo sai — um quadro 1 novo não pode animar contra o quadro 2 de
     * outra foto.
     */
    public function replacePair(GymExerciseCatalog $exercicio, UploadedFile $quadro1, ?UploadedFile $quadro2): GymExerciseCatalog
    {
        $this->delete($exercicio->image_path);
        $this->delete($exercicio->image_path_2);

        $exercicio->update([
            'image_path' => $this->save($quadro1),
            'image_path_2' => $quadro2 !== null ? $this->save($quadro2) : null,
        ]);

        return $exercicio;
    }

    /**
     * Remove um quadro. Sem o quadro 1 o quadro 2 não faz sentido sozinho, então
     * ele sobe de posição em vez de ficar órfão.
     */
    public function remove(GymExerciseCatalog $exercicio, int $quadro): GymExerciseCatalog
    {
        if ($quadro === 2) {
            $this->delete($exercicio->image_path_2);
            $exercicio->update(['image_path_2' => null]);

            return $exercicio;
        }

        $this->delete($exercicio->image_path);
        $exercicio->update(['image_path' => $exercicio->image_path_2, 'image_path_2' => null]);

        return $exercicio;
    }

    /** Apaga os dois arquivos do disco (usado ao excluir o exercício). */
    public function purge(GymExerciseCatalog $exercicio): void
    {
        $this->delete($exercicio->image_path);
        $this->delete($exercicio->image_path_2);
    }

    private function save(UploadedFile $arquivo): string
    {
        // Nome aleatório: não expõe o nome original e não colide entre exercícios.
        return $arquivo->storeAs(
            config('cerne.gym_images.path').'/catalogo',
            Str::uuid().'.'.$arquivo->extension(),
            config('cerne.gym_images.disk'),
        );
    }

    private function delete(?string $caminho): void
    {
        if ($caminho !== null) {
            Storage::disk(config('cerne.gym_images.disk'))->delete($caminho);
        }
    }
}

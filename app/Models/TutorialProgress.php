<?php

namespace App\Models;

use App\Enums\TutorialStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que uma pessoa já viu dos tutoriais das telas (ver App\Support\Tutorials\TutorialCatalog). É preferência da CONTA, não dado de um perfil
 * financeiro: quem entra em vários perfis (consultor, casal) vê o cartão de primeira visita uma vez só por tela, onde estiver.
 */
#[Fillable(['user_id', 'tutorial_key', 'status'])]
class TutorialProgress extends Model
{
    use HasUuids;

    protected $table = 'tutorial_progress';

    protected function casts(): array
    {
        return ['status' => TutorialStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

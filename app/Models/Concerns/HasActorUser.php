<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem registrou ou alterou, pelo USUÁRIO. O `created_by_member_id` só nomeia quem tem cadeira no perfil; o cuidador de uma pessoa acompanhada
 * (e o dono dela) não tem, e o "registrado por Fulano" que os irmãos precisam ver vem daqui (preenchido por CaregiverWriteGuard).
 */
trait HasActorUser
{
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}

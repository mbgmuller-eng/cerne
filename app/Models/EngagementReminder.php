<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lembrete do acompanhamento já enviado a um usuário. Só existe para sustentar o índice único
 * (usuário, assunto, tipo), que impede aviso em dobro quando o agendador dispara duas vezes.
 */
#[Fillable(['user_id', 'subject_type', 'subject_id', 'kind', 'sent_on'])]
class EngagementReminder extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['sent_on' => 'date'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento da área de Documentos anexado a uma reunião ou tarefa do acompanhamento. É uma referência: o arquivo continua
 * valendo as regras de visibilidade do próprio documento, então quem não pode ver o documento não vê o anexo (a relação
 * `document` volta vazia).
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'subject_type', 'subject_id', 'document_id', 'attached_by_user_id'])]
class EngagementAttachment extends Model
{
    use BelongsToProfile, HasUuids;

    public const MEETING = 'meeting';

    public const TASK = 'task';

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

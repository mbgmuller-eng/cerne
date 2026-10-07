<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\NormalizesNames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pasta de Documentos, em árvore (parent_id). Só organiza: quem vê cada documento
 * é decidido pela categoria dele (DocumentVisibilityScope), não pela pasta.
 */
#[Fillable(['profile_id', 'parent_id', 'name'])]
class DocumentFolder extends Model
{
    use BelongsToProfile, HasUuids, NormalizesNames;

    /** Profundidade máxima (a pasta de cima é nível 1). Cinco níveis cobrem "Propriedades > Fazenda > Escrituras > ...". */
    public const MAX_DEPTH = 5;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }
}

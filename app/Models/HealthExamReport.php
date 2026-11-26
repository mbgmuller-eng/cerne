<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um laudo guardado: de valores (cada um com a referência impressa nele) ou de conclusão, copiada como está no papel. */
#[Fillable(['professional_id', 'profile_id', 'member_id', 'request_id', 'kind', 'performed_on', 'lab', 'doctor', 'exam_name', 'conclusion', 'highlight', 'document_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamReport extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    public const KIND_VALUES = 'values';

    public const KIND_TEXT = 'report';

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'lab' => Sensitive::class,
            'doctor' => Sensitive::class,
            'exam_name' => Sensitive::class,
            'conclusion' => Sensitive::class,
            'highlight' => Sensitive::class,
        ];
    }

    public function isValues(): bool
    {
        return $this->kind === self::KIND_VALUES;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HealthExamRequest::class, 'request_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(HealthExamValue::class, 'report_id')->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HealthExamItem::class, 'report_id');
    }

    /** O PDF nos Documentos, só para quem pode vê-lo lá (o escopo do documento decide; sem acesso, vem nulo). */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

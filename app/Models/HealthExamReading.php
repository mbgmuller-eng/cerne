<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Casts\SensitiveArray;
use App\Enums\ProcessingStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um PDF de laudo mandado para leitura por IA. O que a leitura devolve fica em `summary` até a pessoa revisar e salvar: nada vira resultado antes
 * disso. A linha continua depois (sem o arquivo nem o resumo) porque o teto diário de leituras é contado nela.
 */
#[Fillable(['profile_id', 'member_id', 'request_id', 'status', 'source_path', 'original_filename', 'summary', 'error', 'report_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamReading extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['status' => ProcessingStatus::class, 'original_filename' => Sensitive::class, 'summary' => SensitiveArray::class];
    }

    /** Ainda pede alguma coisa de quem enviou: está na fila, lendo, esperando revisão ou falhou e não foi descartada. */
    public function isOpen(): bool
    {
        return $this->status !== ProcessingStatus::Committed && $this->source_path !== '';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HealthExamRequest::class, 'request_id');
    }
}

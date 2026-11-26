<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Casts\SensitiveArray;
use App\Enums\BodyRegion;
use App\Enums\LimitationStatus;
use App\Enums\MovementLoad;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma limitação física: a região, a situação, o que evitar e o que o profissional disse. Pode estar em um acompanhamento, vir de uma consulta ou
 * de um exame, ou ser do passado. Mesma visibilidade da Agenda de Saúde. Os programas de atividades da pessoa leem daqui; o que eles mudam por
 * causa dela fica com eles e é só dela.
 */
#[Fillable([
    'profile_id', 'member_id', 'followup_id', 'appointment_id', 'exam_report_id', 'since_year', 'region', 'status', 'avoid', 'guidance',
    'professional', 'professional_id', 'informed_on', 'answers', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id',
])]
class PhysicalLimitation extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'region' => Sensitive::class, 'status' => LimitationStatus::class, 'avoid' => SensitiveArray::class, 'guidance' => Sensitive::class,
            'professional' => Sensitive::class, 'informed_on' => 'date', 'answers' => SensitiveArray::class,
        ];
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }

    public function bodyRegion(): ?BodyRegion
    {
        return BodyRegion::tryFrom((string) $this->region);
    }

    public function regionLabel(): string
    {
        return $this->bodyRegion()?->label() ?? 'Região não informada';
    }

    /** @return list<MovementLoad> */
    public function avoidLoads(): array
    {
        return MovementLoad::fromValues($this->avoid);
    }

    public function isActive(): bool
    {
        return $this->status !== LimitationStatus::Released;
    }

    /** O que o profissional respondeu sobre correr: 'yes', 'not_yet' ou nulo (não foi falado). */
    public function runningAnswer(): ?string
    {
        $v = $this->answers['running'] ?? null;

        return in_array($v, ['yes', 'not_yet'], true) ? $v : null;
    }

    /** @return array<string, string> atividade => 'ok' | 'care' | 'stop' */
    public function sportAnswers(): array
    {
        return array_filter((array) ($this->answers['sports'] ?? []), fn ($v) => in_array($v, ['ok', 'care', 'stop'], true));
    }
}

<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Scopes\HealthMeasurementScope;
use App\Support\CaregiverWriteGuard;
use App\Support\ProfileContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma medida: peso, altura e cintura em uma data. Cifrada. Quem vê é decidido por HealthMeasurementScope; quem grava é a própria pessoa (ou,
 * no espaço de uma pessoa acompanhada, quem cuida com acesso de edição). Nunca Auditable: a trilha é legível pelo consultor.
 */
#[Fillable(['profile_id', 'member_id', 'measured_on', 'weight_kg', 'height_cm', 'waist_cm', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthMeasurement extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new HealthMeasurementScope);
        CaregiverWriteGuard::register(static::class);

        $soAPropria = function (HealthMeasurement $m): void {
            if (! self::canWriteFor($m->member_id)) {
                throw new AuthorizationException('Só a própria pessoa registra e apaga as medidas dela.');
            }
        };

        static::creating($soAPropria);
        static::updating($soAPropria);
        static::deleting($soAPropria);
    }

    /** O cônjuge vê o que foi compartilhado, mas não escreve. */
    public static function canWriteFor(?string $membroId): bool
    {
        $contexto = app(ProfileContext::class);

        if ($membroId === null || $contexto->isConsultant() || $contexto->profile() === null) {
            return false;
        }

        if ($contexto->profile()->isCare()) {
            return $contexto->canWriteAsCaregiver($membroId);
        }

        return $contexto->memberId() === $membroId;
    }

    /** Quem acompanha a saúde pode ler (se a pessoa compartilhou): lista de permitidos em ProfileScope. */
    public static function visibleToCaregiver(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return ['measured_on' => 'date', 'weight_kg' => Sensitive::class, 'height_cm' => Sensitive::class, 'waist_cm' => Sensitive::class];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function weight(): ?float
    {
        return is_numeric($this->weight_kg) ? (float) $this->weight_kg : null;
    }

    public function height(): ?int
    {
        return is_numeric($this->height_cm) ? (int) $this->height_cm : null;
    }
}

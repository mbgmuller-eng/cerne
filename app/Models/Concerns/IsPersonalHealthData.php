<?php

namespace App\Models\Concerns;

use App\Models\ProfileMember;
use App\Models\Scopes\PersonalHealthScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marca um model como dado de saúde PESSOAL: escopo global que só deixa o
 * dono enxergar (ver PersonalHealthScope) e carimbo de member_id na criação.
 *
 * Usar junto com BelongsToProfile (o tenancy continua valendo), e NUNCA com
 * Auditable: audit_logs é legível pelo consultor (ver
 * FinancialProfilePolicy::viewAuditLog) e gravaria valores de saúde lá.
 * Há teste guardando isso (GymPrivacyTest).
 */
trait IsPersonalHealthData
{
    public static function bootIsPersonalHealthData(): void
    {
        static::addGlobalScope(new PersonalHealthScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('member_id') !== null) {
                return;
            }

            $memberId = app(ProfileContext::class)->memberId();

            // Escrever sem saber de quem é o dado criaria uma linha que
            // ninguém enxerga — ou pior, atribuída à pessoa errada.
            if ($memberId === null) {
                throw new LogicException(
                    'Dado de saúde pessoal precisa de um membro dono; o contexto atual não tem nenhum.'
                );
            }

            $model->setAttribute('member_id', $memberId);
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }
}

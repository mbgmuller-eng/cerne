<?php

namespace App\Models\Concerns;

use App\Models\ProfileMember;
use App\Models\Scopes\CoupleHealthScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marca um model como dado de saúde da FICHA DO CASAL: escopo global que
 * deixa os dois membros do perfil enxergarem (ver CoupleHealthScope),
 * nunca o consultor/corretor.
 *
 * A diferença central pra IsPersonalHealthData (Academia): lá quem cria o
 * registro só pode ser dono dele (carimba sozinho, do contexto). Aqui
 * Marcelo pode estar registrando uma alergia da Helen — member_id é
 * "de quem é o fato", não "quem está logado agora", então TEM que vir
 * explícito do chamador. Por isso não existe carimbo automático de
 * member_id aqui: se vier nulo, é erro de programação (esqueceram de
 * dizer de quem é o dado), não um caso a cobrir com contexto.
 *
 * Usar junto com BelongsToProfile, e nunca com Auditable — mesmo raciocínio
 * de IsPersonalHealthData: audit_logs é legível pelo consultor.
 */
trait IsCoupleHealthData
{
    public static function bootIsCoupleHealthData(): void
    {
        static::addGlobalScope(new CoupleHealthScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('member_id') === null) {
                throw new LogicException(
                    'Dado da ficha de saúde precisa dizer explicitamente de qual membro é — nunca adivinhado do contexto.'
                );
            }

            if ($model->getAttribute('profile_id') === null) {
                $profileId = app(ProfileContext::class)->profileId();

                if ($profileId === null) {
                    throw new LogicException('Sem perfil ativo — não é possível gravar a ficha de saúde.');
                }

                $model->setAttribute('profile_id', $profileId);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    /**
     * Ignora o escopo de saúde do casal. Só pra código que legitimamente
     * atravessa perfis por fora de uma sessão de usuário — o cron de
     * lembrete de consulta (sem ProfileContext nenhum) e a página pública
     * de emergência (o visitante nunca fez login). Sempre combinar com um
     * filtro explícito (token, id) no chamador; nunca devolver a lista
     * inteira sem um "de qual perfil" dito na própria query.
     */
    public static function withoutCoupleHealthScope(): Builder
    {
        return static::query()->withoutGlobalScope(CoupleHealthScope::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * O perfil financeiro é a unidade de isolamento do sistema: toda query do
 * domínio filtra por profile_id (ver BelongsToProfile).
 *
 * Note que ESTE model não usa BelongsToProfile — ele é o próprio tenant.
 */
#[Fillable(['owner_user_id', 'profile_name', 'profile_type', 'base_currency', 'reference_month'])]
class FinancialProfile extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'profile_type' => ProfileType::class,
            'reference_month' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProfileMember::class, 'profile_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('is_active', true);
    }

    public function accessSettings(): HasOne
    {
        return $this->hasOne(ProfileAccessSettings::class, 'profile_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'profile_id');
    }

    /**
     * Configuração de privacidade, criando o padrão transparente na
     * primeira leitura. Um perfil sem configuração não pode cair no
     * caminho de "nega tudo" nem no de "libera tudo por acidente" —
     * é melhor materializar o padrão explicitamente.
     *
     * setRelation() depois do create() é obrigatório: sem isso, a próxima
     * chamada de settings() no MESMO objeto $profile lê de novo a relação
     * já carregada (cacheada como nula desde a checagem acima) e tenta
     * criar outra linha — que colide com a constraint única de profile_id.
     * MemberPrivacyScope chama settings() a cada query com privacidade
     * restrita, então isso quebraria no segundo acesso do mesmo request.
     */
    public function settings(): ProfileAccessSettings
    {
        return $this->accessSettings ?? tap(
            $this->accessSettings()->create(['updated_by_user_id' => $this->owner_user_id]),
            fn (ProfileAccessSettings $settings) => $this->setRelation('accessSettings', $settings),
        );
    }

    public function memberFor(User $user): ?ProfileMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }
}

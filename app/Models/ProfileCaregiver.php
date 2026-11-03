<?php

namespace App\Models;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverGrantBasis;
use App\Enums\CaregiverStatus;
use App\Models\Scopes\CareProfileScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Quem acompanha uma pessoa sem ser dono nem membro dela. Vê o que a pessoa liberou: a saúde e os documentos (com leitura ou edição) e/ou as finanças
 * (SEMPRE só leitura, e só o que não é privado).
 *
 * Não usa BelongsToProfile: é a tabela que DECIDE se alguém abre o perfil (a policy e o SetProfileContext a consultam antes de existir contexto),
 * mesmo raciocínio de ProfileMember. Toda consulta filtra profile_id ou user_id explicitamente. Por que não é um ProfileMember: o cuidador
 * apareceria nos seletores de "de quem é" da agenda, da ficha e dos documentos.
 */
#[Fillable([
    'profile_id', 'member_id', 'user_id', 'invited_by_user_id', 'invited_email', 'invited_name', 'access_level', 'status', 'invite_token', 'invite_expires_at',
    'accepted_at', 'revoked_at', 'notify_reminders', 'notify_care_items',
    'can_view_health', 'can_view_finance', 'notify_finance', 'notify_weekly', 'notify_alerts', 'grant_basis', 'granted_by_user_id', 'granted_declared_at',
])]
class ProfileCaregiver extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'access_level' => CaregiverAccess::class,
            'status' => CaregiverStatus::class,
            'invite_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'notify_reminders' => 'boolean',
            'notify_care_items' => 'boolean',
            'can_view_health' => 'boolean',
            'can_view_finance' => 'boolean',
            'notify_finance' => 'boolean',
            'notify_weekly' => 'boolean',
            'notify_alerts' => 'boolean',
            'grant_basis' => CaregiverGrantBasis::class,
            'granted_declared_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id')->withoutGlobalScope(CareProfileScope::class);
    }

    /** De quem é o acesso: a pessoa cuja saúde este cuidador acompanha (o membro; na pessoa acompanhada, o único dela). */
    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** O consultor que montou o acesso em nome da pessoa (convite assistido), se foi o caso. */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CaregiverStatus::Active->value)->whereNotNull('user_id');
    }

    /**
     * Cria (ou renova) o convite e devolve o token em claro, a única vez que ele existe fora do hash.
     *
     * @return array{caregiver: self, token: string}
     */
    public static function issue(FinancialProfile $profile, ProfileMember $member, User $issuer, string $name, string $email, CaregiverAccess $access, bool $health = true, bool $finance = false, ?CaregiverGrantBasis $basis = null, ?User $grantedBy = null): array
    {
        $token = Str::random(48);
        $email = mb_strtolower(trim($email));

        // Reconvidar a mesma pessoa reaproveita a linha (índice único pessoa acompanhada + e-mail): reativa quem foi revogado e renova o link de quem não aceitou.
        $caregiver = self::query()->where('member_id', $member->id)->where('invited_email', $email)->first()
            ?? new self(['profile_id' => $profile->id, 'member_id' => $member->id, 'invited_email' => $email]);

        $caregiver->fill([
            'invited_by_user_id' => $issuer->id,
            'invited_name' => $name,
            'access_level' => $access,
            'can_view_health' => $health,
            'can_view_finance' => $finance,
            'grant_basis' => $basis,
            'granted_by_user_id' => $grantedBy?->id,
            'granted_declared_at' => $grantedBy !== null ? now() : null,
            'invite_token' => hash('sha256', $token),
            'invite_expires_at' => now()->addDays((int) config('cerne.invite.expires_in_days')),
            'revoked_at' => null,
        ]);

        // Quem já aceitou continua ativo; os demais voltam a "convidado".
        if ($caregiver->status !== CaregiverStatus::Active) {
            $caregiver->status = CaregiverStatus::Invited;
            $caregiver->user_id = null;
        }

        $caregiver->save();

        return ['caregiver' => $caregiver, 'token' => $token];
    }

    public static function findValidByToken(string $plainToken): ?self
    {
        $caregiver = self::query()->where('invite_token', hash('sha256', $plainToken))->where('status', CaregiverStatus::Invited->value)->first();

        return $caregiver !== null && $caregiver->invite_expires_at?->isFuture() ? $caregiver : null;
    }
}

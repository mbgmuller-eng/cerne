<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Convite para a própria pessoa acompanhada ganhar login. Não usa BelongsToProfile: é consultado pelo dono sobre um perfil de cuidado (escondido
 * por padrão) e por quem abre o link ainda sem perfil nenhum, sempre filtrando profile_id ou o token explicitamente.
 */
#[Fillable(['profile_id', 'member_id', 'invited_by_user_id', 'email', 'token', 'expires_at', 'status', 'accepted_at'])]
class CarePersonInvite extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['status' => InviteStatus::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id')->withoutGlobalScope(\App\Models\Scopes\CareProfileScope::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return array{invite: self, token: string} o token em claro, a única vez que existe fora do hash
     */
    public static function issue(FinancialProfile $profile, ProfileMember $member, User $issuer, string $email): array
    {
        $token = Str::random(48);

        // Reenviar substitui o convite anterior em vez de empilhar.
        self::query()->where('profile_id', $profile->id)->where('status', InviteStatus::Pending->value)->update(['status' => InviteStatus::Expired->value]);

        $invite = self::create([
            'profile_id' => $profile->id,
            'member_id' => $member->id,
            'invited_by_user_id' => $issuer->id,
            'email' => mb_strtolower(trim($email)),
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('cerne.invite.expires_in_days')),
            'status' => InviteStatus::Pending,
        ]);

        return ['invite' => $invite, 'token' => $token];
    }

    public static function findValid(string $plainToken): ?self
    {
        $invite = self::query()->where('token', hash('sha256', $plainToken))->where('status', InviteStatus::Pending->value)->first();

        return $invite !== null && $invite->expires_at->isFuture() ? $invite : null;
    }
}

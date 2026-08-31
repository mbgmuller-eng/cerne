<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Convite de cônjuge — mesma forma de ConsultantInvite (token com hash,
 * expira, aceite vira usuário), mas parte de um perfil que já existe.
 *
 * Não usa BelongsToProfile de propósito: é consultado tanto pelo titular
 * (dentro do próprio contexto de perfil) quanto pelo consultor (sobre um
 * perfil de cliente que NÃO é o contexto ativo dele) — sempre filtra
 * profile_id explicitamente no ponto de uso, mesmo raciocínio de
 * ConsultantInvite (ver TenancyCoverageTest).
 */
#[Fillable(['profile_id', 'invited_by_user_id', 'partner_name', 'partner_email', 'token', 'expires_at', 'status', 'accepted_at'])]
class PartnerInvite extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Cria o convite e devolve o token em claro junto — é a única vez que
     * ele existe fora do hash. O chamador monta o link com ele.
     *
     * @return array{invite: self, token: string}
     */
    public static function issue(FinancialProfile $profile, User $issuer, string $name, string $email): array
    {
        $plainToken = Str::random(48);

        $invite = self::create([
            'profile_id' => $profile->id,
            'invited_by_user_id' => $issuer->id,
            'partner_name' => $name,
            'partner_email' => $email,
            'token' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(config('cerne.invite.expires_in_days')),
            'status' => InviteStatus::Pending,
        ]);

        return ['invite' => $invite, 'token' => $plainToken];
    }

    /** Localiza um convite ainda válido a partir do token do link. */
    public static function findValid(string $plainToken): ?self
    {
        $invite = self::query()
            ->where('token', hash('sha256', $plainToken))
            ->where('status', InviteStatus::Pending)
            ->first();

        return $invite?->isExpired() ? null : $invite;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

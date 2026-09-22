<?php

namespace App\Models;

use App\Enums\InviteStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Convite de conta PROFISSIONAL (Consultor ou Corretor), emitido pelo
 * admin — diferente de ConsultantInvite, que sempre nasce cliente. Quem
 * aceita não ganha perfil financeiro nenhum (ver ProfessionalOnboardingService),
 * só a conta com o papel declarado; o vínculo com clientes vem depois,
 * pelo próprio profissional (ConsultantLinkService).
 */
#[Fillable(['invited_by_user_id', 'name', 'email', 'role', 'token', 'expires_at', 'status', 'accepted_at'])]
class ProfessionalInvite extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => InviteStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return array{invite: self, token: string}
     */
    public static function issue(?User $admin, string $name, string $email, UserRole $role): array
    {
        $plainToken = Str::random(48);

        $invite = self::create([
            'invited_by_user_id' => $admin?->id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'token' => hash('sha256', $plainToken),
            'expires_at' => Carbon::now()->addDays(config('cerne.invite.expires_in_days')),
            'status' => InviteStatus::Pending,
        ]);

        return ['invite' => $invite, 'token' => $plainToken];
    }

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

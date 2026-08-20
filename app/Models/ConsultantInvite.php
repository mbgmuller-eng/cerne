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

#[Fillable(['consultant_id', 'client_name', 'client_email', 'token', 'expires_at', 'status', 'accepted_at'])]
class ConsultantInvite extends Model
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

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    /**
     * Cria o convite e devolve o token em claro junto — é a única vez que
     * ele existe fora do hash. O chamador monta o link com ele.
     *
     * @return array{invite: self, token: string}
     */
    public static function issue(User $consultant, string $name, string $email): array
    {
        $plainToken = Str::random(48);

        $invite = self::create([
            'consultant_id' => $consultant->id,
            'client_name' => $name,
            'client_email' => $email,
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

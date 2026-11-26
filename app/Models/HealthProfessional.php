<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Scopes\HealthProfessionalScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um médico ou outro profissional de saúde guardado para reusar. É dado de saúde (a especialidade já diz algo): quem o vê é decidido pelas pessoas
 * que ele atende (HealthProfessionalScope). Nunca Auditable: a trilha é legível pelo consultor.
 */
#[Fillable(['profile_id', 'name', 'specialty', 'registry', 'place', 'address', 'phone', 'notes', 'is_favorite', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthProfessional extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new HealthProfessionalScope);
        \App\Support\CaregiverWriteGuard::register(static::class);
    }

    /** Quem acompanha a saúde enxerga (lista de permitidos em ProfileScope); o escopo próprio restringe às pessoas dele. */
    public static function visibleToCaregiver(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'name' => Sensitive::class, 'specialty' => Sensitive::class, 'registry' => Sensitive::class, 'place' => Sensitive::class,
            'address' => Sensitive::class, 'phone' => Sensitive::class, 'notes' => Sensitive::class, 'is_favorite' => 'boolean',
        ];
    }

    /** Só as ligações que quem olha enxerga. */
    public function links(): HasMany
    {
        return $this->hasMany(HealthProfessionalLink::class, 'professional_id');
    }
}

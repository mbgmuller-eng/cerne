<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cabeçalho da ficha de saúde de UMA pessoa: tipo sanguíneo, QR de emergência e o que ela escolheu mostrar das medidas e limitações. */
#[Fillable(['profile_id', 'member_id', 'blood_type', 'updated_by_member_id', 'emergency_token', 'share_measurements', 'qr_measurements', 'qr_limitations'])]
class HealthCard extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    /** O que expõe um dado da pessoa: o resto da ficha o casal edita junto, isto não. */
    private const OWNER_ONLY = ['share_measurements', 'qr_measurements', 'qr_limitations'];

    protected static function booted(): void
    {
        static::saving(function (HealthCard $ficha): void {
            $mexeu = array_filter(self::OWNER_ONLY, fn (string $c) => $ficha->isDirty($c) && ($ficha->exists || (bool) $ficha->getAttribute($c)));

            if ($mexeu !== [] && ! HealthMeasurement::canWriteFor($ficha->member_id)) {
                throw new AuthorizationException('Só a própria pessoa escolhe o que mostrar das medidas e limitações dela.');
            }
        });
    }

    protected function casts(): array
    {
        return ['share_measurements' => 'boolean', 'qr_measurements' => 'boolean', 'qr_limitations' => 'boolean'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'updated_by_member_id');
    }
}

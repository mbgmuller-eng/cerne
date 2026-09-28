<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lista curta de equipamentos da pessoa ("Halteres", "Polia", "Máquina do
 * canto"). É uma tabela, não texto livre, pra o gráfico não tratar
 * "máquina do canto" e "maquina canto" como equipamentos diferentes.
 */
#[Fillable(['profile_id', 'member_id', 'name'])]
class GymEquipment extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    // "equipment" é incontável em inglês: o Laravel não pluraliza, então
    // o nome padrão já daria isto — explícito pra ninguém precisar adivinhar.
    protected $table = 'gym_equipment';
}

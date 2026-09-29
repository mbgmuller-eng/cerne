<?php

namespace App\Services;

use App\Enums\HealthMedicationChangeType;
use App\Models\HealthAllergy;
use App\Models\HealthCard;
use App\Models\HealthCondition;
use App\Models\HealthMedication;
use App\Models\HealthMedicationChange;
use App\Models\ProfileMember;

/**
 * Ficha de saúde do casal (Cerne Saúde, fase A): tipo sanguíneo, alergia,
 * doença e remédio — com o remédio ganhando uma linha do tempo de
 * alteração, que é o motivo do recurso existir (ver memória do projeto).
 *
 * `$member` é sempre "de quem é o fato"; `$autor` é sempre quem está
 * logado fazendo a ação — podem ser pessoas diferentes (Marcelo
 * registrando uma alergia da Helen).
 */
class HealthCardService
{
    public function cardFor(ProfileMember $member): HealthCard
    {
        return HealthCard::query()->firstOrCreate(['member_id' => $member->id]);
    }

    public function setBloodType(ProfileMember $member, ?string $bloodType, ProfileMember $autor): HealthCard
    {
        $card = $this->cardFor($member);
        $card->update([
            'blood_type' => $bloodType !== null && trim($bloodType) !== '' ? trim($bloodType) : null,
            'updated_by_member_id' => $autor->id,
        ]);

        return $card;
    }

    public function addAllergy(ProfileMember $member, string $description, ProfileMember $autor): HealthAllergy
    {
        return HealthAllergy::create([
            'member_id' => $member->id,
            'description' => trim($description),
            'created_by_member_id' => $autor->id,
        ]);
    }

    public function removeAllergy(HealthAllergy $allergy): void
    {
        $allergy->delete();
    }

    public function addCondition(ProfileMember $member, string $description, ProfileMember $autor): HealthCondition
    {
        return HealthCondition::create([
            'member_id' => $member->id,
            'description' => trim($description),
            'created_by_member_id' => $autor->id,
        ]);
    }

    public function removeCondition(HealthCondition $condition): void
    {
        $condition->delete();
    }

    /** @param  array<string, mixed>  $dados  name, dose, schedule, reason, prescriber, started_on */
    public function addMedication(ProfileMember $member, array $dados, ProfileMember $autor): HealthMedication
    {
        $medicamento = HealthMedication::create([
            'member_id' => $member->id,
            'name' => trim($dados['name']),
            'dose' => $this->blankToNull($dados['dose'] ?? null),
            'schedule' => $this->blankToNull($dados['schedule'] ?? null),
            'reason' => $this->blankToNull($dados['reason'] ?? null),
            'prescriber' => $this->blankToNull($dados['prescriber'] ?? null),
            'started_on' => $dados['started_on'] ?? null,
            'is_active' => true,
            'created_by_member_id' => $autor->id,
        ]);

        $this->logChange($medicamento, HealthMedicationChangeType::Started, null, $medicamento->dose, $autor);

        return $medicamento;
    }

    /**
     * Atualiza o remédio e registra na linha do tempo só o que
     * REALMENTE mudou (dose e ativo/suspenso) — nome, horário, motivo e
     * prescritor são só correção de cadastro, não viram entrada de
     * histórico.
     *
     * @param  array<string, mixed>  $dados
     */
    public function updateMedication(HealthMedication $medicamento, array $dados, ProfileMember $autor): HealthMedication
    {
        $doseAntiga = $medicamento->dose;
        $ativoAntes = $medicamento->is_active;

        $novaDose = $this->blankToNull($dados['dose'] ?? $medicamento->dose);
        $novoAtivo = array_key_exists('is_active', $dados) ? (bool) $dados['is_active'] : $medicamento->is_active;

        $medicamento->update([
            'name' => isset($dados['name']) ? trim($dados['name']) : $medicamento->name,
            'dose' => $novaDose,
            'schedule' => array_key_exists('schedule', $dados) ? $this->blankToNull($dados['schedule']) : $medicamento->schedule,
            'reason' => array_key_exists('reason', $dados) ? $this->blankToNull($dados['reason']) : $medicamento->reason,
            'prescriber' => array_key_exists('prescriber', $dados) ? $this->blankToNull($dados['prescriber']) : $medicamento->prescriber,
            'ended_on' => array_key_exists('ended_on', $dados) ? $dados['ended_on'] : $medicamento->ended_on,
            'is_active' => $novoAtivo,
        ]);

        if ($novaDose !== $doseAntiga) {
            $this->logChange($medicamento, HealthMedicationChangeType::DoseChanged, $doseAntiga, $novaDose, $autor);
        }

        if ($ativoAntes !== $novoAtivo) {
            $this->logChange($medicamento, $novoAtivo ? HealthMedicationChangeType::Resumed : HealthMedicationChangeType::Suspended, null, null, $autor);
        }

        return $medicamento;
    }

    public function addMedicationNote(HealthMedication $medicamento, string $nota, ProfileMember $autor): HealthMedicationChange
    {
        return $this->logChange($medicamento, HealthMedicationChangeType::Note, null, null, $autor, $nota);
    }

    private function logChange(
        HealthMedication $medicamento,
        HealthMedicationChangeType $tipo,
        ?string $antigo,
        ?string $novo,
        ProfileMember $autor,
        ?string $nota = null,
    ): HealthMedicationChange {
        return HealthMedicationChange::create([
            'member_id' => $medicamento->member_id,
            'medication_id' => $medicamento->id,
            'change_type' => $tipo,
            'old_value' => $antigo,
            'new_value' => $novo,
            'note' => $nota,
            'changed_by_member_id' => $autor->id,
        ]);
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

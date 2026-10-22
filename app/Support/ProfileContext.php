<?php

namespace App\Support;

use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;

/**
 * O perfil financeiro ativo da requisição.
 *
 * Registrado como singleton e preenchido pelo middleware SetProfileContext.
 * É a fonte de verdade do escopo global BelongsToProfile — se o contexto
 * estiver vazio, nenhuma query do domínio devolve linha alguma.
 */
class ProfileContext
{
    private ?FinancialProfile $profile = null;

    private ?ProfileMember $member = null;

    /** Consultor operando sobre o perfil de um cliente. */
    private bool $asConsultant = false;

    /** Cuidador de uma pessoa acompanhada: vê só saúde e documentos liberados, com leitura ou edição. */
    private ?ProfileCaregiver $caregiver = null;

    public function set(FinancialProfile $profile, ?ProfileMember $member = null, bool $asConsultant = false, ?ProfileCaregiver $caregiver = null): void
    {
        $this->caregiver = $caregiver;
        $this->profile = $profile;
        $this->member = $member;
        $this->asConsultant = $asConsultant;
    }

    public function clear(): void
    {
        $this->profile = null;
        $this->member = null;
        $this->asConsultant = false;
        $this->caregiver = null;
    }

    /**
     * Pode abrir ficha, agenda e cuidados do perfil ativo: membro com cadeira, cuidador, ou o dono do perfil de uma pessoa acompanhada (que não
     * tem cadeira nele). Consultor e corretor nunca.
     */
    public function isHealthParticipant(): bool
    {
        return $this->profile !== null
            && ! $this->asConsultant
            && ($this->member !== null || $this->caregiver !== null || $this->profile->isCare());
    }

    public function isCaregiver(): bool
    {
        return $this->caregiver !== null;
    }

    public function caregiver(): ?ProfileCaregiver
    {
        return $this->caregiver;
    }

    /** Quem não é cuidador sempre pode (as outras regras de escrita são as de sempre); cuidador só com acesso de edição. */
    public function canWriteAsCaregiver(): bool
    {
        return $this->caregiver === null || $this->caregiver->access_level->canWrite();
    }

    public function profile(): ?FinancialProfile
    {
        return $this->profile;
    }

    public function profileId(): ?string
    {
        return $this->profile?->id;
    }

    /** A cadeira que o usuário logado ocupa neste perfil, se ocupar alguma. */
    public function member(): ?ProfileMember
    {
        return $this->member;
    }

    public function memberId(): ?string
    {
        return $this->member?->id;
    }

    public function hasProfile(): bool
    {
        return $this->profile !== null;
    }

    /**
     * Consultor vinculado enxerga tudo, independentemente do que cada
     * lançamento tem marcado como privado (seção 14 da especificação).
     */
    public function isConsultant(): bool
    {
        return $this->asConsultant;
    }
}

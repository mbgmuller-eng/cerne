<?php

namespace App\Support;

use App\Models\FinancialProfile;
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

    public function set(FinancialProfile $profile, ?ProfileMember $member = null, bool $asConsultant = false): void
    {
        $this->profile = $profile;
        $this->member = $member;
        $this->asConsultant = $asConsultant;
    }

    public function clear(): void
    {
        $this->profile = null;
        $this->member = null;
        $this->asConsultant = false;
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

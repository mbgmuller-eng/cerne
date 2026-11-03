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

    /** Quem acompanha: vê o que a pessoa liberou (saúde e documentos, com leitura ou edição; e/ou finanças, só leitura). */
    private ?ProfileCaregiver $caregiver = null;

    /** Todos os acessos de cuidador do usuário NESTE perfil (um por pessoa acompanhada). */
    private array $grants = [];

    /** @var array<string, string>|null id da pessoa => 'read'|'edit' (calculado sob demanda; só os acessos de SAÚDE) */
    private ?array $levels = null;

    /** @var list<string>|null id das pessoas cujas finanças este acompanhante vê */
    private ?array $financeIds = null;

    /** @param  list<ProfileCaregiver>  $grants todos os acessos do cuidador neste perfil; sem a lista vale só $caregiver */
    public function set(FinancialProfile $profile, ?ProfileMember $member = null, bool $asConsultant = false, ?ProfileCaregiver $caregiver = null, array $grants = []): void
    {
        $this->caregiver = $caregiver;
        $this->grants = $grants !== [] ? $grants : ($caregiver !== null ? [$caregiver] : []);
        $this->levels = null;
        $this->financeIds = null;
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
        $this->grants = [];
        $this->levels = null;
        $this->financeIds = null;
    }

    /**
     * Pode abrir ficha, agenda e cuidados do perfil ativo: membro com cadeira, cuidador, ou o dono do perfil de uma pessoa acompanhada (que não
     * tem cadeira nele). Consultor e corretor nunca.
     */
    public function isHealthParticipant(): bool
    {
        return $this->profile !== null
            && ! $this->asConsultant
            && ($this->member !== null || $this->hasHealthAccess() || $this->profile->isCare());
    }

    public function isCaregiver(): bool
    {
        return $this->caregiver !== null;
    }

    /** Algum acesso deste acompanhante inclui a saúde? */
    public function hasHealthAccess(): bool
    {
        foreach ($this->grants as $acesso) {
            if ($acesso->can_view_health) {
                return true;
            }
        }

        return false;
    }

    /** Algum acesso deste acompanhante inclui as finanças (só leitura)? */
    public function hasFinanceAccess(): bool
    {
        foreach ($this->grants as $acesso) {
            if ($acesso->can_view_finance) {
                return true;
            }
        }

        return false;
    }

    /**
     * As pessoas cujas finanças este acompanhante vê neste perfil. Acesso sem pessoa marcada vale para todos os membros. O que cada uma marcou como
     * privado nunca entra (MemberPrivacyScope).
     *
     * @return list<string>
     */
    public function financeMemberIds(): array
    {
        if ($this->financeIds !== null) {
            return $this->financeIds;
        }

        $ids = [];

        foreach ($this->grants as $acesso) {
            if (! $acesso->can_view_finance) {
                continue;
            }

            $ids = array_merge($ids, $acesso->member_id !== null ? [$acesso->member_id] : ProfileMember::query()->where('profile_id', $acesso->profile_id)->pluck('id')->all());
        }

        return $this->financeIds = array_values(array_unique($ids));
    }

    public function caregiver(): ?ProfileCaregiver
    {
        return $this->caregiver;
    }

    /**
     * Quem não é cuidador sempre pode (as outras regras de escrita são as de sempre); cuidador só com acesso de edição. Com `$memberId`, vale o nível
     * dele para ESSA pessoa (quem tem edição de uma e leitura de outra não grava na segunda); sem, basta ter edição em alguma.
     */
    public function canWriteAsCaregiver(?string $memberId = null): bool
    {
        if ($this->caregiver === null) {
            return true;
        }

        $niveis = $this->grantLevels();

        if ($memberId === null) {
            return in_array('edit', $niveis, true);
        }

        return ($niveis[$memberId] ?? 'read') === 'edit';
    }

    /**
     * As pessoas que o cuidador acompanha neste perfil. Acesso sem pessoa marcada (linha antiga) vale para todos os membros do perfil.
     *
     * @return list<string>
     */
    public function grantedMemberIds(): array
    {
        return array_keys($this->grantLevels());
    }

    /** "Maria" ou "Maria e João": as pessoas que este cuidador acompanha, para mostrar na tela. */
    public function followedNames(): string
    {
        $ids = array_values(array_unique(array_merge($this->grantedMemberIds(), $this->financeMemberIds())));
        $nomes = ProfileMember::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();

        return $nomes !== [] ? implode(' e ', $nomes) : (string) $this->profile?->profile_name;
    }

    /** @return array<string, string> id da pessoa => 'read'|'edit' */
    public function grantLevels(): array
    {
        if ($this->levels !== null) {
            return $this->levels;
        }

        $niveis = [];

        foreach ($this->grants as $acesso) {
            // Só o acesso à SAÚDE entra aqui: quem só acompanha as finanças não ganha ficha, agenda nem documentos de ninguém.
            if (! $acesso->can_view_health) {
                continue;
            }

            $ids = $acesso->member_id !== null
                ? [$acesso->member_id]
                : ProfileMember::query()->where('profile_id', $acesso->profile_id)->pluck('id')->all();

            foreach ($ids as $id) {
                $nivel = $acesso->access_level->value;
                // Dois acessos à mesma pessoa: vale o mais forte.
                $niveis[$id] = ($niveis[$id] ?? 'read') === 'edit' ? 'edit' : $nivel;
            }
        }

        return $this->levels = $niveis;
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

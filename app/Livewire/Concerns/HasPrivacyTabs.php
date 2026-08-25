<?php

namespace App\Livewire\Concerns;

use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * 3 abas — Casal / cada membro — pras telas do consultor que listam
 * dado com privacidade entre o casal (despesas, contas, investimentos,
 * seguros...). Só aparecem quando o casal de fato tem algum domínio
 * marcado como own_only (ver privacyDomains()); pra quem nunca mexeu
 * na privacidade, a tela continua igual, sem aba nenhuma — não é um
 * controle de acesso novo, é só uma lente de apresentação a mais.
 *
 * Cada tela decide, na própria query de listagem, o que "casal" e
 * "membro X" significam pra ela (coluna direta, relação, is_joint...)
 * — a trait só resolve SE mostrar abas e QUAL está selecionada.
 */
trait HasPrivacyTabs
{
    #[Url]
    public string $viewAs = '';

    /** Colunas de profile_access_settings relevantes pra esta tela. */
    abstract protected function privacyDomains(): array;

    public function setViewAs(string $viewAs): void
    {
        $this->viewAs = $viewAs;
    }

    public function getShowPrivacyTabsProperty(): bool
    {
        if ($this->privacyMembers->count() < 2) {
            return false;
        }

        $settings = app(ProfileContext::class)->profile()?->settings();

        if ($settings === null) {
            return false;
        }

        foreach ($this->privacyDomains() as $domain) {
            if (! $settings->sharesDomain($domain)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, ProfileMember> */
    public function getPrivacyMembersProperty(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}

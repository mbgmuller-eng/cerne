<?php

namespace App\Livewire\Concerns;

use App\Models\ProfileMember;
use App\Support\PrivacyGovernedModels;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * 3 abas — Casal / cada membro — pras telas do consultor que listam
 * dado com privacidade entre o casal (despesas, contas, investimentos,
 * seguros...). Só aparecem quando o casal de fato tem algum lançamento
 * marcado como oculto (ver privacyModels()); pra quem nunca marcou nada,
 * a tela continua igual, sem aba nenhuma — não é um controle de acesso
 * novo, é só uma lente de apresentação a mais.
 *
 * Cada tela decide, na própria query de listagem, o que "casal" e
 * "membro X" significam pra ela (coluna direta, relação, is_joint...)
 * — a trait só resolve SE mostrar abas e QUAL está selecionada.
 */
trait HasPrivacyTabs
{
    #[Url]
    public string $viewAs = '';

    /** Models relevantes pra esta tela — ver PrivacyGovernedModels. */
    abstract protected function privacyModels(): array;

    public function setViewAs(string $viewAs): void
    {
        $this->viewAs = $viewAs;
    }

    public function getShowPrivacyTabsProperty(): bool
    {
        if ($this->privacyMembers->count() < 2) {
            return false;
        }

        $profileId = app(ProfileContext::class)->profileId();

        if ($profileId === null) {
            return false;
        }

        return PrivacyGovernedModels::anyPrivate($profileId, $this->privacyModels());
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

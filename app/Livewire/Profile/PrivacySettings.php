<?php

namespace App\Livewire\Profile;

use App\Enums\Visibility;
use App\Models\ProfileAccessSettings;
use App\Support\ProfileContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 9 — Configurações de privacidade do casal.
 *
 * Três atalhos (Transparente / Privado / Personalizado) escrevendo em
 * profile_access_settings, com a visão campo a campo logo abaixo.
 *
 * Só o dono do perfil altera isto — nem o consultor mexe (ver a policy).
 */
#[Layout('components.layouts.app')]
class PrivacySettings extends Component
{
    /** @var array<string, string> coluna => valor do enum Visibility */
    public array $visibility = [];

    public bool $canEditPartnerRecords = true;

    public function mount(): void
    {
        $profile = app(ProfileContext::class)->profile();

        abort_if($profile === null, 404);
        $this->authorize('updatePrivacy', $profile);

        $settings = $profile->settings();

        foreach (array_keys(ProfileAccessSettings::DOMAINS) as $domain) {
            $this->visibility[$domain] = $settings->{$domain}->value;
        }

        $this->canEditPartnerRecords = $settings->can_edit_partner_records;
    }

    public function applyPreset(string $preset): void
    {
        $values = match ($preset) {
            'transparent' => ProfileAccessSettings::transparentPreset(),
            'private' => ProfileAccessSettings::privatePreset(),
            default => null,
        };

        if ($values === null) {
            return;
        }

        foreach (array_keys(ProfileAccessSettings::DOMAINS) as $domain) {
            $this->visibility[$domain] = $values[$domain]->value;
        }

        $this->canEditPartnerRecords = $values['can_edit_partner_records'];

        $this->save();
    }

    public function save(): void
    {
        $profile = app(ProfileContext::class)->profile();

        abort_if($profile === null, 404);
        $this->authorize('updatePrivacy', $profile);

        $this->validate([
            'visibility.*' => ['required', Visibility::rule()],
            'canEditPartnerRecords' => ['boolean'],
        ]);

        $profile->settings()->update(array_merge($this->visibility, [
            'can_edit_partner_records' => $this->canEditPartnerRecords,
            'updated_by_user_id' => auth()->id(),
        ]));

        session()->flash('status', 'Configurações de privacidade atualizadas.');
    }

    /** Qual atalho descreve o estado atual do formulário. */
    public function getCurrentPresetProperty(): string
    {
        $values = array_values($this->visibility);

        $todosCompartilhados = ! in_array(Visibility::OwnOnly->value, $values, true);
        $todosPrivados = ! in_array(Visibility::AllMembers->value, $values, true);

        return match (true) {
            $todosCompartilhados && $this->canEditPartnerRecords => 'transparent',
            $todosPrivados && ! $this->canEditPartnerRecords => 'private',
            default => 'custom',
        };
    }

    public function render()
    {
        return view('livewire.profile.privacy-settings', [
            'domains' => ProfileAccessSettings::DOMAINS,
            'currentPreset' => $this->currentPreset,
            'profile' => app(ProfileContext::class)->profile(),
        ]);
    }
}

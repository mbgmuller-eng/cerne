<?php

namespace App\Livewire\Consultant;

use App\Enums\InsuranceType;
use App\Services\LeadAutomationService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Como este profissional quer tratar renovações: quais tipos de seguro geram lead de renovação e com quantos dias de
 * antecedência. A apólice que renova sozinha é uma marca à parte, no cadastro dela. Preferência pessoal de quem
 * trabalha a carteira, por isso fica no usuário (ver LeadAutomationService::renewalSettings).
 */
class LeadRenewalSettings extends Component
{
    /** @var list<string> */
    public array $types = [];

    public string $days = '';

    public function mount(LeadAutomationService $automation): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);

        $atual = $automation->renewalSettings(auth()->user());
        $this->types = $atual['types'];
        $this->days = (string) max(0, $atual['days']);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);

        $dados = $this->validate([
            'types' => ['array'],
            'types.*' => ['string', Rule::enum(InsuranceType::class)],
            'days' => ['required', 'integer', 'min:7', 'max:120'],
        ], attributes: ['days' => 'antecedência']);

        auth()->user()->update([
            'renewal_lead_types' => array_values(array_unique($dados['types'])),
            'renewal_lead_days' => (int) $dados['days'],
        ]);

        session()->flash('status', 'Preferências de renovação salvas.');
        $this->dispatch('renewal-settings-saved');
    }

    public function render()
    {
        return view('livewire.consultant.lead-renewal-settings', ['insuranceTypes' => InsuranceType::options()]);
    }
}

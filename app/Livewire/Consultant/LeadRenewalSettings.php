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

    /**
     * O que "Salvar" muda: a rotina diária de renovação (`LeadAutomationService::createRenewalLeads`) passa a olhar os tipos marcados e a janela
     * de dias. Lead que já foi criado não muda nem se repete (a chave `automation_key` é única).
     *
     * @return list<string>
     */
    private function consequence(): array
    {
        $dias = (int) $this->days;

        if ($dias < 7 || $dias > 120) {
            return [];
        }

        if ($this->types === []) {
            return ['Nenhum tipo marcado: nenhuma apólice vira contato de renovação. Todas continuam em "Datas importantes".'];
        }

        $n = count($this->types);

        return [
            'A partir da próxima rodada diária, cada apólice dos seus clientes '.($n === 1 ? 'do tipo marcado' : 'dos '.$n.' tipos marcados').' que vence nos próximos '.$dias.' dias vira um contato de renovação no funil.',
            'Contatos que já foram criados não mudam nem se repetem. Apólice que renova sozinha nunca gera contato.',
        ];
    }

    public function render()
    {
        return view('livewire.consultant.lead-renewal-settings', ['insuranceTypes' => InsuranceType::options(), 'consequence' => $this->consequence()]);
    }
}

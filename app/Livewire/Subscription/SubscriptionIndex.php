<?php

namespace App\Livewire\Subscription;

use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Rules\CpfCnpj;
use App\Services\AsaasClient;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Assinar um dos 3 pacotes — direto (usuário final) ou profissional
 * (consultor/corretor, cobre os clientes vinculados e ativos dele, ver
 * EntitlementService). $kind() decide sozinho qual dos dois com base em
 * quem está logado — não é uma escolha que aparece na tela.
 *
 * Quem já tem assinatura ATIVA/em teste não vê o formulário, só o status;
 * quem tem PastDue/Cancelled pode assinar de novo (ex.: trocar de pacote).
 */
#[Layout('components.layouts.app')]
class SubscriptionIndex extends Component
{
    public string $cpfCnpj = '';

    public function mount(): void
    {
        $this->cpfCnpj = Auth::user()->cpf_cnpj ?? '';
    }

    public function rules(): array
    {
        return [
            'cpfCnpj' => ['required', 'string', new CpfCnpj],
        ];
    }

    public function assinar(string $bundle, AsaasClient $asaas)
    {
        $data = $this->validate();
        $usuario = Auth::user();
        $usuario->update(['cpf_cnpj' => $data['cpfCnpj']]);

        $pacote = SubscriptionBundle::from($bundle);
        $customerId = $asaas->findOrCreateCustomer($usuario->fresh());
        $resultado = $asaas->createSubscription($customerId, $pacote, "Cerne — {$pacote->label()}");

        Subscription::create([
            'user_id' => $usuario->id,
            'kind' => $this->kind(),
            'bundle' => $pacote,
            // Aguardando a primeira cobrança confirmar — sem
            // current_period_ends_at, isCurrent() já nega acesso sozinho
            // até o webhook de pagamento chegar.
            'status' => SubscriptionStatus::PastDue,
            'asaas_subscription_id' => $resultado['id'],
            'started_at' => now(),
        ]);

        if ($resultado['invoiceUrl'] !== null) {
            return $this->redirect($resultado['invoiceUrl']);
        }

        session()->flash('status', 'Assinatura criada — acompanhe o pagamento pelo e-mail da Asaas.');
    }

    public function render()
    {
        $assinaturaAtual = Subscription::query()
            ->where('user_id', Auth::id())
            ->ofKind($this->kind())
            ->latest('created_at')
            ->first();

        return view('livewire.subscription.subscription-index', [
            'bundles' => SubscriptionBundle::cases(),
            'assinaturaAtual' => $assinaturaAtual,
            'temAcessoAtivo' => $assinaturaAtual?->isCurrent() ?? false,
            'souProfissional' => $this->kind() === SubscriptionKind::Professional,
        ]);
    }

    private function kind(): SubscriptionKind
    {
        return Auth::user()->isLinkedProfessional() ? SubscriptionKind::Professional : SubscriptionKind::Direct;
    }
}

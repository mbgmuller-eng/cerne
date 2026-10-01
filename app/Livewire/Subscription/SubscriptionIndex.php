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

        session()->flash('status', 'Assinatura criada. Acompanhe o pagamento pelo e-mail da Asaas.');
    }

    /**
     * Cancelamento é imediato — sem prorata, sem manter acesso até o fim
     * do período já pago. `isCurrent()` já nega acesso assim que o status
     * vira Cancelled; manter acesso "até o fim do mês" exigiria rastrear
     * isso à parte (fora de escopo por ora, ver conversa com o Marcelo).
     */
    public function cancelar(AsaasClient $asaas): void
    {
        $assinatura = Subscription::query()
            ->where('user_id', Auth::id())
            ->ofKind($this->kind())
            ->latest('created_at')
            ->first();

        if ($assinatura === null || ! $assinatura->isCurrent()) {
            return;
        }

        $asaas->cancelSubscription($assinatura->asaas_subscription_id);

        $assinatura->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        session()->flash('status', 'Assinatura cancelada. O acesso foi encerrado agora.');
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

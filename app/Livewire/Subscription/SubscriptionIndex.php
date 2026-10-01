<?php

namespace App\Livewire\Subscription;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Rules\CpfCnpj;
use App\Services\AsaasClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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

    public string $metodoPagamento = '';

    /** Só preenchido/validado pra quem assina como profissional — ver rules(). */
    public string $clientCap = '';

    public function mount(): void
    {
        $this->cpfCnpj = Auth::user()->cpf_cnpj ?? '';
    }

    public function rules(): array
    {
        $regras = [
            'cpfCnpj' => ['required', 'string', new CpfCnpj],
            'metodoPagamento' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
        ];

        if ($this->kind() === SubscriptionKind::Professional) {
            $regras['clientCap'] = ['required', Rule::in(array_keys(config('billing.client_tier_surcharge')))];
        }

        return $regras;
    }

    /**
     * 7 dias grátis pra qualquer pacote, cartão ou Pix — ver
     * AsaasClient::createSubscription(). Nenhum dos dois cobra sozinho
     * hoje: a pessoa clica a fatura quando o teste acabar (ou antes, se
     * quiser). Status nasce Trialing, não PastDue — isCurrent() já trata
     * os dois igual, liberando acesso, mas Trialing é o que de fato
     * aconteceu.
     */
    public function assinar(string $bundle, AsaasClient $asaas)
    {
        $data = $this->validate();
        $usuario = Auth::user();
        $usuario->update(['cpf_cnpj' => $data['cpfCnpj']]);

        $pacote = SubscriptionBundle::from($bundle);
        $metodo = PaymentMethod::from($data['metodoPagamento']);
        // Só profissional escolhe faixa — ver rules(), cliente Direct nunca
        // tem 'clientCap' no array validado.
        $teto = isset($data['clientCap']) ? (int) $data['clientCap'] : null;
        $customerId = $asaas->findOrCreateCustomer($usuario->fresh());
        $resultado = $asaas->createSubscription($customerId, $pacote, $metodo, "Cerne — {$pacote->label()}", $teto);

        Subscription::create([
            'user_id' => $usuario->id,
            'kind' => $this->kind(),
            'bundle' => $pacote,
            'client_cap' => $teto,
            'billing_type' => $metodo,
            'status' => SubscriptionStatus::Trialing,
            // Fim do teste grátis — mesmo campo que current_period_ends_at
            // sempre teve, só que agora nasce preenchido em vez de nulo.
            // O webhook de pagamento confirmado sobrescreve isso a cada
            // ciclo normalmente.
            'current_period_ends_at' => now()->addDays(7),
            'asaas_subscription_id' => $resultado['id'],
            'started_at' => now(),
        ]);

        if ($resultado['invoiceUrl'] !== null) {
            return $this->redirect($resultado['invoiceUrl']);
        }

        session()->flash('status', 'Assinatura criada: 7 dias grátis pra testar. Acompanhe o pagamento pelo e-mail da Asaas quando o teste acabar.');
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

        // Assinatura de cortesia (concedida direto no banco, sem passar
        // pela Asaas — ver "fora de escopo fase 1" no plano) não tem
        // asaas_subscription_id: nada pra cancelar lá, só localmente.
        if ($assinatura->asaas_subscription_id !== null) {
            $asaas->cancelSubscription($assinatura->asaas_subscription_id);
        }

        $assinatura->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        session()->flash('status', 'Assinatura cancelada. O acesso foi encerrado agora.');
    }

    /**
     * Sobe de faixa — não existe endpoint de "mudar valor" na Asaas (só
     * criar e cancelar, ver AsaasClient), então cancela a assinatura atual
     * e cria uma nova, maior, com o MESMO pacote/forma de pagamento. Sem
     * os 7 dias de teste: quem já paga não ganha outro trial só por
     * precisar de mais clientes (ver AsaasClient::createSubscription(),
     * diasAteprimeiraCobranca = 0) — a nova nasce PastDue, cobra na hora,
     * mesma regra de sempre pra quem está em atraso.
     */
    public function aumentarFaixa(int $novoTeto, AsaasClient $asaas)
    {
        abort_unless($this->kind() === SubscriptionKind::Professional, 403);
        abort_unless(in_array($novoTeto, array_keys(config('billing.client_tier_surcharge')), true), 422);

        $atual = Subscription::query()
            ->where('user_id', Auth::id())
            ->ofKind(SubscriptionKind::Professional)
            ->latest('created_at')
            ->first();

        abort_if($atual === null || ! $atual->isCurrent() || $atual->client_cap === null, 404);

        if ($novoTeto <= $atual->client_cap) {
            session()->flash('status', 'Essa já é sua faixa atual ou uma faixa menor.');

            return;
        }

        $usuario = Auth::user();

        if ($atual->asaas_subscription_id !== null) {
            $asaas->cancelSubscription($atual->asaas_subscription_id);
        }

        $atual->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        $customerId = $asaas->findOrCreateCustomer($usuario->fresh());
        $resultado = $asaas->createSubscription(
            $customerId,
            $atual->bundle,
            $atual->billing_type,
            "Cerne — {$atual->bundle->label()}",
            $novoTeto,
            diasAteprimeiraCobranca: 0,
        );

        Subscription::create([
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => $atual->bundle,
            'client_cap' => $novoTeto,
            'billing_type' => $atual->billing_type,
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => now(),
            'asaas_subscription_id' => $resultado['id'],
            'started_at' => now(),
        ]);

        if ($resultado['invoiceUrl'] !== null) {
            return $this->redirect($resultado['invoiceUrl']);
        }

        session()->flash('status', 'Faixa aumentada. A cobrança já foi gerada — acompanhe pelo e-mail da Asaas.');
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
            'faixasClientes' => config('billing.client_tier_surcharge'),
        ]);
    }

    private function kind(): SubscriptionKind
    {
        return Auth::user()->isLinkedProfessional() ? SubscriptionKind::Professional : SubscriptionKind::Direct;
    }
}

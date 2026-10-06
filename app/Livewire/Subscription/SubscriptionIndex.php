<?php

namespace App\Livewire\Subscription;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Rules\CpfCnpj;
use App\Services\AsaasClient;
use App\Services\PixAutomaticBillingService;
use App\Support\ProfessionalPricing;
use DomainException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    /**
     * Plano que a pessoa escolheu na página pública antes de criar a conta
     * (ver CheckoutController). Preenche o resumo do pedido; vazio quando
     * ela chegou aqui por outro caminho.
     */
    public string $pacoteEscolhido = '';

    public bool $temIntencao = false;

    public bool $trocandoPlano = false;

    /**
     * QR Code da autorização do Pix Automático, só enquanto a pessoa está
     * nesta tela esperando pagar (a Asaas só devolve o QR junto com a criação
     * ou enquanto a autorização está CREATED).
     *
     * @var array{payload: ?string, qrImage: ?string, expiresAt: ?string}|null
     */
    public ?array $pixQr = null;

    public function mount(): void
    {
        $this->cpfCnpj = Auth::user()->cpf_cnpj ?? '';
        $this->carregarIntencaoDoCheckout();
    }

    public function trocarPlano(): void
    {
        $this->trocandoPlano = true;
    }

    public function voltarAoPedido(): void
    {
        $this->trocandoPlano = false;
    }

    /** Valida tudo que veio da sessão: ela só guarda a intenção, nunca é confiável sozinha. */
    private function carregarIntencaoDoCheckout(): void
    {
        $intencao = session('checkout');

        if (! is_array($intencao)) {
            return;
        }

        $metodo = PaymentMethod::tryFrom((string) ($intencao['metodo'] ?? ''));
        $metodo = in_array($metodo, PaymentMethod::available(), true) ? $metodo : null;

        if ($this->kind() === SubscriptionKind::Professional && ($intencao['tipo'] ?? null) === 'profissional') {
            $teto = (int) ($intencao['clientes'] ?? 0);

            if (ProfessionalPricing::isValidCap($teto)) {
                $this->clientCap = (string) $teto;
                $this->temIntencao = true;
            }
        } elseif ($this->kind() === SubscriptionKind::Direct && ($intencao['tipo'] ?? null) === 'usuario') {
            $pacote = SubscriptionBundle::tryFrom((string) ($intencao['pacote'] ?? ''));

            if ($pacote !== null) {
                $this->pacoteEscolhido = $pacote->value;
                $this->temIntencao = true;
            }
        }

        if ($this->temIntencao && $metodo !== null) {
            $this->metodoPagamento = $metodo->value;
        }
    }

    public function rules(): array
    {
        $regras = [
            'cpfCnpj' => ['required', 'string', new CpfCnpj],
            'metodoPagamento' => ['required', Rule::in(array_column(PaymentMethod::available(), 'value'))],
        ];

        if ($this->kind() === SubscriptionKind::Professional) {
            $regras['clientCap'] = ['required', Rule::in(ProfessionalPricing::validCaps())];
        }

        return $regras;
    }

    /**
     * 7 dias grátis pra qualquer pacote e forma de pagamento. NADA vai para a
     * Asaas aqui: a Asaas cria a primeira cobrança no instante em que a
     * assinatura nasce, então o cadastro só registra a escolha e o teste corre
     * localmente. A assinatura é criada lá perto do fim do teste, com
     * vencimento no último dia dele (SubscriptionBillingService), e no Pix
     * Automático a pessoa gera o QR de autorização por esta tela
     * (ativarDebitoAutomatico()). Status nasce Trialing e o acesso expira sozinho
     * se ninguém pagar (Subscription::isCurrent()).
     */
    public function assinar(string $bundle)
    {
        $data = $this->validate();
        $usuario = Auth::user();
        $usuario->update(['cpf_cnpj' => $data['cpfCnpj']]);

        // Profissional não escolhe pacote: os clientes dele recebem tudo e o
        // preço depende só do teto de clientes (ver config/billing.php).
        $pacote = $this->kind() === SubscriptionKind::Professional
            ? SubscriptionBundle::Completo
            : SubscriptionBundle::from($bundle);
        $metodo = PaymentMethod::from($data['metodoPagamento']);
        // Só profissional escolhe teto — ver rules(), cliente Direct nunca
        // tem 'clientCap' no array validado.
        $teto = isset($data['clientCap']) ? (int) $data['clientCap'] : null;
        $fimDoTeste = now()->addDays(7);

        Subscription::create([
            'user_id' => $usuario->id,
            'kind' => $this->kind(),
            'bundle' => $pacote,
            'client_cap' => $teto,
            'billing_type' => $metodo,
            'status' => SubscriptionStatus::Trialing,
            // Último dia do teste grátis: é o vencimento da primeira cobrança.
            // O webhook de pagamento confirmado sobrescreve isso a cada ciclo.
            'current_period_ends_at' => $fimDoTeste,
            'started_at' => now(),
        ]);

        session()->forget('checkout');

        session()->flash('status', $metodo === PaymentMethod::PixAutomatic
            ? 'Assinatura criada: 7 dias grátis para testar. Perto do fim do teste, ative o débito automático por esta tela.'
            : 'Assinatura criada: 7 dias grátis para testar. A cobrança é gerada perto do fim do teste e vence em '.$fimDoTeste->format('d/m/Y').'; o link de pagamento chega por e-mail. Não há nada a pagar agora.');
    }
    /**
     * Cancelamento é imediato — sem prorata, sem manter acesso até o fim
     * do período já pago. `isCurrent()` já nega acesso assim que o status
     * vira Cancelled; manter acesso "até o fim do mês" exigiria rastrear
     * isso à parte (fora de escopo por ora, ver conversa com o Marcelo).
     */
    public function cancelar(AsaasClient $asaas, PixAutomaticBillingService $pix): void
    {
        $assinatura = $this->assinaturaAtual();

        if ($assinatura === null || ! $assinatura->isCurrent()) {
            return;
        }

        // Assinatura de cortesia (concedida direto no banco, sem passar
        // pela Asaas — ver "fora de escopo fase 1" no plano) não tem
        // asaas_subscription_id: nada pra cancelar lá, só localmente.
        if ($assinatura->asaas_subscription_id !== null) {
            $asaas->cancelSubscription($assinatura->asaas_subscription_id);
        }

        // Sem isso o banco continuaria com a autorização de débito de pé.
        $pix->cancelAuthorization($assinatura);

        $assinatura->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $this->pixQr = null;

        session()->flash('status', 'Assinatura cancelada. O acesso foi encerrado agora.');
    }

    /**
     * Gera o QR Code do Pix Automático: o primeiro mês é pago por ele e o
     * pagamento é também o pedido de autorização ao banco. Pode ser refeito
     * (QR expirado, autorização recusada ou cancelada): o serviço cancela a
     * anterior não concluída.
     */
    public function ativarDebitoAutomatico(PixAutomaticBillingService $pix): void
    {
        $assinatura = $this->assinaturaAtual();

        abort_if($assinatura === null, 404);

        try {
            $autorizacao = $pix->startAuthorization($assinatura);
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        } catch (RequestException $e) {
            Log::warning('Pix Automático: a Asaas recusou a criação da autorização', ['erro' => $e->getMessage(), 'user_id' => Auth::id()]);
            session()->flash('status', 'Não foi possível gerar o QR Code agora. Tente novamente em instantes.');

            return;
        }

        $this->pixQr = [
            'payload' => $autorizacao['payload'],
            'qrImage' => $autorizacao['qrImage'],
            'expiresAt' => $autorizacao['expiresAt'],
        ];
    }

    /** Chamado pelo polling da tela enquanto o QR está aberto: some quando a autorização ativa ou falha. */
    public function atualizarAtivacao(): void
    {
        $assinatura = $this->assinaturaAtual();

        if ($assinatura === null) {
            $this->pixQr = null;

            return;
        }

        if ($assinatura->hasActivePixAuthorization()) {
            $this->pixQr = null;
            session()->flash('status', 'Débito automático ativado. As próximas cobranças saem sozinhas.');
        } elseif (in_array($assinatura->pix_authorization_status, ['REFUSED', 'CANCELLED', 'EXPIRED'], true)) {
            $this->pixQr = null;
        }
    }

    /**
     * Compra mais clientes: sobe o teto da MESMA assinatura e muda o valor
     * dela na Asaas (ver AsaasClient::updateSubscriptionValue()). O novo
     * valor vale a partir da próxima cobrança, sem cobrar de novo o ciclo
     * que já foi pago nem recomeçar teste grátis. O acesso aos clientes
     * novos é imediato.
     */
    public function aumentarFaixa(int $novoTeto, AsaasClient $asaas)
    {
        abort_unless($this->kind() === SubscriptionKind::Professional, 403);
        abort_unless(ProfessionalPricing::isValidCap($novoTeto), 422);

        $atual = Subscription::query()
            ->where('user_id', Auth::id())
            ->ofKind(SubscriptionKind::Professional)
            ->latest('created_at')
            ->first();

        abort_if($atual === null || ! $atual->isCurrent() || $atual->client_cap === null, 404);

        if ($novoTeto <= $atual->client_cap) {
            session()->flash('status', 'Esse já é o seu limite atual ou um limite menor.');

            return;
        }

        if ($atual->asaas_subscription_id !== null) {
            $asaas->updateSubscriptionValue($atual->asaas_subscription_id, ProfessionalPricing::priceFor($novoTeto));
        }

        $atual->update(['client_cap' => $novoTeto]);

        session()->flash('status', 'Limite aumentado para '.$novoTeto.' clientes. O novo valor vale a partir da próxima cobrança.');
    }
    public function render()
    {
        $assinaturaAtual = $this->assinaturaAtual();

        return view('livewire.subscription.subscription-index', [
            'bundles' => SubscriptionBundle::cases(),
            'assinaturaAtual' => $assinaturaAtual,
            'temAcessoAtivo' => $assinaturaAtual?->isCurrent() ?? false,
            'souProfissional' => $this->kind() === SubscriptionKind::Professional,
            'tetosClientes' => ProfessionalPricing::validCaps(),
            'resumoDoPedido' => $this->temIntencao && ! $this->trocandoPlano && ! ($assinaturaAtual?->isCurrent() ?? false),
            'pacoteDoPedido' => $this->pacoteEscolhido !== '' ? SubscriptionBundle::tryFrom($this->pacoteEscolhido) : null,
            'primeiraCobranca' => now()->addDays(7),
        ]);
    }

    private function assinaturaAtual(): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', Auth::id())
            ->ofKind($this->kind())
            ->latest('created_at')
            ->first();
    }

    private function kind(): SubscriptionKind
    {
        return Auth::user()->isLinkedProfessional() ? SubscriptionKind::Professional : SubscriptionKind::Direct;
    }
}

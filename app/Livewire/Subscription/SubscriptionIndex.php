<?php

namespace App\Livewire\Subscription;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Models\BillingDetail;
use App\Models\Subscription;
use App\Rules\CpfCnpj;
use App\Services\AsaasClient;
use App\Services\InvoiceSettingsService;
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
 * Assinatura em duas etapas, de propósito separadas:
 *
 * 1. Começar o teste: escolhe o plano e ganha 7 dias grátis. Sem CPF e sem
 *    forma de pagamento: nada vai para a Asaas.
 * 2. Pagar: a pessoa (a qualquer momento do teste, ou quando ele acaba e o
 *    app trava) escolhe Pix ou cartão e vai para a fatura. Só aqui a
 *    assinatura nasce na Asaas, que cria a primeira cobrança no mesmo instante.
 *
 * Direto (usuário final) ou profissional (consultor/corretor, cobre os
 * clientes vinculados e ativos dele, ver EntitlementService): $kind() decide
 * sozinho com base em quem está logado.
 */
#[Layout('components.layouts.app')]
class SubscriptionIndex extends Component
{
    public string $cpfCnpj = '';

    public string $metodoPagamento = '';

    // Dados fiscais de quem paga (nota fiscal de serviço). CPF/CNPJ fica em $cpfCnpj.
    public string $fiscalNome = '';

    public string $fiscalNascimento = '';

    public string $cep = '';

    public string $rua = '';

    public string $numero = '';

    public string $complemento = '';

    public string $bairro = '';

    public string $cidade = '';

    public string $uf = '';

    /** Só preenchido/validado pra quem assina como profissional. */
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
        $this->preencherDadosFiscais();
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
    }

    /**
     * Etapa 1: começa o teste grátis (ou, se a pessoa já tem um teste que ainda
     * não virou pagamento, só troca o plano dele).
     *
     * Nada vai para a Asaas. Quem já teve uma assinatura deste tipo não ganha
     * um segundo teste: a nova nasce com o teste já vencido e a pessoa vai
     * direto ao pagamento. O acesso termina sozinho no fim do período
     * (Subscription::isCurrent()).
     */
    public function assinar(string $bundle, AsaasClient $asaas)
    {
        $usuario = Auth::user();
        $kind = $this->kind();

        // Só o profissional escolhe o limite de clientes; o cliente direto não tem o que validar.
        $dados = $kind === SubscriptionKind::Professional
            ? $this->validate(['clientCap' => ['required', Rule::in(ProfessionalPricing::validCaps())]])
            : [];

        // Profissional não escolhe pacote: os clientes dele recebem tudo e o
        // preço depende só do teto de clientes (ver config/billing.php).
        $pacote = $kind === SubscriptionKind::Professional
            ? SubscriptionBundle::Completo
            : SubscriptionBundle::from($bundle);
        $teto = isset($dados['clientCap']) ? (int) $dados['clientCap'] : null;

        $atual = $this->assinaturaAtual();

        // Quem já paga não usa este caminho: troca de limite é aumentarFaixa().
        abort_if($atual !== null && $atual->status === SubscriptionStatus::Active, 422);

        if ($atual !== null && $atual->status !== SubscriptionStatus::Cancelled) {
            $this->trocarDePlano($atual, $pacote, $teto, $asaas);
            $this->trocandoPlano = false;
            session()->flash('status', 'Plano alterado.');

            return;
        }

        $primeiroTeste = ! Subscription::query()->where('user_id', $usuario->id)->ofKind($kind)->exists();
        $fimDoTeste = $primeiroTeste ? now()->addDays(7) : now();

        Subscription::create([
            'user_id' => $usuario->id,
            'kind' => $kind,
            'bundle' => $pacote,
            'client_cap' => $teto,
            'status' => SubscriptionStatus::Trialing,
            // Dia em que o acesso trava e a primeira cobrança vence. O webhook
            // de pagamento confirmado sobrescreve isso a cada ciclo.
            'current_period_ends_at' => $fimDoTeste,
            'started_at' => now(),
        ]);

        session()->forget('checkout');
        $this->trocandoPlano = false;

        session()->flash('status', $primeiroTeste
            ? 'Teste grátis iniciado: você tem acesso até '.$fimDoTeste->copy()->subDay()->format('d/m/Y').'. Avisamos 3 dias antes de acabar, e você escolhe como pagar quando quiser.'
            : 'Plano escolhido. Como o seu teste grátis já foi usado, escolha abaixo como pagar para liberar o acesso.');
    }

    /** Troca o plano de um teste/assinatura ainda não paga. A fatura antiga, se existir, é cancelada na Asaas. */
    private function trocarDePlano(Subscription $assinatura, SubscriptionBundle $pacote, ?int $teto, AsaasClient $asaas): void
    {
        if ($assinatura->asaas_subscription_id !== null) {
            $this->cancelarNaAsaas($asaas, $assinatura->asaas_subscription_id);
        }

        $assinatura->update([
            'bundle' => $pacote,
            'client_cap' => $teto,
            'billing_type' => null,
            'asaas_subscription_id' => null,
        ]);
    }

    /**
     * Etapa 2: a pessoa escolheu Pix ou cartão. Só agora a assinatura nasce na
     * Asaas, com vencimento no último dia do teste (ou hoje, se ele já acabou),
     * e a pessoa é levada à fatura para pagar. O acesso volta quando a Asaas
     * confirmar o pagamento (webhook).
     */
    public function iniciarPagamento(AsaasClient $asaas, PixAutomaticBillingService $pix)
    {
        $dados = $this->validate($this->regrasDoPagamento(), attributes: [
            'fiscalNome' => 'nome completo',
            'cpfCnpj' => 'CPF ou CNPJ',
            'fiscalNascimento' => 'data de nascimento',
            'cep' => 'CEP',
            'rua' => 'rua',
            'numero' => 'número',
            'complemento' => 'complemento',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
            'metodoPagamento' => 'forma de pagamento',
        ]);

        $assinatura = $this->assinaturaAtual();

        abort_if($assinatura === null || in_array($assinatura->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Active], true), 404);

        $usuario = Auth::user();
        $metodo = PaymentMethod::from($dados['metodoPagamento']);
        $fiscal = $this->salvarDadosFiscais($usuario, $dados);

        // O cliente da Asaas leva o nome, o documento e o endereço: é dele que sai o
        // tomador da nota fiscal. Uma recusa (CEP ou CPF inválido) volta como erro na tela.
        try {
            $customerId = $asaas->findOrCreateCustomer($usuario->fresh(), $fiscal);
        } catch (RequestException $e) {
            Log::warning('Asaas: recusou os dados do cliente', ['user_id' => $usuario->id, 'erro' => $e->getMessage()]);
            $this->addError('cpfCnpj', 'O serviço de pagamento recusou os dados informados: '.($e->response->json('errors.0.description') ?? 'confira o CPF/CNPJ e o CEP.'));

            return;
        }

        // Pix Automático tem fluxo próprio: autorização por QR Code, sem fatura.
        if ($metodo === PaymentMethod::PixAutomatic) {
            if ($assinatura->asaas_subscription_id !== null) {
                $this->cancelarNaAsaas($asaas, $assinatura->asaas_subscription_id);
            }
            $assinatura->update(['billing_type' => $metodo, 'asaas_subscription_id' => null]);

            return $this->ativarDebitoAutomatico($pix);
        }

        try {
            $id = $this->garantirAssinaturaNaAsaas($assinatura, $metodo, $customerId, $asaas);
        } catch (AsaasBillingTypeMismatch $e) {
            // Já foi desfeita na Asaas: nenhuma cobrança fica de pé.
            Log::error('Asaas: forma de pagamento diferente da pedida, assinatura desfeita', [
                'user_id' => Auth::id(), 'pedido' => $e->pedido, 'recebido' => $e->recebido,
            ]);
            $this->addError('metodoPagamento', 'Não foi possível criar a cobrança com essa forma de pagamento agora. Nenhuma cobrança foi gerada. Tente novamente em instantes.');

            return;
        } catch (RequestException $e) {
            Log::warning('Asaas: falha ao criar a assinatura', ['user_id' => Auth::id(), 'erro' => $e->getMessage()]);
            $this->addError('metodoPagamento', 'Não foi possível falar com o serviço de pagamento agora. Tente novamente em instantes.');

            return;
        }

        $this->redirectParaFatura($asaas, $id);
    }

    /** @return array<string, mixed> */
    private function regrasDoPagamento(): array
    {
        $documento = preg_replace('/\D/', '', $this->cpfCnpj);
        $pessoaFisica = strlen($documento) !== 14;

        return [
            'fiscalNome' => ['required', 'string', 'max:150', $pessoaFisica ? 'regex:/\S+\s+\S+/' : 'min:3'],
            'cpfCnpj' => ['required', 'string', new CpfCnpj],
            // A Asaas não usa a data de nascimento, mas guardamos do nosso lado para a nota (só de pessoa física).
            'fiscalNascimento' => [Rule::requiredIf($pessoaFisica), 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            'rua' => ['required', 'string', 'max:120'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:60'],
            'bairro' => ['required', 'string', 'max:80'],
            'cidade' => ['required', 'string', 'max:80'],
            'uf' => ['required', Rule::in(BillingDetail::STATES)],
            'metodoPagamento' => ['required', Rule::in(array_column(PaymentMethod::available(), 'value'))],
        ];
    }

    /**
     * Guarda os dados fiscais (do lado do Cerne, não só na Asaas) e mantém o CPF/CNPJ
     * da conta em sincronia, que é de onde a Asaas o recebia até aqui.
     *
     * @param  array<string, mixed>  $dados
     */
    private function salvarDadosFiscais($usuario, array $dados): BillingDetail
    {
        $documento = preg_replace('/\D/', '', $dados['cpfCnpj']);

        $usuario->update(['cpf_cnpj' => $documento]);

        return BillingDetail::updateOrCreate(['user_id' => $usuario->id], [
            'full_name' => trim($dados['fiscalNome']),
            'document' => $documento,
            'birth_date' => ($dados['fiscalNascimento'] ?? '') !== '' ? $dados['fiscalNascimento'] : null,
            'postal_code' => preg_replace('/\D/', '', $dados['cep']),
            'street' => trim($dados['rua']),
            'number' => trim($dados['numero']),
            'complement' => ($dados['complemento'] ?? '') !== '' ? trim($dados['complemento']) : null,
            'neighborhood' => trim($dados['bairro']),
            'city' => trim($dados['cidade']),
            'state' => $dados['uf'],
        ]);
    }

    /** Pré-preenche com o que já temos: os dados fiscais salvos, ou o que veio do cadastro. */
    private function preencherDadosFiscais(): void
    {
        $usuario = Auth::user();
        $fiscal = $usuario->billingDetail;

        $this->cpfCnpj = $fiscal?->document ?? ($usuario->cpf_cnpj ?? '');
        $this->fiscalNome = $fiscal?->full_name ?? $usuario->name;
        $this->fiscalNascimento = ($fiscal?->birth_date ?? $usuario->birthdate)?->toDateString() ?? '';
        $this->cep = $fiscal?->postal_code ?? '';
        $this->rua = $fiscal?->street ?? '';
        $this->numero = $fiscal?->number ?? '';
        $this->complemento = $fiscal?->complement ?? '';
        $this->bairro = $fiscal?->neighborhood ?? '';
        $this->cidade = $fiscal?->city ?? '';
        $this->uf = $fiscal?->state ?? '';
    }
    /** Reabre a fatura de quem já escolheu a forma de pagamento e saiu da página sem pagar. */
    public function abrirFatura(AsaasClient $asaas)
    {
        $assinatura = $this->assinaturaAtual();

        abort_if($assinatura === null || $assinatura->asaas_subscription_id === null, 404);

        $this->redirectParaFatura($asaas, $assinatura->asaas_subscription_id);
    }

    /** Solta a fatura já gerada para a pessoa escolher outra forma de pagamento. */
    public function trocarFormaDePagamento(AsaasClient $asaas, PixAutomaticBillingService $pix): void
    {
        $assinatura = $this->assinaturaAtual();

        abort_if($assinatura === null || $assinatura->status === SubscriptionStatus::Active, 404);

        if ($assinatura->asaas_subscription_id !== null) {
            $this->cancelarNaAsaas($asaas, $assinatura->asaas_subscription_id);
        }

        // Autorização de Pix Automático ainda não concluída: cancela, para não sobrar solta na Asaas.
        $pix->cancelAuthorization($assinatura);

        $assinatura->update([
            'billing_type' => null,
            'asaas_subscription_id' => null,
            'asaas_pix_authorization_id' => null,
            'pix_authorization_status' => null,
        ]);
        $this->reset('metodoPagamento', 'pixQr');
    }

    /** Chamado pelo polling da tela enquanto espera a Asaas confirmar o pagamento. */
    public function atualizarPagamento(): void
    {
        $assinatura = $this->assinaturaAtual();

        if ($assinatura !== null && $assinatura->status === SubscriptionStatus::Active) {
            session()->flash('status', 'Pagamento confirmado. Seu acesso está liberado.');
        }
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

        if ($assinatura === null || $assinatura->status === SubscriptionStatus::Cancelled) {
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

        $atual = $this->assinaturaAtual();

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
        $vigente = $assinaturaAtual !== null && $assinaturaAtual->status !== SubscriptionStatus::Cancelled;
        $precisaPagar = $vigente && $assinaturaAtual->status !== SubscriptionStatus::Active;

        return view('livewire.subscription.subscription-index', [
            'bundles' => SubscriptionBundle::cases(),
            'assinaturaAtual' => $assinaturaAtual,
            'temAcessoAtivo' => $assinaturaAtual?->isCurrent() ?? false,
            'souProfissional' => $this->kind() === SubscriptionKind::Professional,
            'tetosClientes' => ProfessionalPricing::validCaps(),
            'metodos' => PaymentMethod::available(),
            // Mostra o pagamento enquanto a assinatura ainda não está paga; o
            // seletor de plano só aparece sem assinatura, depois de cancelar
            // ou quando a pessoa pediu para trocar.
            'precisaPagar' => $precisaPagar,
            'mostrarPlanos' => ! $vigente || ($this->trocandoPlano && $precisaPagar),
            'aguardandoPagamento' => $precisaPagar && $assinaturaAtual->asaas_subscription_id !== null,
            'resumoDoPedido' => $this->temIntencao && ! $vigente,
            'pacoteDoPedido' => $this->pacoteEscolhido !== '' ? SubscriptionBundle::tryFrom($this->pacoteEscolhido) : null,
            'fimDoTeste' => now()->addDays(7),
        ]);
    }

    /**
     * Cria (ou reaproveita) a assinatura na Asaas para a forma escolhida e
     * grava o id. Idempotente: busca primeiro pela referência (o id do Cerne),
     * então um duplo clique ou uma queda entre a criação e a gravação nunca
     * gera duas assinaturas.
     */
    private function garantirAssinaturaNaAsaas(Subscription $assinatura, PaymentMethod $metodo, string $customerId, AsaasClient $asaas): string
    {
        if ($assinatura->asaas_subscription_id !== null) {
            if ($assinatura->billing_type === $metodo) {
                return $assinatura->asaas_subscription_id;
            }

            // Mudou de ideia sobre a forma de pagamento: solta a fatura antiga.
            $this->cancelarNaAsaas($asaas, $assinatura->asaas_subscription_id);
            $assinatura->update(['asaas_subscription_id' => null]);
        }

        $id = $asaas->findSubscriptionIdByReference($assinatura->id);

        if ($id === null) {
            // Vencimento no último dia do teste (a pessoa pode pagar antes sem perder
            // os dias que sobram) ou hoje, se o teste já acabou.
            $vencimento = $assinatura->current_period_ends_at->isFuture() ? $assinatura->current_period_ends_at : today();

            $id = $asaas->createSubscription(
                $customerId,
                $assinatura->bundle,
                $metodo,
                "Cerne — {$assinatura->bundle->label()}",
                $assinatura->client_cap,
                $vencimento->toDateString(),
                $assinatura->id,
            )['id'];
        }

        $assinatura->update(['billing_type' => $metodo, 'asaas_subscription_id' => $id]);

        // Nota fiscal automática na confirmação de cada pagamento (se ligada). Falhar aqui nunca
        // trava o pagamento: a tarefa diária tenta de novo.
        app(InvoiceSettingsService::class)->configure($assinatura->fresh());

        return $id;
    }

    private function redirectParaFatura(AsaasClient $asaas, string $asaasSubscriptionId)
    {
        $url = $asaas->currentInvoiceUrl($asaasSubscriptionId);

        if ($url !== null) {
            return $this->redirect($url);
        }

        session()->flash('status', 'Cobrança gerada. O link de pagamento chega por e-mail em instantes, ou volte aqui e toque em "Abrir fatura".');

        return null;
    }

    private function cancelarNaAsaas(AsaasClient $asaas, string $asaasSubscriptionId): void
    {
        try {
            $asaas->cancelSubscription($asaasSubscriptionId);
        } catch (\Throwable $e) {
            Log::warning('Asaas: não conseguiu cancelar a assinatura anterior', ['subscription_id' => $asaasSubscriptionId, 'erro' => $e->getMessage()]);
        }
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

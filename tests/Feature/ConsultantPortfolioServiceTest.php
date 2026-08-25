<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileAccessSettings;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ConsultantPortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ConsultantPortfolioService agrega dado através de VÁRIOS perfis
 * deliberadamente (withoutProfileScope). É exatamente o tipo de código que
 * pode vazar dado de um perfil não vinculado ao consultor se o whereIn de
 * profile_id estiver errado — por isso testa tanto o cálculo quanto o
 * isolamento (CLAUDE.md, regra 1).
 */
class ConsultantPortfolioServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_soma_patrimonio_apenas_dos_clientes_com_vinculo_ativo(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$perfilA] = $this->criarClienteVinculado($consultor, saldoConta: '10000.00');
        [$perfilB] = $this->criarClienteVinculado($consultor, saldoConta: '5000.00');

        // Vínculo pendente: ainda não é cliente de fato, não deve entrar na soma.
        $pendente = User::factory()->create();
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $pendente->id,
        ]);
        $perfilPendente = FinancialProfile::factory()->create(['owner_user_id' => $pendente->id]);
        $membroPendente = ProfileMember::factory()->create(['profile_id' => $perfilPendente->id]);
        BankAccount::factory()->for($perfilPendente, 'profile')->for($membroPendente, 'member')
            ->create(['current_balance' => '99999.00']);

        // Perfil de um cliente de OUTRO consultor: nunca pode entrar na soma.
        $outroConsultor = User::factory()->consultant()->create();
        $this->criarClienteVinculado($outroConsultor, saldoConta: '77777.00');

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertSame(2, $dados['clientes']['ativos']);
        self::assertSame(3, $dados['clientes']['total']);
        self::assertSame('15000.00', $dados['patrimonio']['contas']);
        self::assertSame('15000.00', $dados['patrimonio']['liquido']);

        // O vínculo pendente aparece na tabela (é o pipeline do consultor),
        // mas sem nenhum dado financeiro — a política só autoriza acesso
        // ao perfil de um vínculo ATIVO.
        $linhaPendente = collect($dados['por_cliente'])->firstWhere('email', $pendente->email);
        self::assertNotNull($linhaPendente);
        self::assertNull($linhaPendente['profile_id']);
        self::assertNull($linhaPendente['patrimonio']);
        self::assertNull($linhaPendente['premio_mensal']);
    }

    public function test_conta_clientes_com_e_sem_seguro_de_vida(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$comSeguro] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->life()->create(['profile_id' => $comSeguro->id]);

        // Apólice de vida INATIVA não conta como cobertura vigente.
        [$comSeguroInativo] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->life()->inactive()->create(['profile_id' => $comSeguroInativo->id]);

        // Seguro de outro tipo não é seguro de vida.
        [$semSeguroDeVida] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->create(['profile_id' => $semSeguroDeVida->id]);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertSame(3, $dados['clientes']['ativos']);
        self::assertSame(1, $dados['seguro_vida']['com']);
        self::assertSame(2, $dados['seguro_vida']['sem']);

        $porNome = collect($dados['por_cliente'])->keyBy('profile_id');
        self::assertTrue($porNome[$comSeguro->id]['seguro_vida']);
        self::assertFalse($porNome[$comSeguroInativo->id]['seguro_vida']);
        self::assertFalse($porNome[$semSeguroDeVida->id]['seguro_vida']);
    }

    public function test_sem_clientes_vinculados_devolve_zeros_em_vez_de_erro(): void
    {
        $consultor = User::factory()->consultant()->create();

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertSame(0, $dados['clientes']['ativos']);
        self::assertSame('0.00', $dados['patrimonio']['liquido']);
        self::assertSame(['com' => 0, 'sem' => 0], $dados['seguro_vida']);
        self::assertSame('0.00', $dados['premios_mes']);
        self::assertSame(0, $dados['multiproduto']);
        self::assertSame([], $dados['por_cliente']);
    }

    public function test_soma_premio_mensal_e_conta_clientes_multiproduto(): void
    {
        $consultor = User::factory()->consultant()->create();

        // Duas apólices ativas → conta como multiproduto.
        [$multiproduto] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->life()->create(['profile_id' => $multiproduto->id, 'monthly_premium' => '100.00']);
        InsurancePolicy::factory()->create(['profile_id' => $multiproduto->id, 'monthly_premium' => '50.00']);

        // Uma apólice só → não conta como multiproduto.
        [$umProduto] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->create(['profile_id' => $umProduto->id, 'monthly_premium' => '30.00']);

        // Apólice inativa não soma no prêmio nem conta pra multiproduto.
        [$comInativa] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->create(['profile_id' => $comInativa->id, 'monthly_premium' => '40.00']);
        InsurancePolicy::factory()->inactive()->create(['profile_id' => $comInativa->id, 'monthly_premium' => '999.00']);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertSame('220.00', $dados['premios_mes']);
        self::assertSame(1, $dados['multiproduto']);

        $porPerfil = collect($dados['por_cliente'])->keyBy('profile_id');
        self::assertEqualsCanonicalizing(
            $porPerfil[$multiproduto->id]['insurers'],
            InsurancePolicy::withoutProfileScope()->where('profile_id', $multiproduto->id)->pluck('insurer_name')->all(),
        );
        self::assertSame('150.00', $porPerfil[$multiproduto->id]['premio_mensal']);
    }

    public function test_lista_todas_apolices_dos_clientes_ativos_com_nome_do_cliente(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$perfilA, $clienteA] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->create(['profile_id' => $perfilA->id, 'insurer_name' => 'Icatu']);

        [$perfilB, $clienteB] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->create(['profile_id' => $perfilB->id, 'insurer_name' => 'Azos']);

        // Apólice inativa não deve aparecer na lista.
        InsurancePolicy::factory()->inactive()->create(['profile_id' => $perfilA->id, 'insurer_name' => 'Porto Seguro']);

        // Apólice de um cliente de OUTRO consultor nunca pode vazar aqui.
        $outroConsultor = User::factory()->consultant()->create();
        [$perfilOutro] = $this->criarClienteVinculado($outroConsultor);
        InsurancePolicy::factory()->create(['profile_id' => $perfilOutro->id, 'insurer_name' => 'Bradesco Seguros']);

        $linhas = app(ConsultantPortfolioService::class)->allActivePolicies($consultor);

        self::assertCount(2, $linhas);
        self::assertTrue($linhas->contains(fn (array $l) => $l['policy']->insurer_name === 'Icatu' && $l['client_name'] === $clienteA->name));
        self::assertTrue($linhas->contains(fn (array $l) => $l['policy']->insurer_name === 'Azos' && $l['client_name'] === $clienteB->name));
        self::assertFalse($linhas->contains(fn (array $l) => $l['policy']->insurer_name === 'Porto Seguro'));
        self::assertFalse($linhas->contains(fn (array $l) => $l['policy']->insurer_name === 'Bradesco Seguros'));
    }

    public function test_lista_todos_investimentos_dos_clientes_ativos_com_nome_do_cliente(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$perfilA, $clienteA] = $this->criarClienteVinculado($consultor);
        InvestmentRecord::factory()->create(['profile_id' => $perfilA->id, 'institution' => 'XP Investimentos']);

        // Ativo inativo não deve aparecer.
        InvestmentRecord::factory()->inactive()->create(['profile_id' => $perfilA->id, 'institution' => 'BTG Pactual']);

        // Ativo de um cliente de OUTRO consultor nunca pode vazar aqui.
        $outroConsultor = User::factory()->consultant()->create();
        [$perfilOutro] = $this->criarClienteVinculado($outroConsultor);
        InvestmentRecord::factory()->create(['profile_id' => $perfilOutro->id, 'institution' => 'Nubank']);

        $linhas = app(ConsultantPortfolioService::class)->allActiveInvestments($consultor);

        self::assertCount(1, $linhas);
        self::assertSame('XP Investimentos', $linhas->first()['investment']->institution);
        self::assertSame($clienteA->name, $linhas->first()['client_name']);
    }

    public function test_lista_de_clientes_sem_seguro_de_vida_e_acionavel(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$comSeguro] = $this->criarClienteVinculado($consultor);
        InsurancePolicy::factory()->life()->create(['profile_id' => $comSeguro->id]);

        [$semSeguro, $clienteSemSeguro] = $this->criarClienteVinculado($consultor);

        // Vínculo pendente não entra na lista — a política não autoriza ler
        // apólice de um perfil que o consultor ainda não pode acessar.
        $pendente = User::factory()->create();
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $pendente->id,
        ]);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertCount(1, $dados['sem_seguro_vida']);
        self::assertSame($semSeguro->id, $dados['sem_seguro_vida'][0]['profile_id']);
        self::assertSame($clienteSemSeguro->email, $dados['sem_seguro_vida'][0]['email']);
    }

    public function test_evolucao_investido_soma_snapshots_dos_clientes_ativos_por_mes(): void
    {
        $consultor = User::factory()->consultant()->create();

        [$perfilA] = $this->criarClienteVinculado($consultor);
        $investimentoA = InvestmentRecord::factory()->create(['profile_id' => $perfilA->id]);
        InvestmentSnapshot::create([
            'profile_id' => $perfilA->id,
            'investment_id' => $investimentoA->id,
            'year' => now()->year,
            'month' => now()->month,
            'amount' => '10000.00',
        ]);

        [$perfilB] = $this->criarClienteVinculado($consultor);
        $investimentoB = InvestmentRecord::factory()->create(['profile_id' => $perfilB->id]);
        InvestmentSnapshot::create([
            'profile_id' => $perfilB->id,
            'investment_id' => $investimentoB->id,
            'year' => now()->year,
            'month' => now()->month,
            'amount' => '5000.00',
        ]);

        // Snapshot de um cliente de OUTRO consultor nunca pode entrar na soma.
        $outroConsultor = User::factory()->consultant()->create();
        [$perfilOutro] = $this->criarClienteVinculado($outroConsultor);
        $investimentoOutro = InvestmentRecord::factory()->create(['profile_id' => $perfilOutro->id]);
        InvestmentSnapshot::create([
            'profile_id' => $perfilOutro->id,
            'investment_id' => $investimentoOutro->id,
            'year' => now()->year,
            'month' => now()->month,
            'amount' => '99999.00',
        ]);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor);

        self::assertCount(12, $dados['evolucao_investido']);
        $mesAtual = collect($dados['evolucao_investido'])->last();
        self::assertSame('15000.00', $mesAtual['valor']);
    }

    public function test_acoes_pendentes_lista_vinculos_ordenados_e_faturas_vencendo(): void
    {
        // Congela o relógio no segundo exato: "dias pendente" soma
        // diffInDays(invited_at, now()), e o MySQL trunca invited_at pro
        // segundo ao gravar — sem alinhar os dois em startOfSecond(), a
        // fração de segundo perdida na gravação sobra pro ceil() (ver
        // InvestmentRecord::daysHeld()) arredondar 10 dias pra 11.
        $this->travelTo(now()->startOfSecond());

        $consultor = User::factory()->consultant()->create();

        $antigo = User::factory()->create(['name' => 'Convite Antigo']);
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $antigo->id,
            'invited_at' => now()->subDays(10),
        ]);

        $recente = User::factory()->create(['name' => 'Convite Recente']);
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $recente->id,
            'invited_at' => now()->subDays(2),
        ]);

        [$perfil, $cliente] = $this->criarClienteVinculado($consultor);
        $cartaoProximo = CreditCard::factory()->create(['profile_id' => $perfil->id]);
        CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartaoProximo->id,
            'year' => now()->year,
            'month' => now()->month,
            'closing_date' => now(),
            'due_date' => now()->addDays(3),
            'total_amount' => '500.00',
            'status' => InvoiceStatus::Open,
        ]);

        // Fatura fora da janela de dias configurada não deve aparecer.
        $cartaoDistante = CreditCard::factory()->create(['profile_id' => $perfil->id]);
        CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartaoDistante->id,
            'year' => now()->year,
            'month' => now()->month,
            'closing_date' => now(),
            'due_date' => now()->addDays(30),
            'total_amount' => '999.00',
            'status' => InvoiceStatus::Open,
        ]);

        // Vínculo pendente de OUTRO consultor nunca pode vazar aqui.
        $outroConsultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $outroConsultor->id,
            'client_id' => User::factory()->create()->id,
        ]);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor)['acoes_pendentes'];

        self::assertCount(2, $dados['vinculos']);
        self::assertSame('Convite Antigo', $dados['vinculos'][0]['name']);
        self::assertSame(10, $dados['vinculos'][0]['dias']);
        self::assertSame('Convite Recente', $dados['vinculos'][1]['name']);
        self::assertSame(2, $dados['vinculos'][1]['dias']);

        self::assertCount(1, $dados['faturas']);
        self::assertSame($cliente->name, $dados['faturas'][0]['cliente']);
        self::assertSame('500.00', $dados['faturas'][0]['valor']);
        self::assertSame('500.00', $dados['total_faturas']);
    }

    public function test_distribuicao_conta_individual_casal_e_vida_financeira(): void
    {
        $consultor = User::factory()->consultant()->create();

        // Individual.
        $this->criarClienteVinculado($consultor);

        // Casal com vida financeira única (preset transparente).
        [$perfilTransparente] = $this->criarCasalVinculado($consultor);
        $perfilTransparente->settings()->update(ProfileAccessSettings::transparentPreset());

        // Casal com vida financeira separada (preset privado).
        [$perfilPrivado] = $this->criarCasalVinculado($consultor);
        $perfilPrivado->settings()->update(ProfileAccessSettings::privatePreset());

        // Vínculo pendente não entra na distribuição — só a carteira ativa.
        $pendente = User::factory()->create();
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $pendente->id,
        ]);

        $dados = app(ConsultantPortfolioService::class)->overview($consultor)['distribuicao'];

        self::assertSame(1, $dados['individual']);
        self::assertSame(2, $dados['casal']);
        self::assertSame(1, $dados['vida_financeira']['unica']);
        self::assertSame(1, $dados['vida_financeira']['separada']);
    }

    /** @return array{0: FinancialProfile, 1: User} perfil de casal com dois logins (titular + cônjuge) */
    private function criarCasalVinculado(User $consultor): array
    {
        $titular = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create([
            'profile_id' => $perfil->id,
            'user_id' => $titular->id,
            'role' => MemberRole::Primary,
        ]);
        ProfileMember::factory()->secondary()->create([
            'profile_id' => $perfil->id,
            'user_id' => User::factory()->create()->id,
        ]);

        return [$perfil, $titular];
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarClienteVinculado(User $consultor, string $saldoConta = '0.00'): array
    {
        $cliente = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => $saldoConta]);

        return [$perfil, $cliente];
    }
}

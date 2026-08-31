<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\AssetClass;
use App\Enums\CardBrand;
use App\Enums\ConsultantClientStatus;
use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\InsuranceType;
use App\Enums\InvestmentSector;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\PaymentFrequency;
use App\Enums\ProfileType;
use App\Enums\ReturnRateType;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 40 clientes de teste vinculados ao consultor de demonstração — idades,
 * composição familiar e situação financeira variadas, para a carteira do
 * consultor (Painel, Seguros, Investimentos) ter volume de verdade pra
 * navegar, filtrar e ordenar. Nunca rode em produção.
 *
 * Cada cliente é independente: perfil, membros, contas, investimentos,
 * seguros e objetivos variam por "faixa patrimonial" sorteada com peso
 * pela idade — mais jovem pesa pra faixas mais baixas, mais velho pesa
 * pra mais altas, mas sempre com alguma chance de qualquer combinação
 * (patrimônio não é só função de idade na vida real).
 */
class ConsultantBulkClientsSeeder extends Seeder
{
    use DevOnlySeeder;

    private const TOTAL_CLIENTES = 40;

    /** @var array<int, string> */
    private array $nomesM = [
        'Lucas', 'Gabriel', 'Pedro', 'Rafael', 'Bruno', 'Felipe', 'Thiago', 'Marcelo',
        'André', 'Rodrigo', 'Diego', 'Eduardo', 'Fernando', 'Gustavo', 'Henrique',
        'Igor', 'João', 'Leonardo', 'Marcos', 'Paulo', 'Ricardo', 'Vinícius', 'Renato',
        'Sérgio', 'Cláudio',
    ];

    /** @var array<int, string> */
    private array $nomesF = [
        'Ana', 'Beatriz', 'Camila', 'Daniela', 'Fernanda', 'Gabriela', 'Helena',
        'Isabela', 'Juliana', 'Larissa', 'Mariana', 'Natália', 'Patrícia', 'Renata',
        'Sofia', 'Tatiana', 'Vanessa', 'Carolina', 'Débora', 'Eliane', 'Flávia',
        'Cristina', 'Rosana', 'Simone', 'Vera',
    ];

    /** @var array<int, string> */
    private array $sobrenomes = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves',
        'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho',
        'Almeida', 'Barbosa', 'Nascimento', 'Araújo', 'Fernandes', 'Rocha',
        'Dias', 'Monteiro', 'Cardoso', 'Teixeira', 'Moreira',
    ];

    public function run(): void
    {
        $this->abortInProduction();

        $consultor = User::where('email', 'consultor@cerne.test')->where('role', UserRole::Consultant)->first();

        if ($consultor === null) {
            $this->command->error('Rode o DevSeeder antes: ele cria o consultor de demonstração.');

            return;
        }

        $usados = [];

        for ($i = 1; $i <= self::TOTAL_CLIENTES; $i++) {
            $this->criarCliente($consultor, $i, $usados);
        }

        $this->command->newLine();
        $this->command->info(sprintf('%d clientes de teste vinculados a %s.', self::TOTAL_CLIENTES, $consultor->email));
    }

    /** @param array<string, bool> $usados e-mails já sorteados, pra não colidir */
    private function criarCliente(User $consultor, int $indice, array &$usados): void
    {
        $idade = random_int(20, 70);
        $tipoPerfil = ProfileType::from($this->sortear([
            ProfileType::Single->value => 40,
            ProfileType::Couple->value => 35,
            ProfileType::Family->value => 25,
        ]));
        $endividado = random_int(1, 100) <= 18; // ~1 em cada 6

        // Endividado de verdade não costuma estar, ao mesmo tempo, com
        // uma carteira de investimentos robusta — força a faixa baixa
        // pra a dívida aparecer no patrimônio líquido em vez de sumir
        // atrás de um investimento grande sorteado por coincidência.
        $faixa = $endividado ? $this->faixaPorNome('baixa') : $this->sortearFaixaPatrimonial($idade);

        $generoTitular = random_int(0, 1) === 0 ? 'M' : 'F';
        $nomeTitular = $this->nomeCompleto($generoTitular, $usados);
        $email = $this->emailPara($nomeTitular, $indice, $usados);

        $titular = User::create([
            'name' => $nomeTitular,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => UserRole::Client,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $profile = FinancialProfile::create([
            'owner_user_id' => $titular->id,
            'profile_name' => $tipoPerfil === ProfileType::Single
                ? 'Finanças de '.explode(' ', $nomeTitular)[0]
                : 'Família '.explode(' ', $nomeTitular)[1],
            'profile_type' => $tipoPerfil,
            'base_currency' => 'BRL',
            'reference_month' => 1,
        ]);

        app(ProfileContext::class)->set($profile);

        $titularMember = ProfileMember::create([
            'profile_id' => $profile->id,
            'user_id' => $titular->id,
            'name' => explode(' ', $nomeTitular)[0],
            'role' => MemberRole::Primary,
            'color_hex' => fake()->hexColor(),
            'is_active' => true,
        ]);

        $conjugeMember = null;
        if ($tipoPerfil !== ProfileType::Single) {
            $generoConjuge = $generoTitular === 'M' ? 'F' : 'M';
            $nomeConjuge = $this->primeiroNome($generoConjuge);

            // ~65% dos casais têm os dois com login próprio — regra: só faz
            // sentido falar em "vida financeira única/separada" quando o
            // cônjuge também acessa o app. O resto fica como convite
            // pendente (cônjuge só existe como registro, sem conta ainda).
            $conjugeTemLogin = random_int(1, 100) <= 65;

            if ($conjugeTemLogin) {
                $sobrenomeTitular = explode(' ', $nomeTitular)[1];
                $mesmoSobrenome = random_int(1, 100) <= 50;
                $sobrenomeConjuge = $mesmoSobrenome ? $sobrenomeTitular : $this->sobrenomes[array_rand($this->sobrenomes)];

                $userConjuge = User::create([
                    'name' => "{$nomeConjuge} {$sobrenomeConjuge}",
                    'email' => $this->emailPara("conjuge-{$nomeConjuge}", $indice, $usados),
                    'password' => Hash::make('password'),
                    'role' => UserRole::Client,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                $conjugeMember = ProfileMember::create([
                    'profile_id' => $profile->id,
                    'user_id' => $userConjuge->id,
                    'name' => $nomeConjuge,
                    'role' => MemberRole::Secondary,
                    'color_hex' => fake()->hexColor(),
                    'is_active' => true,
                ]);
            } else {
                $conjugeMember = ProfileMember::create([
                    'profile_id' => $profile->id,
                    'user_id' => null, // convite pendente: cônjuge ainda não criou login próprio
                    'name' => $nomeConjuge,
                    'role' => MemberRole::Secondary,
                    'color_hex' => fake()->hexColor(),
                    'is_active' => true,
                ]);
            }
        }

        $convidadoEm = now()->subMonths(random_int(1, 30));
        ConsultantClient::create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => $convidadoEm,
            'accepted_at' => $convidadoEm->copy()->addDays(random_int(1, 10)),
        ]);

        $membros = array_filter([$titularMember, $conjugeMember]);

        $this->criarContas($titular->id, $membros, $faixa, $endividado);
        $this->criarInvestimentos($titular->id, $membros, $faixa, $idade);
        $this->criarSeguros($titular->id, $membros, $faixa, $idade, $tipoPerfil);
        $this->criarObjetivos($titular->id, $membros, $idade, $tipoPerfil);
    }

    // -----------------------------------------------------------------
    // Faixa patrimonial — sorteio com peso pela idade
    // -----------------------------------------------------------------

    /** @return array{saldo: array{int,int}, invest: array{int,int}, cobertura: array{int,int}} */
    private function sortearFaixaPatrimonial(int $idade): array
    {
        $pesos = match (true) {
            $idade < 35 => ['baixa' => 45, 'media' => 35, 'alta' => 15, 'muito_alta' => 5],
            $idade < 50 => ['baixa' => 20, 'media' => 40, 'alta' => 30, 'muito_alta' => 10],
            default => ['baixa' => 12, 'media' => 28, 'alta' => 38, 'muito_alta' => 22],
        };

        return $this->faixaPorNome($this->sortear($pesos));
    }

    /** @return array{nome: string, saldo: array{int,int}, invest: array{int,int}, cobertura: array{int,int}} */
    private function faixaPorNome(string $faixa): array
    {
        return match ($faixa) {
            'baixa' => ['nome' => 'baixa', 'saldo' => [1500, 12000], 'invest' => [0, 25000], 'cobertura' => [50000, 250000]],
            'media' => ['nome' => 'media', 'saldo' => [8000, 50000], 'invest' => [15000, 180000], 'cobertura' => [200000, 700000]],
            'alta' => ['nome' => 'alta', 'saldo' => [30000, 150000], 'invest' => [150000, 900000], 'cobertura' => [500000, 1500000]],
            default => ['nome' => 'muito_alta', 'saldo' => [100000, 500000], 'invest' => [800000, 5000000], 'cobertura' => [1000000, 5000000]],
        };
    }

    // -----------------------------------------------------------------
    // Contas + dívida
    // -----------------------------------------------------------------

    /** @param array<int, ProfileMember> $membros */
    private function criarContas(string $userId, array $membros, array $faixa, bool $endividado): void
    {
        $bancos = ['Itaú', 'Bradesco', 'Nubank', 'Inter', 'Santander', 'BTG Pactual', 'C6 Bank'];
        $qtdContas = random_int(1, min(3, count($membros) + 1));

        for ($c = 0; $c < $qtdContas; $c++) {
            $membro = $membros[array_rand($membros)];
            $saldo = $endividado
                ? (string) random_int(100, 2500)
                : (string) random_int($faixa['saldo'][0], $faixa['saldo'][1]);

            BankAccount::create([
                'member_id' => $membro->id,
                'bank_name' => $bancos[array_rand($bancos)],
                'account_type' => AccountType::Checking,
                'current_balance' => $saldo,
                'is_joint' => count($membros) > 1 && $c === 0,
                'color_hex' => fake()->hexColor(),
            ]);
        }

        if (! $endividado) {
            return;
        }

        // Fatura vencida bem acima do saldo — é o que a carteira do
        // consultor mostra como patrimônio líquido negativo.
        $membroCartao = $membros[array_rand($membros)];
        $cartao = CreditCard::create([
            'member_id' => $membroCartao->id,
            'card_name' => 'Cartão principal',
            'bank_name' => $bancos[array_rand($bancos)],
            'card_brand' => fake()->randomElement([CardBrand::Visa, CardBrand::Mastercard, CardBrand::Elo]),
            'credit_limit' => (string) random_int(3000, 15000),
            'closing_day' => random_int(1, 28),
            'due_day' => random_int(1, 28),
            'last_four_digits' => fake()->numerify('####'),
        ]);

        $hoje = CarbonImmutable::now();
        CreditCardInvoice::create([
            'credit_card_id' => $cartao->id,
            'year' => $hoje->year,
            'month' => $hoje->month,
            'closing_date' => $hoje->subDays(15),
            'due_date' => $hoje->subDays(5),
            'total_amount' => (string) random_int(3000, 14000),
            'status' => InvoiceStatus::Overdue,
        ]);
    }

    // -----------------------------------------------------------------
    // Investimentos
    // -----------------------------------------------------------------

    /** @param array<int, ProfileMember> $membros */
    private function criarInvestimentos(string $userId, array $membros, array $faixa, int $idade): void
    {
        // Cliente endividado ou de faixa baixa pode não ter investimento
        // nenhum ainda — é um retrato realista, não um bug.
        $qtd = match ($faixa['nome']) {
            'baixa' => random_int(0, 2),
            'media' => random_int(1, 3),
            'alta' => random_int(2, 4),
            default => random_int(3, 6),
        };

        if ($qtd === 0) {
            return;
        }

        $opcoes = match ($faixa['nome']) {
            'baixa' => [
                [AssetClass::Poupanca, InvestmentSector::Reserve, null],
                [AssetClass::ReservaPaz, InvestmentSector::Reserve, 'CDI 100%'],
                [AssetClass::Cdb, InvestmentSector::FixedIncome, 'CDI 98%'],
            ],
            'media' => [
                [AssetClass::Cdb, InvestmentSector::FixedIncome, 'CDI 108%'],
                [AssetClass::Tesouro, InvestmentSector::FixedIncome, 'IPCA + 5,8%'],
                [AssetClass::Lci, InvestmentSector::FixedIncome, '95% do CDI'],
                [AssetClass::Fii, InvestmentSector::VariableIncome, null],
                [AssetClass::Acao, InvestmentSector::VariableIncome, null],
            ],
            'alta' => [
                [AssetClass::Previdencia, InvestmentSector::Retirement, 'CDI 100%'],
                [AssetClass::Tesouro, InvestmentSector::FixedIncome, 'IPCA + 6,1%'],
                [AssetClass::Fundo, InvestmentSector::VariableIncome, null],
                [AssetClass::Etf, InvestmentSector::VariableIncome, null],
                [AssetClass::Fii, InvestmentSector::VariableIncome, null],
                [AssetClass::Acao, InvestmentSector::VariableIncome, null],
            ],
            default => [
                [AssetClass::Previdencia, InvestmentSector::Retirement, 'CDI 102%'],
                [AssetClass::EtfInternacional, InvestmentSector::International, null],
                [AssetClass::AcaoExterior, InvestmentSector::International, null],
                [AssetClass::FundoInfra, InvestmentSector::VariableIncome, 'IPCA + 7%'],
                [AssetClass::Cripto, InvestmentSector::VariableIncome, null],
                [AssetClass::Fundo, InvestmentSector::VariableIncome, null],
            ],
        };

        $instituicoes = ['XP Investimentos', 'BTG Pactual', 'Itaú', 'Rico', 'Ágora', 'Nu Invest', 'Icatu'];

        for ($n = 0; $n < $qtd; $n++) {
            [$classe, $setor, $taxa] = $opcoes[array_rand($opcoes)];
            $membro = $membros[array_rand($membros)];

            $atual = (string) random_int($faixa['invest'][0] ?: 500, max($faixa['invest'][1], 1000));
            // Ganho/perda plausível: entre -8% e +25% do valor atual.
            $variacaoPct = random_int(-8, 25) / 100;
            $investido = bcdiv($atual, (string) (1 + $variacaoPct), 2);

            $investimento = InvestmentRecord::create([
                'member_id' => $membro->id,
                'sector' => $setor,
                'asset_class' => $classe,
                'name' => $classe->label().' '.random_int(2026, 2035),
                'institution' => $instituicoes[array_rand($instituicoes)],
                'current_amount' => $atual,
                'invested_amount' => $investido,
                'purchase_date' => CarbonImmutable::now()->subMonths(random_int(3, min(120, ($idade - 18) * 12))),
                'return_rate' => $taxa,
                'return_rate_type' => $taxa !== null ? ReturnRateType::PostfixedCdi : null,
                'created_by_user_id' => $userId,
            ]);

            $this->criarSnapshotsHistorico($investimento);
        }
    }

    /**
     * Fotos mensais dos últimos N meses (config('cerne.dashboard.
     * evolution_months')), pra "Evolução do patrimônio investido" da
     * Carteira do consultor não nascer vazia — sem isso só o cron mensal
     * (InvestmentSnapshotService, dia 1) preencheria essa tabela, e um
     * ambiente novo ficaria meses sem gráfico nenhum.
     *
     * Crescimento fictício deliberado, ancorado no patrimônio ATUAL (mês
     * corrente = current_amount, exato): 12 meses atrás valia entre 55% e
     * 80% de hoje — não é o `invested_amount` (isso é só o ganho/perda da
     * posição, quase sempre perto de 1:1 do atual, o que fazia o gráfico
     * sair quase reto). Ruído mês a mês por cima da curva, mesma ideia de
     * InvestmentsDemoSeeder::previdencia(), generalizada pra qualquer
     * ativo (não é o histórico real do ativo, só faz a carteira ter
     * volume de verdade pra navegar).
     */
    private function criarSnapshotsHistorico(InvestmentRecord $investimento): void
    {
        $meses = config('cerne.dashboard.evolution_months');
        $hoje = CarbonImmutable::now();
        $atual = (float) $investimento->current_amount;

        $pctInicio = random_int(55, 80) / 100;
        $ritmo = ($atual > 0 ? 1 / $pctInicio : 1) ** (1 / $meses);

        $valor = $atual;
        for ($i = 0; $i < $meses; $i++) {
            $competencia = $hoje->subMonths($i);

            InvestmentSnapshot::create([
                'investment_id' => $investimento->id,
                'year' => $competencia->year,
                'month' => $competencia->month,
                'amount' => number_format(max($valor, 0), 2, '.', ''),
            ]);

            if ($i < $meses - 1) {
                $ruido = random_int(-400, 400) / 10000; // ±4%
                $valor = $valor / $ritmo * (1 + $ruido);
            }
        }
    }

    // -----------------------------------------------------------------
    // Seguros
    // -----------------------------------------------------------------

    /** @param array<int, ProfileMember> $membros */
    private function criarSeguros(string $userId, array $membros, array $faixa, int $idade, ProfileType $tipoPerfil): void
    {
        // Parte dos clientes não tem seguro nenhum — é a oportunidade de
        // venda que a carteira do consultor existe pra mostrar.
        if (random_int(1, 100) <= 25) {
            return;
        }

        $seguradoras = ['Icatu', 'AZOS', 'Porto Seguro', 'Allianz', 'SulAmérica', 'Bradesco Seguros', 'Prudential', 'MetLife'];
        $qtd = match ($faixa['nome']) {
            'baixa' => random_int(1, 1),
            'media' => random_int(1, 2),
            'alta' => random_int(1, 3),
            default => random_int(2, 3),
        };

        // Seguro de vida é mais comum depois dos 30 e quando há
        // dependente (casal/família) — não é regra, é peso.
        $tiposPossiveis = [InsuranceType::Carro, InsuranceType::Residencia, InsuranceType::Saude];
        if ($idade >= 28 && ($tipoPerfil !== ProfileType::Single || random_int(0, 1) === 0)) {
            $tiposPossiveis[] = InsuranceType::Vida;
            $tiposPossiveis[] = InsuranceType::Vida; // peso extra
        }

        for ($n = 0; $n < $qtd; $n++) {
            $tipo = $tiposPossiveis[array_rand($tiposPossiveis)];
            $membro = $membros[array_rand($membros)];
            $mensal = (string) random_int(
                (int) ($faixa['cobertura'][0] / 3000),
                (int) ($faixa['cobertura'][1] / 1500),
            );

            InsurancePolicy::create([
                'member_id' => $membro->id,
                'insurance_type' => $tipo,
                'insurer_name' => $seguradoras[array_rand($seguradoras)],
                'policy_number' => strtoupper(fake()->bothify('??-####-####')),
                'coverage_amount' => $tipo === InsuranceType::Saude ? null : (string) random_int($faixa['cobertura'][0], $faixa['cobertura'][1]),
                'monthly_premium' => $mensal,
                'payment_frequency' => PaymentFrequency::Monthly,
                'start_date' => CarbonImmutable::now()->subMonths(random_int(1, 48)),
                // ~1 em cada 5 apólices vence nos próximos 60 dias — testa
                // o alerta da tela de seguros e da carteira.
                'expiry_date' => random_int(1, 5) === 1 ? CarbonImmutable::now()->addDays(random_int(5, 55)) : null,
                'created_by_user_id' => $userId,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Objetivos
    // -----------------------------------------------------------------

    /** @param array<int, ProfileMember> $membros */
    private function criarObjetivos(string $userId, array $membros, int $idade, ProfileType $tipoPerfil): void
    {
        if (random_int(1, 100) <= 20) {
            return; // nem todo mundo tem objetivo cadastrado ainda
        }

        $poolJovem = ['Intercâmbio', 'Entrada do apartamento', 'Troca de carro', 'Casamento', 'Curso de especialização'];
        $poolMeia = ['Entrada do apartamento', 'Reforma da casa', 'Viagem em família', 'Troca de carro', 'Faculdade dos filhos'];
        $poolMaduro = ['Aposentadoria', 'Viagem dos sonhos', 'Casa de praia', 'Ajudar os netos', 'Reserva de tranquilidade'];

        $pool = match (true) {
            $idade < 35 => $poolJovem,
            $idade < 55 => $tipoPerfil === ProfileType::Family ? $poolMeia : array_merge($poolJovem, $poolMeia),
            default => $poolMaduro,
        };

        $qtd = random_int(1, 3);
        $usados = [];

        for ($n = 0; $n < $qtd; $n++) {
            $disponiveis = array_diff($pool, $usados);
            if ($disponiveis === []) {
                break;
            }
            $nome = $disponiveis[array_rand($disponiveis)];
            $usados[] = $nome;

            $valor = (string) random_int(15000, 250000);
            $acumulado = bcmul($valor, (string) (random_int(5, 70) / 100), 2);

            Goal::create([
                'member_id' => random_int(0, 1) === 0 ? null : $membros[array_rand($membros)]->id,
                'name' => $nome,
                'priority' => $n + 1,
                'estimated_value' => $valor,
                'target_date' => CarbonImmutable::now()->addMonths(random_int(6, 60)),
                'funding_method' => fake()->randomElement(FundingMethod::cases()),
                'current_amount' => $acumulado,
                'status' => GoalStatus::Active,
                'created_by_user_id' => $userId,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Nomes
    // -----------------------------------------------------------------

    private function primeiroNome(string $genero): string
    {
        return $genero === 'M'
            ? $this->nomesM[array_rand($this->nomesM)]
            : $this->nomesF[array_rand($this->nomesF)];
    }

    /** @param array<string, bool> $usados */
    private function nomeCompleto(string $genero, array &$usados): string
    {
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $nome = $this->primeiroNome($genero).' '.$this->sobrenomes[array_rand($this->sobrenomes)];
            if (! isset($usados[$nome])) {
                $usados[$nome] = true;

                return $nome;
            }
        }

        // Depois de 20 tentativas (pouco provável com os 40 clientes),
        // aceita repetido — nome de teste duplicado não quebra nada.
        return $nome;
    }

    /** @param array<string, bool> $usados */
    private function emailPara(string $nome, int $indice, array &$usados): string
    {
        $slug = \Illuminate\Support\Str::slug($nome);
        $email = "cliente-{$indice}-{$slug}@cerne.test";
        $usados[$email] = true;

        return $email;
    }

    /**
     * Sorteio ponderado simples — evita puxar um pacote só pra isso.
     *
     * @param  array<string, int>  $pesos
     */
    private function sortear(array $pesos): string
    {
        $total = array_sum($pesos);
        $alvo = random_int(1, $total);
        $acumulado = 0;

        foreach ($pesos as $chave => $peso) {
            $acumulado += $peso;
            if ($alvo <= $acumulado) {
                return (string) $chave;
            }
        }

        return (string) array_key_first($pesos);
    }
}

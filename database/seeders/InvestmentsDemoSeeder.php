<?php

namespace Database\Seeders;

use App\Enums\AllocationAssetClass;
use App\Enums\AssetClass;
use App\Enums\Benchmark;
use App\Enums\InvestmentSector;
use App\Enums\InvestorType;
use App\Enums\PeriodType;
use App\Enums\ReserveType;
use App\Enums\ReturnRateType;
use App\Enums\TransactionType;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\InvestmentPerformance;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\RecommendedAllocation;
use App\Services\InvestmentSnapshotService;
use App\Services\InvestmentTransactionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;

/**
 * Carteira de demonstração — nunca rode em produção.
 *
 * As ações e FIIs são criados por TRANSAÇÕES reais passando pelo serviço,
 * não com preço médio digitado à mão: assim os números da tela são o
 * resultado do mesmo cálculo que o usuário final aciona.
 */
class InvestmentsDemoSeeder extends Seeder
{
    use DevOnlySeeder;

    public function run(): void
    {
        $this->abortInProduction();

        $profile = FinancialProfile::where('profile_name', 'Família Ribeiro')->first();

        if ($profile === null) {
            $this->command->error('Rode o DevSeeder antes.');

            return;
        }

        app(ProfileContext::class)->set($profile);

        $ana = ProfileMember::where('profile_id', $profile->id)->where('name', 'Ana')->firstOrFail();
        $bruno = ProfileMember::where('profile_id', $profile->id)->where('name', 'Bruno')->firstOrFail();
        $userId = $profile->owner_user_id;

        $this->perfilInvestidor($ana, $bruno);
        $this->rendaFixa($ana, $bruno, $userId);
        $this->rendaVariavel($ana, $bruno, $userId);
        $this->reservas($ana, $bruno);
        $this->rentabilidade($ana);

        app(InvestmentSnapshotService::class)->captureMonth();

        $this->command->newLine();
        $this->command->info(sprintf(
            'Investimentos: %d ativos, %d transações, %d reservas.',
            InvestmentRecord::count(),
            \App\Models\InvestmentTransaction::count(),
            FinancialReserve::count(),
        ));
    }

    private function perfilInvestidor(ProfileMember $ana, ProfileMember $bruno): void
    {
        $perfilAna = InvestorProfile::create([
            'member_id' => $ana->id,
            'investor_type' => InvestorType::Moderate,
            'monthly_cost_average' => '11500.00',
            'months_reserve_target' => 6,
        ]);

        // A alocação recomendada precisa somar 100%.
        $alocacoes = [
            [AllocationAssetClass::FixedIncome, '40.00'],
            [AllocationAssetClass::EquitiesFiis, '30.00'],
            [AllocationAssetClass::Funds, '15.00'],
            [AllocationAssetClass::International, '10.00'],
            [AllocationAssetClass::DigitalAssets, '5.00'],
        ];

        foreach ($alocacoes as [$classe, $pct]) {
            RecommendedAllocation::create([
                'investor_profile_id' => $perfilAna->id,
                'asset_class' => $classe,
                'target_percentage' => $pct,
            ]);
        }

        InvestorProfile::create([
            'member_id' => $bruno->id,
            'investor_type' => InvestorType::Conservative,
            'monthly_cost_average' => '11500.00',
            'months_reserve_target' => 12,
        ]);
    }

    private function rendaFixa(ProfileMember $ana, ProfileMember $bruno, string $userId): void
    {
        $ativos = [
            [$ana, AssetClass::ReservaPaz, InvestmentSector::Reserve, 'Reserva de emergência', 'Itaú', '69000.00', 'CDI 102%', ReturnRateType::PostfixedCdi],
            [$bruno, AssetClass::Cdb, InvestmentSector::FixedIncome, 'CDB Inter 2028', 'Inter', '42500.00', 'CDI 112%', ReturnRateType::PostfixedCdi],
            [$ana, AssetClass::Tesouro, InvestmentSector::FixedIncome, 'Tesouro IPCA+ 2035', 'Tesouro Direto', '31800.00', 'IPCA + 6,2%', ReturnRateType::PostfixedIpca],
            [$bruno, AssetClass::Lci, InvestmentSector::FixedIncome, 'LCI Bradesco', 'Bradesco', '25000.00', '96% do CDI', ReturnRateType::PostfixedCdi],
        ];

        foreach ($ativos as [$membro, $classe, $setor, $nome, $instituicao, $valor, $taxa, $tipoTaxa]) {
            InvestmentRecord::create([
                'member_id' => $membro->id,
                'sector' => $setor,
                'asset_class' => $classe,
                'name' => $nome,
                'institution' => $instituicao,
                'current_amount' => $valor,
                'invested_amount' => $valor,
                'return_rate' => $taxa,
                'return_rate_type' => $tipoTaxa,
                'created_by_user_id' => $userId,
            ]);
        }

        $this->previdencia($ana, $userId);
    }

    /**
     * Previdência à parte da renda fixa comum: tem aporte inicial, data
     * de compra e histórico de fotos mensais — é o "card de contrato"
     * com gráfico de evolução na tela (ver InvestmentSnapshot).
     */
    private function previdencia(ProfileMember $ana, string $userId): void
    {
        $inicio = CarbonImmutable::now()->subYears(2)->startOfMonth();

        $pgbl = InvestmentRecord::create([
            'member_id' => $ana->id,
            'sector' => InvestmentSector::Retirement,
            'asset_class' => AssetClass::Previdencia,
            'name' => 'PGBL Icatu',
            'institution' => 'Icatu',
            'current_amount' => '87400.00',
            'invested_amount' => '72000.00',
            'purchase_date' => $inicio,
            'return_rate' => 'CDI 98%',
            'return_rate_type' => ReturnRateType::PostfixedCdi,
            'created_by_user_id' => $userId,
        ]);

        // Fotos mensais de aporte + valorização crescendo até o valor
        // atual — é o que desenha o gráfico de evolução na tela. Uma
        // curva com leve ruído fica mais real que uma reta.
        $meses = 24;
        $inicial = 72000.0;
        $final = 87400.0;
        $ritmo = ($final / $inicial) ** (1 / $meses);
        $variacao = [0.997, 1.006, 1.002, 0.998, 1.009, 1.001, 0.995, 1.011, 1.003, 0.999, 1.007, 1.0];

        $valor = $inicial;
        for ($i = 1; $i <= $meses; $i++) {
            $competencia = $inicio->addMonths($i);
            $valor *= $ritmo * $variacao[($i - 1) % count($variacao)];

            InvestmentSnapshot::create([
                'investment_id' => $pgbl->id,
                'year' => $competencia->year,
                'month' => $competencia->month,
                'amount' => number_format($i === $meses ? $final : $valor, 2, '.', ''),
            ]);
        }
    }

    /** Ações e FIIs nascem de transações reais, com preço médio calculado. */
    private function rendaVariavel(ProfileMember $ana, ProfileMember $bruno, string $userId): void
    {
        $service = app(InvestmentTransactionService::class);

        // [membro, classe, setor, ticker, nome, valor atual, [compras: qtd, preço, data]]
        $ativos = [
            [$ana, AssetClass::Acao, InvestmentSector::VariableIncome, 'PETR4', 'Petrobras PN', '18420.00', [
                ['400', '32.50', '2025-03-14'],
                ['200', '38.20', '2025-11-08'],
            ]],
            [$ana, AssetClass::Fii, InvestmentSector::VariableIncome, 'HGLG11', 'CSHG Logística', '14900.00', [
                ['80', '158.40', '2025-05-20'],
                ['20', '172.10', '2026-02-11'],
            ]],
            [$bruno, AssetClass::Etf, InvestmentSector::VariableIncome, 'IVVB11', 'iShares S&P 500', '22300.00', [
                ['120', '168.90', '2025-01-22'],
            ]],
            [$bruno, AssetClass::Cripto, InvestmentSector::VariableIncome, 'BTC', 'Bitcoin', '31200.00', [
                ['0.052400', '412000.00', '2025-08-30'],
            ]],
            [$ana, AssetClass::EtfInternacional, InvestmentSector::International, 'VOO', 'Vanguard S&P 500', '19800.00', [
                ['32', '512.30', '2025-06-18'],
            ]],
        ];

        foreach ($ativos as [$membro, $classe, $setor, $ticker, $nome, $valorAtual, $compras]) {
            $ativo = InvestmentRecord::create([
                'member_id' => $membro->id,
                'sector' => $setor,
                'asset_class' => $classe,
                'ticker' => $ticker,
                'name' => $nome,
                'institution' => 'XP Investimentos',
                'current_amount' => $valorAtual,
                'created_by_user_id' => $userId,
            ]);

            foreach ($compras as [$qtd, $preco, $data]) {
                $service->record($ativo->refresh(), [
                    'type' => TransactionType::Buy,
                    'quantity' => $qtd,
                    'unit_price' => $preco,
                    'total_amount' => bcmul($qtd, $preco, 2),
                    'broker_fee' => '4.90',
                    'operation_date' => CarbonImmutable::parse($data),
                ], $userId);
            }
        }

        // Um provento, para a aba de transações mostrar o caso.
        $fii = InvestmentRecord::where('ticker', 'HGLG11')->first();

        if ($fii !== null) {
            $service->record($fii, [
                'type' => TransactionType::Dividend,
                'total_amount' => '118.40',
                'operation_date' => CarbonImmutable::now()->subDays(12),
            ], $userId);
        }
    }

    private function reservas(ProfileMember $ana, ProfileMember $bruno): void
    {
        $reservaPaz = InvestmentRecord::where('name', 'Reserva de emergência')->first();

        FinancialReserve::create([
            'member_id' => $ana->id,
            'reserve_type' => ReserveType::Paz,
            // 11.500 x 6 meses
            'target_amount' => '69000.00',
            'current_amount' => '69000.00',
            'linked_investment_id' => $reservaPaz?->id,
        ]);

        FinancialReserve::create([
            'member_id' => $bruno->id,
            'reserve_type' => ReserveType::Oportunidade,
            'target_amount' => '50000.00',
            'current_amount' => '31200.00',
        ]);
    }

    private function rentabilidade(ProfileMember $ana): void
    {
        $hoje = CarbonImmutable::now();

        // Últimos 6 meses da carteira consolidada, comparados ao CDI.
        $meses = [
            ['1.42', '0.94'],
            ['0.87', '0.91'],
            ['-0.63', '0.95'],
            ['2.11', '0.92'],
            ['1.05', '0.90'],
            ['0.78', '0.93'],
        ];

        foreach ($meses as $i => [$retorno, $cdi]) {
            $data = $hoje->subMonths(count($meses) - 1 - $i);

            InvestmentPerformance::create([
                'member_id' => $ana->id,
                'investment_id' => null,
                'period_type' => PeriodType::Monthly,
                'year' => $data->year,
                'month' => $data->month,
                'return_amount' => bcmul('382000', bcdiv($retorno, '100', 6), 2),
                'return_percentage' => $retorno,
                'benchmark' => Benchmark::Cdi,
                'benchmark_return' => $cdi,
                'vs_benchmark' => bcsub($retorno, $cdi, 4),
            ]);
        }
    }
}

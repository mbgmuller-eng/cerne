<?php

namespace Database\Seeders;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seguros e objetivos de demonstração — nunca rode em produção.
 */
class InsuranceGoalsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $profile = FinancialProfile::where('profile_name', 'Família Ribeiro')->first();

        if ($profile === null) {
            $this->command->error('Rode o DevSeeder antes.');

            return;
        }

        app(ProfileContext::class)->set($profile);

        $ana = ProfileMember::where('profile_id', $profile->id)->where('name', 'Ana')->firstOrFail();
        $bruno = ProfileMember::where('profile_id', $profile->id)->where('name', 'Bruno')->firstOrFail();
        $userId = $profile->owner_user_id;
        $conta = BankAccount::where('bank_name', 'Itaú')->first();

        $this->seguros($ana, $bruno, $conta?->id, $userId);
        $this->objetivos($ana, $bruno, $userId);

        $this->command->newLine();
        $this->command->info(sprintf(
            'Seguros e objetivos: %d apólices, %d objetivos.',
            InsurancePolicy::count(),
            Goal::count(),
        ));
    }

    private function seguros(ProfileMember $ana, ProfileMember $bruno, ?string $contaId, string $userId): void
    {
        $hoje = CarbonImmutable::now();

        InsurancePolicy::create([
            'member_id' => $ana->id,
            'insurance_type' => InsuranceType::Vida,
            'insurer_name' => 'Icatu Seguros',
            'policy_number' => 'IC-2024-88431',
            'coverage_amount' => '850000.00',
            'monthly_premium' => '312.40',
            'payment_frequency' => PaymentFrequency::Monthly,
            'bank_account_id' => $contaId,
            'start_date' => $hoje->subYears(2),
            'beneficiaries' => [
                ['name' => 'Bruno Ribeiro', 'percentage' => 60],
                ['name' => 'Filhos', 'percentage' => 40],
            ],
            'created_by_user_id' => $userId,
        ]);

        InsurancePolicy::create([
            'member_id' => $bruno->id,
            'insurance_type' => InsuranceType::Vida,
            'insurer_name' => 'AZOS',
            'policy_number' => 'AZ-77120',
            'coverage_amount' => '600000.00',
            'monthly_premium' => '248.90',
            'payment_frequency' => PaymentFrequency::Monthly,
            'bank_account_id' => $contaId,
            'start_date' => $hoje->subYear(),
            'beneficiaries' => [
                ['name' => 'Ana Ribeiro', 'percentage' => 100],
            ],
            'created_by_user_id' => $userId,
        ]);

        // Apólice anual: a tela normaliza para o custo mensal equivalente.
        InsurancePolicy::create([
            'member_id' => null,
            'insurance_type' => InsuranceType::Residencia,
            'insurer_name' => 'Porto Seguro',
            'policy_number' => 'PS-RES-40182',
            'coverage_amount' => '420000.00',
            'monthly_premium' => '0.00',
            'annual_premium' => '1980.00',
            'payment_frequency' => PaymentFrequency::Annual,
            'bank_account_id' => $contaId,
            'start_date' => $hoje->subMonths(10),
            // Vence em breve: aciona o aviso da tela.
            'expiry_date' => $hoje->addDays(42),
            'created_by_user_id' => $userId,
        ]);

        InsurancePolicy::create([
            'member_id' => $bruno->id,
            'insurance_type' => InsuranceType::Carro,
            'insurer_name' => 'Allianz',
            'policy_number' => 'AL-CAR-99213',
            'coverage_amount' => '135000.00',
            'monthly_premium' => '0.00',
            'annual_premium' => '3240.00',
            'payment_frequency' => PaymentFrequency::Annual,
            'bank_account_id' => $contaId,
            'start_date' => $hoje->subMonths(4),
            'expiry_date' => $hoje->addMonths(8),
            'created_by_user_id' => $userId,
        ]);

        InsurancePolicy::create([
            'member_id' => null,
            'insurance_type' => InsuranceType::Saude,
            'insurer_name' => 'SulAmérica',
            'policy_number' => 'SA-FAM-3311',
            'coverage_amount' => null,
            'monthly_premium' => '1980.00',
            'payment_frequency' => PaymentFrequency::Monthly,
            'bank_account_id' => $contaId,
            'start_date' => $hoje->subYears(3),
            'created_by_user_id' => $userId,
        ]);
    }

    private function objetivos(ProfileMember $ana, ProfileMember $bruno, string $userId): void
    {
        $hoje = CarbonImmutable::now();
        $reserva = InvestmentRecord::where('name', 'Reserva de emergência')->first();

        // [nome, prioridade, valor, prazo em meses, método, acumulado, membro, investimento vinculado]
        $objetivos = [
            ['Reserva de emergência completa', 1, '69000.00', null, FundingMethod::InvestmentReturn, '0.00', null, $reserva?->id],
            ['Entrada do apartamento', 2, '180000.00', 30, FundingMethod::Installments, '62400.00', null, null],
            ['Intercâmbio das crianças', 3, '85000.00', 48, FundingMethod::Installments, '18200.00', null, null],
            ['Troca do carro', 4, '95000.00', 24, FundingMethod::LumpSum, '31000.00', $bruno->id, null],
            ['Viagem para o Japão', 5, '48000.00', 18, FundingMethod::Installments, '9600.00', $ana->id, null],
        ];

        foreach ($objetivos as [$nome, $prioridade, $valor, $meses, $metodo, $acumulado, $membroId, $investimentoId]) {
            Goal::create([
                'member_id' => $membroId,
                'name' => $nome,
                'priority' => $prioridade,
                'estimated_value' => $valor,
                'target_date' => $meses !== null ? $hoje->addMonths($meses) : null,
                'funding_method' => $metodo,
                'current_amount' => $acumulado,
                'linked_investment_id' => $investimentoId,
                'status' => GoalStatus::Active,
                'created_by_user_id' => $userId,
            ]);
        }

        Goal::create([
            'member_id' => null,
            'name' => 'Quitar o financiamento do carro antigo',
            'priority' => 9,
            'estimated_value' => '28000.00',
            'funding_method' => FundingMethod::LumpSum,
            'current_amount' => '28000.00',
            'status' => GoalStatus::Achieved,
            'created_by_user_id' => $userId,
        ]);
    }
}

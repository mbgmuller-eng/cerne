<?php

namespace Database\Seeders;

use App\Enums\Necessity;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;

/**
 * Fluxo de caixa de 10 meses pra Cristina — uma das clientes de teste do
 * ConsultantBulkClientsSeeder (perfil "Finanças de Cristina", faixa
 * baixa, endividada: saldo apertado e fatura de cartão vencida). O bulk
 * seeder cria conta, cartão, investimento — mas nenhum lançamento de
 * receita/despesa; é o que este seeder resolve, com uma história
 * plausível de orçamento apertado (mesmo raciocínio de
 * CashFlowDemoSeeder, mas repetido por 10 competências em vez de 1).
 *
 * Só usa a conta bancária (sem cartão) — a fatura vencida que o bulk
 * seeder já gerou fica como está, não é o foco aqui.
 */
class CristinaCashFlowDemoSeeder extends Seeder
{
    use DevOnlySeeder;

    private const MESES = 10;

    public function run(): void
    {
        $this->abortInProduction();

        $titular = User::where('role', UserRole::Client)
            ->where('name', 'like', 'Cristina %')
            ->whereHas('ownedProfiles', fn ($q) => $q->where('profile_type', ProfileType::Single))
            ->first();

        if ($titular === null) {
            $this->command->error('Cliente Cristina não encontrada. Rode o ConsultantBulkClientsSeeder antes.');

            return;
        }

        $profile = $titular->ownedProfiles()->where('profile_type', ProfileType::Single)->firstOrFail();
        app(ProfileContext::class)->set($profile);

        $membro = ProfileMember::where('profile_id', $profile->id)->firstOrFail();
        $conta = BankAccount::where('profile_id', $profile->id)->first();

        $salario = IncomeCategory::available()->where('name', 'Salário')->first();
        $vr = IncomeCategory::available()->where('name', 'VR/VA')->first();

        $habitacao = ExpenseCategory::available()->where('name', 'Habitação')->first();
        $alimentacao = ExpenseCategory::available()->where('name', 'Alimentação')->first();
        $transporte = ExpenseCategory::available()->where('name', 'Transporte')->first();
        $saude = ExpenseCategory::available()->where('name', 'Saúde')->first();
        $lazer = ExpenseCategory::available()->where('name', 'Lazer')->first();
        $vestuario = ExpenseCategory::available()->where('name', 'Vestuário')->first();
        $cuidados = ExpenseCategory::available()->where('name', 'Cuidados Pessoais')->first();

        $sub = fn (?ExpenseCategory $cat, string $nome) => $cat === null ? null : ExpenseSubcategory::available()
            ->where('category_id', $cat->id)->where('name', $nome)->first();

        for ($i = self::MESES - 1; $i >= 0; $i--) {
            $mes = CarbonImmutable::now()->subMonths($i)->startOfMonth();

            // --- receitas: salário fixo + VR quase todo mês -------------
            IncomeRecord::create([
                'member_id' => $membro->id,
                'category_id' => $salario->id,
                'description' => 'Salário',
                'amount' => '3400.00',
                'received_date' => $mes->setDay(5),
                'bank_account_id' => $conta?->id,
                'is_recurring' => true,
                'created_by_user_id' => $titular->id,
            ]);

            if ($vr !== null && random_int(1, 100) <= 90) {
                IncomeRecord::create([
                    'member_id' => $membro->id,
                    'category_id' => $vr->id,
                    'description' => 'Vale refeição',
                    'amount' => '450.00',
                    'received_date' => $mes->setDay(5),
                    'bank_account_id' => $conta?->id,
                    'is_recurring' => true,
                    'created_by_user_id' => $titular->id,
                ]);
            }

            // --- despesas fixas essenciais -------------------------------
            $fixas = [
                [$habitacao, 'Aluguel', 'Aluguel', '1100.00', 8, Necessity::Essential],
                [$habitacao, 'Condomínio', 'Condomínio', '320.00', 8, Necessity::Essential],
                [$habitacao, 'Luz', 'Energia elétrica', (string) random_int(120, 190).'.00', 10, Necessity::Essential],
                [$habitacao, 'Água', 'Água', (string) random_int(60, 95).'.00', 10, Necessity::Essential],
                [$habitacao, 'Internet', 'Internet', '99.90', 12, Necessity::Essential],
                [$habitacao, 'Celular', 'Celular', '59.90', 12, Necessity::Essential],
                [$alimentacao, 'Supermercado', 'Supermercado do mês', (string) random_int(520, 780).'.00', 6, Necessity::Essential],
                [$transporte, 'Combustível', 'Combustível', (string) random_int(150, 260).'.00', 15, Necessity::Essential],
            ];

            foreach ($fixas as [$cat, $subNome, $descricao, $valor, $dia, $necessidade]) {
                ExpenseRecord::create([
                    'member_id' => $membro->id,
                    'description' => $descricao,
                    'necessity' => $necessidade,
                    'category_id' => $cat->id,
                    'subcategory_id' => $sub($cat, $subNome)?->id,
                    'amount' => $valor,
                    'expense_date' => $mes->setDay($dia),
                    'bank_account_id' => $conta?->id,
                    'created_by_user_id' => $titular->id,
                ]);
            }

            // --- despesas variáveis: nem todo mês tem todas -------------
            $variaveis = [
                [$alimentacao, 'Restaurantes', 'Almoço fora', 60, [25, 70], 18, Necessity::Discretionary],
                [$transporte, 'Uber', 'Corridas de app', 55, [40, 120], 16, Necessity::Discretionary],
                [$saude, 'Farmácia', 'Farmácia', 50, [40, 130], 20, Necessity::Essential],
                [$lazer, 'Cinema', 'Cinema', 25, [35, 60], 22, Necessity::Discretionary],
                [$lazer, 'Bar', 'Saída com amigos', 30, [50, 150], 24, Necessity::Discretionary],
                [$vestuario, 'Roupas', 'Roupas', 20, [90, 280], 19, Necessity::Discretionary],
                [$cuidados, 'Salão', 'Salão', 35, [60, 140], 14, Necessity::Discretionary],
            ];

            foreach ($variaveis as [$cat, $subNome, $descricao, $chance, $faixa, $dia, $necessidade]) {
                if (random_int(1, 100) > $chance) {
                    continue;
                }

                ExpenseRecord::create([
                    'member_id' => $membro->id,
                    'description' => $descricao,
                    'necessity' => $necessidade,
                    'category_id' => $cat->id,
                    'subcategory_id' => $sub($cat, $subNome)?->id,
                    'amount' => (string) random_int($faixa[0], $faixa[1]).'.00',
                    'expense_date' => $mes->setDay($dia),
                    'bank_account_id' => $conta?->id,
                    'created_by_user_id' => $titular->id,
                ]);
            }
        }

        $this->command->newLine();
        $this->command->info(sprintf(
            'Fluxo de caixa de Cristina: %d receitas e %d despesas em %d meses.',
            IncomeRecord::query()->where('member_id', $membro->id)->count(),
            ExpenseRecord::query()->where('member_id', $membro->id)->count(),
            self::MESES,
        ));
    }
}

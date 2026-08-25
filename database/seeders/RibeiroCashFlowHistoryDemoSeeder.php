<?php

namespace Database\Seeders;

use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;

/**
 * Histórico de 10 meses (fechados) de fluxo de caixa pra Família Ribeiro
 * — só pra teste da média de gasto essencial (InvestorProfile::
 * essentialMonthlyAverage()), que agora só olha mês fechado. O
 * CashFlowDemoSeeder cobre só o mês corrente; sem meses passados, a
 * média fica sem dado nenhum. Mesma cesta de receitas/despesas do mês
 * corrente, repetida com variação leve mês a mês — direto na conta,
 * sem cartão (não precisa reabrir fatura de 10 meses fechados pra isso).
 */
class RibeiroCashFlowHistoryDemoSeeder extends Seeder
{
    use DevOnlySeeder;

    private const MESES = 10;

    public function run(): void
    {
        $this->abortInProduction();

        $profile = FinancialProfile::where('profile_name', 'Família Ribeiro')->first();

        if ($profile === null) {
            $this->command->error('Rode o DevSeeder e o DemoDataSeeder antes.');

            return;
        }

        app(ProfileContext::class)->set($profile);

        $ana = ProfileMember::where('profile_id', $profile->id)->where('name', 'Ana')->firstOrFail();
        $bruno = ProfileMember::where('profile_id', $profile->id)->where('name', 'Bruno')->firstOrFail();
        $userId = $profile->owner_user_id;
        $conta = BankAccount::where('bank_name', 'Itaú')->first();

        $salario = IncomeCategory::available()->where('name', 'Salário')->firstOrFail();
        $vr = IncomeCategory::available()->where('name', 'VR/VA')->firstOrFail();
        $dividendos = IncomeCategory::available()->where('name', 'Dividendos')->firstOrFail();

        $categorias = collect(['Habitação', 'Alimentação', 'Transporte', 'Saúde', 'Lazer', 'Pets', 'Vestuário'])
            ->mapWithKeys(fn ($nome) => [$nome => ExpenseCategory::available()->where('name', $nome)->first()]);

        $sub = fn (string $cat, string $nome) => $categorias[$cat] === null ? null : ExpenseSubcategory::available()
            ->where('category_id', $categorias[$cat]->id)->where('name', $nome)->first();

        $receitas = 0;
        $despesas = 0;

        // Do mês fechado mais antigo (10 meses atrás) até o mais recente —
        // "mês corrente" aqui é o mês do CashFlowDemoSeeder (o atual de
        // verdade), então o histórico vai de 10 a 1 mês atrás.
        for ($i = self::MESES; $i >= 1; $i--) {
            $mes = CarbonImmutable::now()->subMonths($i)->startOfMonth();

            $rendas = [
                [$ana, $salario, 'Salário Ana', '12400.00', 5, true],
                [$bruno, $salario, 'Salário Bruno', '9800.00', 5, true],
                [$ana, $vr, 'Vale refeição', '1100.00', 5, true],
                [$bruno, $vr, 'Vale refeição', '980.00', 5, true],
                [null, $dividendos, 'Dividendos da carteira', (string) random_int(50000, 95000) / 100, 15, false],
            ];

            foreach ($rendas as [$membro, $categoria, $descricao, $valor, $dia, $recorrente]) {
                IncomeRecord::create([
                    'member_id' => $membro?->id,
                    'category_id' => $categoria->id,
                    'description' => $descricao,
                    'amount' => number_format((float) $valor, 2, '.', ''),
                    'received_date' => $mes->setDay($dia),
                    'bank_account_id' => $conta?->id,
                    'is_recurring' => $recorrente,
                    'created_by_user_id' => $userId,
                ]);
                $receitas++;
            }

            // [categoria, subcategoria, descrição, valor base, variação %, dia, necessidade, membro]
            $gastos = [
                ['Habitação', 'Condomínio', 'Condomínio', 1450.00, 0, 5, Necessity::Essential, null],
                ['Habitação', 'Luz', 'Energia elétrica', 387.42, 15, 8, Necessity::Essential, null],
                ['Habitação', 'Internet', 'Internet fibra', 149.90, 0, 10, Necessity::Essential, null],
                ['Habitação', 'Streamings', 'Assinaturas de streaming', 96.70, 0, 12, Necessity::Discretionary, null],
                ['Alimentação', 'Supermercado', 'Supermercado do mês', 1842.30, 12, 6, Necessity::Essential, null],
                ['Alimentação', 'Restaurantes', 'Jantar fora', 280.00, 30, 14, Necessity::Discretionary, $ana],
                ['Alimentação', 'Padaria/café/Doceria', 'Padaria', 218.55, 10, 18, Necessity::Essential, null],
                ['Transporte', 'Combustível', 'Combustível', 620.00, 15, 9, Necessity::Essential, $bruno],
                ['Transporte', 'Uber', 'Corridas de app', 220.00, 25, 16, Necessity::Discretionary, $ana],
                ['Saúde', 'Plano de saúde', 'Plano de saúde do casal', 1980.00, 0, 10, Necessity::Essential, null],
                ['Saúde', 'Terapia', 'Sessões de terapia', 800.00, 0, 11, Necessity::Essential, $ana],
                ['Saúde', 'Academia', 'Academia', 189.90, 0, 7, Necessity::Discretionary, $bruno],
                ['Lazer', 'Cinema', 'Cinema', 80.00, 40, 20, Necessity::Discretionary, null],
                ['Pets', 'Ração', 'Ração e petiscos', 312.80, 8, 13, Necessity::Essential, null],
                ['Vestuário', 'Roupas', 'Roupas', 250.00, 50, 17, Necessity::Discretionary, $ana],
            ];

            foreach ($gastos as [$cat, $subNome, $descricao, $base, $variacaoPct, $dia, $necessidade, $membro]) {
                if ($categorias[$cat] === null) {
                    continue;
                }

                $ruido = $variacaoPct > 0 ? random_int(-$variacaoPct, $variacaoPct) / 100 : 0;
                $valor = round($base * (1 + $ruido), 2);

                ExpenseRecord::create([
                    'member_id' => $membro?->id,
                    'description' => $descricao,
                    'necessity' => $necessidade,
                    'category_id' => $categorias[$cat]->id,
                    'subcategory_id' => $sub($cat, $subNome)?->id,
                    'amount' => number_format($valor, 2, '.', ''),
                    'expense_date' => $mes->setDay($dia),
                    'bank_account_id' => $conta?->id,
                    'created_by_user_id' => $userId,
                ]);
                $despesas++;
            }
        }

        $this->command->newLine();
        $this->command->info(sprintf(
            'Histórico Família Ribeiro: %d receitas e %d despesas em %d meses fechados.',
            $receitas,
            $despesas,
            self::MESES,
        ));
    }
}

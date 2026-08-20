<?php

namespace Database\Seeders;

use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Services\InstallmentService;
use App\Services\InvoiceService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Lançamentos de demonstração do mês corrente — nunca rode em produção.
 *
 * Monta um mês plausível da Família Ribeiro: salários dos dois, despesas
 * da casa distribuídas entre essencial e supérfluo, e uma compra
 * parcelada real passando pelo InstallmentService (não inserida à mão),
 * para que as faturas fechem de verdade.
 */
class CashFlowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $profile = FinancialProfile::where('profile_name', 'Família Ribeiro')->first();

        if ($profile === null) {
            $this->command->error('Rode o DevSeeder e o DemoDataSeeder antes.');

            return;
        }

        app(ProfileContext::class)->set($profile);

        $ana = ProfileMember::where('profile_id', $profile->id)->where('name', 'Ana')->firstOrFail();
        $bruno = ProfileMember::where('profile_id', $profile->id)->where('name', 'Bruno')->firstOrFail();
        $userId = $profile->owner_user_id;

        $hoje = CarbonImmutable::now();
        $mes = $hoje->startOfMonth();

        $this->receitas($ana, $bruno, $mes, $userId);
        $this->despesas($ana, $bruno, $mes, $userId);
        $this->parcelada($ana, $mes, $userId);

        $this->command->newLine();
        $this->command->info(sprintf(
            'Fluxo de caixa: %d receitas e %d despesas em %s.',
            IncomeRecord::count(),
            ExpenseRecord::count(),
            $mes->translatedFormat('F/Y'),
        ));
    }

    private function receitas(ProfileMember $ana, ProfileMember $bruno, CarbonImmutable $mes, string $userId): void
    {
        $salario = IncomeCategory::available()->where('name', 'Salário')->firstOrFail();
        $vr = IncomeCategory::available()->where('name', 'VR/VA')->firstOrFail();
        $dividendos = IncomeCategory::available()->where('name', 'Dividendos')->firstOrFail();

        $conta = BankAccount::where('bank_name', 'Itaú')->first();

        $lancamentos = [
            [$ana, $salario, 'Salário Ana', '12400.00', 5, true],
            [$bruno, $salario, 'Salário Bruno', '9800.00', 5, true],
            [$ana, $vr, 'Vale refeição', '1100.00', 5, true],
            [$bruno, $vr, 'Vale refeição', '980.00', 5, true],
            [null, $dividendos, 'Dividendos da carteira', '742.35', 15, false],
        ];

        foreach ($lancamentos as [$membro, $categoria, $descricao, $valor, $dia, $recorrente]) {
            IncomeRecord::create([
                'member_id' => $membro?->id,
                'category_id' => $categoria->id,
                'description' => $descricao,
                'amount' => $valor,
                'received_date' => $mes->setDay($dia),
                'bank_account_id' => $conta?->id,
                'is_recurring' => $recorrente,
                'created_by_user_id' => $userId,
            ]);
        }
    }

    private function despesas(ProfileMember $ana, ProfileMember $bruno, CarbonImmutable $mes, string $userId): void
    {
        $conta = BankAccount::where('bank_name', 'Itaú')->first();
        $cartao = CreditCard::where('card_name', 'Nubank Ultravioleta')->first();

        // [categoria, subcategoria, descrição, valor, dia, necessidade, membro, no cartão?]
        $lancamentos = [
            ['Habitação', 'Condomínio', 'Condomínio', '1450.00', 5, Necessity::Essential, null, false],
            ['Habitação', 'Luz', 'Energia elétrica', '387.42', 8, Necessity::Essential, null, false],
            ['Habitação', 'Internet', 'Internet fibra', '149.90', 10, Necessity::Essential, null, false],
            ['Habitação', 'Streamings', 'Assinaturas de streaming', '96.70', 12, Necessity::Discretionary, null, true],
            ['Alimentação', 'Supermercado', 'Supermercado do mês', '1842.30', 6, Necessity::Essential, null, true],
            ['Alimentação', 'Restaurantes', 'Jantar de aniversário', '410.00', 14, Necessity::Discretionary, $ana, true],
            ['Alimentação', 'Padaria/café/Doceria', 'Padaria', '218.55', 18, Necessity::Essential, null, true],
            ['Transporte', 'Combustível', 'Combustível', '620.00', 9, Necessity::Essential, $bruno, true],
            ['Transporte', 'Uber', 'Corridas de app', '287.40', 16, Necessity::Discretionary, $ana, true],
            ['Saúde', 'Plano de saúde', 'Plano de saúde do casal', '1980.00', 10, Necessity::Essential, null, false],
            ['Saúde', 'Terapia', 'Sessões de terapia', '800.00', 11, Necessity::Essential, $ana, false],
            ['Saúde', 'Academia', 'Academia', '189.90', 7, Necessity::Discretionary, $bruno, true],
            ['Educação', 'Cursos', 'Curso de inglês', '520.00', 12, Necessity::Investment, $bruno, false],
            ['Lazer', 'Cinema', 'Cinema', '96.00', 20, Necessity::Discretionary, null, true],
            ['Pets', 'Ração', 'Ração e petiscos', '312.80', 13, Necessity::Essential, null, true],
            ['Vestuário', 'Roupas', 'Roupas de inverno', '689.90', 17, Necessity::Discretionary, $ana, true],
        ];

        $invoices = app(InvoiceService::class);

        foreach ($lancamentos as [$cat, $sub, $descricao, $valor, $dia, $necessidade, $membro, $noCartao]) {
            $categoria = ExpenseCategory::available()->where('name', $cat)->first();

            if ($categoria === null) {
                continue;
            }

            $subcategoria = ExpenseSubcategory::available()
                ->where('category_id', $categoria->id)
                ->where('name', $sub)
                ->first();

            $data = $mes->setDay($dia);
            $fatura = ($noCartao && $cartao) ? $invoices->invoiceForPurchase($cartao, $data) : null;

            ExpenseRecord::create([
                'member_id' => $membro?->id,
                'description' => $descricao,
                'necessity' => $necessidade,
                'category_id' => $categoria->id,
                'subcategory_id' => $subcategoria?->id,
                'amount' => $valor,
                'expense_date' => $data,
                'bank_account_id' => $noCartao ? null : $conta?->id,
                'credit_card_id' => $noCartao ? $cartao?->id : null,
                'credit_card_invoice_id' => $fatura?->id,
                'created_by_user_id' => $userId,
            ]);
        }
    }

    /** Compra parcelada real, passando pelo motor — não inserida à mão. */
    private function parcelada(ProfileMember $ana, CarbonImmutable $mes, string $userId): void
    {
        $cartao = CreditCard::where('card_name', 'Itaú Personnalité')->first();
        $categoria = ExpenseCategory::available()->where('name', 'Habitação')->first();

        if ($cartao === null || $categoria === null) {
            return;
        }

        $sub = ExpenseSubcategory::available()
            ->where('category_id', $categoria->id)
            ->where('name', 'Eletrodomésticos')
            ->first();

        app(InstallmentService::class)->create($cartao, [
            'description' => 'Geladeira',
            'total_amount' => '7000.00',
            'installments' => 10,
            'purchase_date' => $mes->setDay(4),
            'necessity' => Necessity::Essential,
            'category_id' => $categoria->id,
            'subcategory_id' => $sub?->id,
            'member_id' => $ana->id,
        ], $userId);
    }
}

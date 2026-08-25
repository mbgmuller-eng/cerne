<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\ProfileMember;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;

/**
 * Contas fixas de demonstração — nunca rode em produção.
 *
 * Deixa o mês em estados variados de propósito: uma paga, uma vencida e
 * as demais pendentes, incluindo duas de valor variável, para que a tela
 * mostre todos os caminhos.
 */
class FixedBillsDemoSeeder extends Seeder
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
        $conta = BankAccount::where('bank_name', 'Itaú')->first();

        $cat = fn (string $nome) => ExpenseCategory::available()->where('name', $nome)->first()?->id;

        // [nome, valor, dia, categoria, variável?, membro]
        $contas = [
            ['Aluguel', '3200.00', 5, 'Habitação', false, null],
            ['Condomínio', '1450.00', 5, 'Habitação', false, null],
            ['Energia elétrica', '380.00', 12, 'Habitação', true, null],
            ['Água', '120.00', 15, 'Habitação', true, null],
            ['Internet fibra', '149.90', 10, 'Habitação', false, null],
            ['Plano de saúde', '1980.00', 10, 'Saúde', false, null],
            ['Escola das crianças', '2400.00', 8, 'Filhos', false, null],
            ['Academia', '189.90', 7, 'Saúde', false, $bruno],
            ['Terapia', '800.00', 20, 'Saúde', false, $ana],
        ];

        foreach ($contas as [$nome, $valor, $dia, $categoria, $variavel, $membro]) {
            FixedBill::create([
                'member_id' => $membro?->id,
                'name' => $nome,
                'amount' => $valor,
                'due_day' => $dia,
                'bank_account_id' => $conta?->id,
                'category_id' => $cat($categoria),
                'is_variable' => $variavel,
            ]);
        }

        $service = app(FixedBillService::class);
        $hoje = CarbonImmutable::now();

        $service->generateForMonth($hoje->year, $hoje->month);
        $service->markOverdue($hoje);

        // Uma paga, para a tela mostrar o estado concluído.
        $paga = \App\Models\FixedBillPayment::query()
            ->whereHas('fixedBill', fn ($q) => $q->where('name', 'Internet fibra'))
            ->first();

        if ($paga !== null) {
            $service->pay($paga, null, $hoje, $profile->owner_user_id);
        }

        $this->command->newLine();
        $this->command->info(sprintf(
            'Contas fixas: %d cadastradas, %d vencimentos no mês.',
            FixedBill::count(),
            \App\Models\FixedBillPayment::count(),
        ));
    }
}

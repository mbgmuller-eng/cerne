<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\CardBrand;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Services\InvoiceService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DevOnlySeeder;
use Illuminate\Database\Seeder;

/**
 * Dados financeiros de demonstração — nunca rode em produção.
 *
 * Monta o cenário do casal Ribeiro com contas, cartões e faturas em
 * situações diferentes (aberta, fechada, paga, vencida), para que as
 * telas tenham o que mostrar.
 *
 * Roda depois do DevSeeder, que cria o perfil e os membros.
 */
class DemoDataSeeder extends Seeder
{
    use DevOnlySeeder;

    public function run(): void
    {
        $this->abortInProduction();

        $profile = FinancialProfile::where('profile_name', 'Família Ribeiro')->first();

        if ($profile === null) {
            $this->command->error('Rode o DevSeeder antes: ele cria o perfil Família Ribeiro.');

            return;
        }

        // O escopo global carimba profile_id nos registros criados aqui.
        app(ProfileContext::class)->set($profile);

        $ana = ProfileMember::where('profile_id', $profile->id)->where('name', 'Ana')->firstOrFail();
        $bruno = ProfileMember::where('profile_id', $profile->id)->where('name', 'Bruno')->firstOrFail();

        $this->accounts($ana, $bruno);
        $this->cardsAndInvoices($ana, $bruno);

        $this->command->newLine();
        $this->command->info('Dados de demonstração criados para Família Ribeiro:');
        $this->command->line('  4 contas bancárias (uma conjunta, uma privada do Bruno)');
        $this->command->line('  3 cartões com faturas em estados diferentes');
    }

    private function accounts(ProfileMember $ana, ProfileMember $bruno): void
    {
        BankAccount::create([
            'member_id' => $ana->id,
            'bank_name' => 'Itaú',
            'account_type' => AccountType::Checking,
            'agency' => '0245',
            'account_number' => '18734-2',
            'current_balance' => '8420.55',
            'color_hex' => '#EA6F13',
        ]);

        BankAccount::create([
            'member_id' => $bruno->id,
            'bank_name' => 'Nubank',
            'account_type' => AccountType::Checking,
            'current_balance' => '3190.00',
            'color_hex' => '#820AD1',
        ]);

        // Conta conjunta: visível aos dois independentemente da privacidade.
        BankAccount::create([
            'member_id' => $ana->id,
            'bank_name' => 'Bradesco',
            'account_type' => AccountType::Savings,
            'agency' => '1122',
            'account_number' => '90011-8',
            'current_balance' => '25000.00',
            'is_joint' => true,
            'color_hex' => '#CC092F',
        ]);

        // Conta privada do Bruno: fora do consolidado por decisão dele.
        BankAccount::create([
            'member_id' => $bruno->id,
            'bank_name' => 'Inter',
            'account_type' => AccountType::DigitalWallet,
            'current_balance' => '1750.30',
            'visible_to_partner' => false,
            'included_in_consolidated' => false,
            'color_hex' => '#FF7A00',
        ]);
    }

    private function cardsAndInvoices(ProfileMember $ana, ProfileMember $bruno): void
    {
        $invoices = app(InvoiceService::class);
        $hoje = CarbonImmutable::now();

        $nubank = CreditCard::create([
            'member_id' => $ana->id,
            'card_name' => 'Nubank Ultravioleta',
            'bank_name' => 'Nubank',
            'card_brand' => CardBrand::Mastercard,
            'credit_limit' => '15000.00',
            'closing_day' => 20,
            'due_day' => 27,
            'last_four_digits' => '4417',
            'color_hex' => '#820AD1',
        ]);

        $itau = CreditCard::create([
            'member_id' => $bruno->id,
            'card_name' => 'Itaú Personnalité',
            'bank_name' => 'Itaú',
            'card_brand' => CardBrand::Visa,
            'credit_limit' => '22000.00',
            'closing_day' => 28,
            'due_day' => 5, // vence no mês seguinte ao fechamento
            'last_four_digits' => '9032',
            'color_hex' => '#EA6F13',
        ]);

        // Cartão conjunto que fecha no último dia do mês — o caso de borda
        // de fevereiro fica visível na tela.
        $conjunto = CreditCard::create([
            'member_id' => $ana->id,
            'card_name' => 'Cartão da Casa',
            'bank_name' => 'Bradesco',
            'card_brand' => CardBrand::Elo,
            'credit_limit' => '8000.00',
            'closing_day' => 31,
            'due_day' => 10,
            'last_four_digits' => '2201',
            'is_joint' => true,
            'color_hex' => '#CC092F',
        ]);

        // Fatura corrente de cada cartão, com valores plausíveis.
        $atual = $invoices->ensureInvoice($nubank, $hoje->year, $hoje->month);
        $atual->update(['total_amount' => '2847.90']);

        $atualItau = $invoices->ensureInvoice($itau, $hoje->year, $hoje->month);
        $atualItau->update(['total_amount' => '4130.25']);

        $atualConjunto = $invoices->ensureInvoice($conjunto, $hoje->year, $hoje->month);
        $atualConjunto->update(['total_amount' => '1265.40']);

        // Uma fatura do mês passado já paga, para mostrar o estado.
        $anterior = $hoje->subMonth();
        $paga = $invoices->ensureInvoice($nubank, $anterior->year, $anterior->month);
        $paga->update(['total_amount' => '3102.10']);

        $contaDaAna = BankAccount::where('bank_name', 'Itaú')->firstOrFail();
        $invoices->pay($paga, $contaDaAna, '3102.10', $anterior->setDay(27));
    }
}

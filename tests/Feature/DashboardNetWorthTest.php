<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Patrimônio investido" (card-manchete da Visão Geral) precisa ser só
 * investimento — saldo de conta bancária é dinheiro parado, não
 * patrimônio investido, e somar os dois ilude a pessoa sobre quanto de
 * fato está investido. Fatura em aberto continua descontada (já foi
 * gasto, mesmo que não pago).
 */
class DashboardNetWorthTest extends TestCase
{
    use RefreshDatabase;

    public function test_patrimonio_investido_nao_soma_saldo_de_conta_bancaria(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_amount' => '10000.00',
        ]);

        BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_balance' => '500000.00',
        ]);

        $patrimonio = app(DashboardService::class)->netWorth();

        self::assertSame('10000.00', $patrimonio['liquido']);
        // O saldo em conta continua disponível pra tela mostrar à parte — só não entra no total.
        self::assertSame('500000.00', $patrimonio['contas']);
    }

    public function test_fatura_em_aberto_continua_descontada_do_patrimonio_investido(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create([
            'current_amount' => '10000.00',
        ]);

        $cartao = CreditCard::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id]);
        CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => now()->year,
            'month' => now()->month,
            'closing_date' => now(),
            'due_date' => now()->addDays(10),
            'total_amount' => '1500.00',
            'status' => InvoiceStatus::Open,
        ]);

        $patrimonio = app(DashboardService::class)->netWorth();

        self::assertSame('8500.00', $patrimonio['liquido']);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}

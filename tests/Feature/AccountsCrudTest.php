<?php

namespace Tests\Feature;

use App\Livewire\Accounts\AccountsIndex;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRUD de conta bancária e cartão. A parte que mais importa: com
 * lançamento vinculado, excluir vira desativar — apagar destruiria o
 * rastro de um lançamento que já aconteceu (mesmo espírito de CLAUDE.md
 * regra 3, "número errado nunca deve parecer certo").
 */
class AccountsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastra_conta_bancaria_nova(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('accountBankName', 'Itaú')
            ->set('accountType', 'checking')
            ->set('accountBalance', '1500.00')
            ->set('accountMemberId', $membro->id)
            ->set('accountColor', '#0F766E')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $conta = BankAccount::withoutProfileScope()->where('bank_name', 'Itaú')->sole();
        self::assertSame($perfil->id, $conta->profile_id);
        self::assertSame($membro->id, $conta->member_id);
        self::assertSame('1500.00', $conta->current_balance);
    }

    public function test_cadastrar_conta_sem_membro_falha(): void
    {
        $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('accountBankName', 'Itaú')
            ->set('accountBalance', '100.00')
            ->set('accountMemberId', '')
            ->call('saveAccount')
            ->assertHasErrors(['accountMemberId']);

        self::assertSame(0, BankAccount::withoutProfileScope()->count());
    }

    public function test_editar_conta_atualiza_os_dados(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['bank_name' => 'Nome Antigo', 'current_balance' => '100.00']);

        Livewire::test(AccountsIndex::class)
            ->call('editAccount', $conta->id)
            ->set('accountBankName', 'Nome Novo')
            ->call('saveAccount')
            ->assertHasNoErrors();

        self::assertSame('Nome Novo', $conta->fresh()->bank_name);
    }

    public function test_excluir_conta_sem_lancamento_apaga_de_verdade(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        Livewire::test(AccountsIndex::class)->call('deleteAccount', $conta->id);

        self::assertNull(BankAccount::withoutProfileScope()->find($conta->id));
    }

    public function test_excluir_conta_com_lancamento_apenas_desativa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['is_active' => true]);
        ExpenseRecord::factory()->create(['profile_id' => $perfil->id, 'bank_account_id' => $conta->id]);

        Livewire::test(AccountsIndex::class)->call('deleteAccount', $conta->id);

        $conta->refresh();
        self::assertFalse($conta->is_active);
        self::assertNotNull(BankAccount::withoutProfileScope()->find($conta->id)); // ainda existe
    }

    /**
     * O caso mais fácil de passar batido: uma conta fixa cadastrada AGORA,
     * pra vencer só no futuro, sem nenhum FixedBillPayment gerado ainda
     * (o cron não rodou). hasActivity() precisa travar mesmo assim — checa
     * a definição (FixedBill.bank_account_id), não as parcelas geradas.
     */
    public function test_conta_fixa_futura_sem_pagamento_gerado_ja_trava_a_conta(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        FixedBill::factory()->for($perfil, 'profile')->create(['bank_account_id' => $conta->id]);

        self::assertSame(0, \App\Models\FixedBillPayment::withoutProfileScope()->count());
        self::assertTrue($conta->hasActivity());

        Livewire::test(AccountsIndex::class)->call('deleteAccount', $conta->id);

        $conta->refresh();
        self::assertFalse($conta->is_active);
        self::assertNotNull(BankAccount::withoutProfileScope()->find($conta->id));
    }

    /** Espelho do teste acima, do lado da receita recorrente prevista. */
    public function test_receita_recorrente_futura_sem_ocorrencia_gerada_ja_trava_a_conta(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        RecurringIncome::factory()->for($perfil, 'profile')->create(['bank_account_id' => $conta->id]);

        self::assertSame(0, \App\Models\RecurringIncomeOccurrence::withoutProfileScope()->count());
        self::assertTrue($conta->hasActivity());

        Livewire::test(AccountsIndex::class)->call('deleteAccount', $conta->id);

        $conta->refresh();
        self::assertFalse($conta->is_active);
    }

    public function test_reativar_conta_volta_a_ficar_ativa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['is_active' => false]);

        Livewire::test(AccountsIndex::class)->call('reactivateAccount', $conta->id);

        self::assertTrue($conta->fresh()->is_active);
    }

    public function test_membro_de_outro_perfil_nao_e_aceito(): void
    {
        $this->criarPerfil();

        $outroPerfil = FinancialProfile::factory()->create();
        $membroDeOutroPerfil = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);

        Livewire::test(AccountsIndex::class)
            ->set('accountBankName', 'Tentativa')
            ->set('accountBalance', '100.00')
            ->set('accountMemberId', $membroDeOutroPerfil->id)
            ->call('saveAccount')
            ->assertHasErrors(['accountMemberId']);

        self::assertSame(0, BankAccount::withoutProfileScope()->where('bank_name', 'Tentativa')->count());
    }

    public function test_cadastra_cartao_novo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('cardName', 'Nubank Roxinho')
            ->set('cardBankName', 'Nubank')
            ->set('cardBrand', 'mastercard')
            ->set('cardLimit', '5000.00')
            ->set('cardClosingDay', 20)
            ->set('cardDueDay', 27)
            ->set('cardMemberId', $membro->id)
            ->call('saveCard')
            ->assertHasNoErrors();

        $cartao = CreditCard::withoutProfileScope()->where('card_name', 'Nubank Roxinho')->sole();
        self::assertSame($perfil->id, $cartao->profile_id);
        self::assertSame('5000.00', $cartao->credit_limit);
    }

    public function test_excluir_cartao_com_fatura_apenas_desativa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        \App\Models\CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => 2026,
            'month' => 8,
            'closing_date' => '2026-08-20',
            'due_date' => '2026-08-27',
            'total_amount' => '100.00',
        ]);

        Livewire::test(AccountsIndex::class)->call('deleteCard', $cartao->id);

        $cartao->refresh();
        self::assertFalse($cartao->is_active);
    }

    public function test_excluir_cartao_sem_fatura_apaga_de_verdade(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        Livewire::test(AccountsIndex::class)->call('deleteCard', $cartao->id);

        self::assertNull(CreditCard::withoutProfileScope()->find($cartao->id));
    }

    public function test_escolher_banco_conhecido_preenche_a_cor_sozinha(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('accountBankName', 'Itaú')
            ->assertSet('accountColor', '#EC7000');
    }

    public function test_banco_desconhecido_nao_mexe_na_cor_ja_escolhida(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('accountColor', '#123456')
            ->set('accountBankName', 'Banco da Esquina Ltda')
            ->assertSet('accountColor', '#123456');
    }

    public function test_cadastrar_conta_com_banco_desconhecido_funciona_e_vira_sugestao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(AccountsIndex::class)
            ->set('accountBankName', 'Cooperativa do Vale')
            ->set('accountType', 'checking')
            ->set('accountBalance', '100.00')
            ->set('accountMemberId', $membro->id)
            ->set('accountColor', '#0F766E')
            ->call('saveAccount')
            ->assertHasNoErrors();

        self::assertTrue(BankAccount::withoutProfileScope()->where('bank_name', 'Cooperativa do Vale')->exists());

        $sugestao = Bank::query()->where('name', 'Cooperativa do Vale')->sole();
        self::assertSame($perfil->id, $sugestao->profile_id);
    }

    public function test_editar_conta_nao_sobrescreve_a_cor_ja_salva(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['bank_name' => 'Itaú', 'color_hex' => '#123456']);

        // editAccount() carrega os dados via PHP, não via wire:model — não
        // deve disparar o updatedAccountBankName() e trocar a cor que a
        // pessoa já tinha escolhido.
        Livewire::test(AccountsIndex::class)
            ->call('editAccount', $conta->id)
            ->assertSet('accountColor', '#123456');
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

<?php

namespace Tests\Feature;

use App\Enums\Necessity;
use App\Enums\RecurrenceType;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\CreditCard;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Salvar alterações" parecia morto ao editar uma conta fixa: o cadastro gravava 0 em mês e dia da semana de conta mensal (campo vazio virava
 * `(int) ''`), o 0 voltava ao abrir a edição e a validação recusava um campo ESCONDIDO, sem mensagem na tela. Aqui: a gravação deixa nulo o que a
 * recorrência não usa, a edição cura o 0 que já estava gravado, a migração limpa a produção, e todo erro de validação aparece num resumo.
 */
class FixedBillEditDueFieldsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private ExpenseCategory $categoria;

    private ExpenseSubcategory $sub;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->categoria = ExpenseCategory::factory()->create(['necessity' => null, 'name' => 'Seguro de Vida']);
        $this->sub = ExpenseSubcategory::factory()->create(['category_id' => $this->categoria->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Uma conta como o cadastro ANTIGO a deixava: com 0 em mês e dia da semana. */
    private function contaComZeros(array $campos = []): FixedBill
    {
        $conta = FixedBill::create(array_merge([
            'name' => 'Azos', 'amount' => '88.78', 'recurrence' => RecurrenceType::Monthly, 'due_day' => 11, 'necessity' => Necessity::Investment,
            'category_id' => $this->categoria->id, 'is_variable' => false,
        ], $campos));
        DB::table('fixed_bills')->where('id', $conta->id)->update(['due_month' => 0, 'due_weekday' => 0]);

        return $conta->fresh();
    }

    // ------------------------------------------------------------------ o defeito

    public function test_conta_mensal_antiga_com_zeros_abre_para_editar_e_salva_sem_erro_escondido(): void
    {
        $conta = $this->contaComZeros();

        Livewire::test(FixedBillsIndex::class)->call('editBill', $conta->id)
            ->assertSet('billDueMonth', '')->assertSet('billDueWeekday', '')->assertSet('billDueDay', '11')
            ->set('billAmount', '95.00')
            ->call('saveBill')->assertHasNoErrors();

        $conta->refresh();
        self::assertSame('95.00', $conta->amount);
        self::assertNull($conta->due_month, 'o zero some na gravação');
        self::assertNull($conta->due_weekday);
        self::assertSame(11, $conta->due_day);
    }

    public function test_cadastrar_conta_mensal_nao_grava_zero_em_mes_nem_dia_da_semana(): void
    {
        Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', 'Streaming')->set('billAmount', '49.90')->set('billDueDay', '5')->set('billNecessity', 'discretionary')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $this->sub->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::query()->sole();
        self::assertSame(5, $conta->due_day);
        self::assertNull($conta->due_month);
        self::assertNull($conta->due_weekday);
    }

    public function test_conta_semanal_guarda_o_domingo_que_e_zero_e_nao_guarda_mes(): void
    {
        Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', 'Feira')->set('billAmount', '80.00')->set('billRecurrence', 'weekly')->set('billDueWeekday', '0')->set('billNecessity', 'discretionary')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $this->sub->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::query()->sole();
        self::assertSame(0, $conta->due_weekday, 'domingo é o dia 0 e vale para conta semanal');
        self::assertNull($conta->due_month);
        self::assertNull($conta->due_day);

        Livewire::test(FixedBillsIndex::class)->call('editBill', $conta->id)->assertSet('billDueWeekday', '0')->call('saveBill')->assertHasNoErrors();
    }

    public function test_conta_anual_guarda_dia_e_mes(): void
    {
        Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', 'IPVA')->set('billAmount', '1500.00')->set('billRecurrence', 'annual')->set('billDueDay', '15')->set('billDueMonth', '3')->set('billNecessity', 'essential')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $this->sub->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::query()->sole();
        self::assertSame([15, 3], [$conta->due_day, $conta->due_month]);
        self::assertNull($conta->due_weekday);

        Livewire::test(FixedBillsIndex::class)->call('editBill', $conta->id)->assertSet('billDueMonth', '3')->assertSet('billDueDay', '15');
    }

    public function test_receita_recorrente_antiga_com_zeros_tambem_edita(): void
    {
        $receita = RecurringIncome::factory()->for($this->perfil, 'profile')->create(['recurrence' => RecurrenceType::Monthly, 'due_day' => 5]);
        DB::table('recurring_incomes')->where('id', $receita->id)->update(['due_month' => 0, 'due_weekday' => 0]);

        Livewire::test(FixedBillsIndex::class)->call('setTab', 'receitas')->call('editIncome', $receita->id)
            ->assertSet('incomeDueMonth', '')->assertSet('incomeDueWeekday', '')
            ->call('saveIncome')->assertHasNoErrors();

        $receita->refresh();
        self::assertNull($receita->due_month);
        self::assertNull($receita->due_weekday);
    }

    // ------------------------------------------------------------------ a limpeza da produção

    public function test_a_migracao_limpa_os_zeros_e_preserva_o_que_a_recorrencia_usa(): void
    {
        $mensal = $this->contaComZeros(['name' => 'Mensal']);
        $semanal = FixedBill::create(['name' => 'Semanal', 'amount' => '10.00', 'recurrence' => RecurrenceType::Weekly, 'due_weekday' => 0, 'necessity' => Necessity::Essential, 'category_id' => $this->categoria->id, 'is_variable' => false]);
        $anual = FixedBill::create(['name' => 'Anual', 'amount' => '10.00', 'recurrence' => RecurrenceType::Annual, 'due_day' => 15, 'due_month' => 6, 'necessity' => Necessity::Essential, 'category_id' => $this->categoria->id, 'is_variable' => false]);
        DB::table('fixed_bills')->where('id', $semanal->id)->update(['due_day' => 0, 'due_month' => 0]);

        (require base_path('database/migrations/2026_12_04_100000_clean_zero_due_fields_on_recurring_items.php'))->up();

        $mensal->refresh();
        self::assertNull($mensal->due_month);
        self::assertNull($mensal->due_weekday);
        self::assertSame(11, $mensal->due_day);

        $semanal->refresh();
        self::assertSame(0, $semanal->due_weekday, 'domingo de conta semanal é dado de verdade');
        self::assertNull($semanal->due_month);
        self::assertNull($semanal->due_day);

        $anual->refresh();
        self::assertSame([15, 6], [$anual->due_day, $anual->due_month]);
    }

    // ------------------------------------------------------------------ o erro deixa de ser invisível

    public function test_o_erro_de_validacao_aparece_num_resumo_junto_do_botao(): void
    {
        Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', '')->set('billAmount', '10.00')->set('billDueDay', '5')->set('billNecessity', 'essential')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $this->sub->id)
            ->call('saveBill')->assertHasErrors('billName')
            ->assertSee('Não foi possível salvar. Confira:')->assertSee('O campo nome é obrigatório.');
    }

    public function test_erro_em_campo_escondido_tambem_aparece_no_resumo(): void
    {
        // Mesmo que um valor sujo chegue a um campo que a recorrência esconde, a pessoa vê a mensagem (antes ficava mudo).
        Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')
            ->set('billName', 'X')->set('billAmount', '10.00')->set('billDueDay', '5')->set('billDueMonth', '0')->set('billNecessity', 'essential')
            ->set('billCategoryId', $this->categoria->id)->set('billSubcategoryId', $this->sub->id)
            ->call('saveBill')->assertHasErrors('billDueMonth')->assertSee('Não foi possível salvar. Confira:')->assertSee('O campo mês deve estar entre 1 e 12.');
    }

    public function test_o_formulario_de_cartao_tambem_mostra_o_resumo_de_erros(): void
    {
        $cartao = CreditCard::factory()->for($this->perfil, 'profile')->create(['card_name' => 'Nubank', 'last_four_digits' => '1234']);

        Livewire::test(AccountsIndex::class)->call('editCard', $cartao->id)->set('cardLimit', '')
            ->call('saveCard')->assertHasErrors('cardLimit')->assertSee('Não foi possível salvar. Confira:')->assertSee('O campo limite é obrigatório.');
    }
}

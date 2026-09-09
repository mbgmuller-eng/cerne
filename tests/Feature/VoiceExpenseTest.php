<?php

namespace Tests\Feature;

use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\VoiceExpenseExtractionService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Falar despesa" (Fluxo de caixa, celular): o navegador transcreve a
 * fala e manda o texto pra CashFlowIndex::processVoiceExpense(), que
 * chama VoiceExpenseExtractionService (mockado aqui — não é o SDK da
 * Anthropic que este teste precisa cobrir) e só PRÉ-PREENCHE o
 * formulário de despesa de sempre. Nada é gravado sozinho — a pessoa
 * ainda revisa e clica em salvar, então o teste cobre a extração até o
 * ponto em que o formulário abre preenchido, não o saveExpense em si
 * (isso já está coberto em CashFlowManualEntryTest).
 */
class VoiceExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_preenche_o_formulario_com_os_dados_sugeridos_pela_ia(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['name' => 'Transporte']);

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn([
                'descricao' => 'Uber',
                'valor' => '23.50',
                'data' => null,
                'necessidade_sugerida' => 'discretionary',
                'categoria_sugerida' => 'Transporte',
            ]);
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', 'gastei 23 e 50 de uber')
            ->assertSet('expenseDescription', 'Uber')
            ->assertSet('expenseAmount', '23.50')
            ->assertSet('expenseNecessity', 'discretionary')
            ->assertSet('expenseCategoryId', $categoria->id)
            ->assertSet('expenseDate', now()->toDateString())
            ->assertSet('showExpenseForm', true);
    }

    public function test_categoria_sugerida_que_nao_bate_com_a_necessidade_e_ignorada(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        // Categoria só válida pra necessidade "investment" — ver getExpenseFormCategoriesProperty().
        ExpenseCategory::factory()->create(['name' => 'Aporte', 'necessity' => 'investment']);

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn([
                'descricao' => 'Gasto qualquer',
                'valor' => '10.00',
                'data' => null,
                'necessidade_sugerida' => 'essential', // não bate com a categoria "Aporte" (só vale pra investment).
                'categoria_sugerida' => 'Aporte',
            ]);
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', 'gastei 10 reais')
            ->assertSet('expenseNecessity', 'essential')
            ->assertSet('expenseCategoryId', ''); // não força uma categoria inválida pra necessidade escolhida.
    }

    public function test_sem_valor_reconhecido_deixa_o_campo_vazio_pro_usuario_preencher(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn([
                'descricao' => 'Gasto',
                'valor' => null,
                'data' => null,
                'necessidade_sugerida' => 'discretionary',
                'categoria_sugerida' => null,
            ]);
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', 'gastei um dinheiro')
            ->assertSet('expenseAmount', '');
    }

    public function test_com_data_mencionada_usa_a_data_extraida(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn([
                'descricao' => 'Farmácia',
                'valor' => '40.00',
                'data' => '2026-01-15',
                'necessidade_sugerida' => 'essential',
                'categoria_sugerida' => null,
            ]);
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', 'paguei 40 na farmácia ontem')
            ->assertSet('expenseDate', '2026-01-15');
    }

    public function test_transcricao_vazia_nao_chama_a_ia_nem_abre_o_formulario(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldNotReceive('extract');
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', '   ')
            ->assertSet('showExpenseForm', false);
    }

    public function test_falha_na_extracao_avisa_e_nao_abre_o_formulario(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        $this->mock(VoiceExpenseExtractionService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andThrow(new \RuntimeException('falha de teste'));
        });

        Livewire::test(CashFlowIndex::class)
            ->call('processVoiceExpense', 'gastei 10 reais')
            ->assertSet('expenseDescription', '')
            ->assertSet('showExpenseForm', false);
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

<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Motivado por um caso real: várias parcelas de previdência privada com
 * valores diferentes (R$ 330, R$ 340...) — a oferta de "aplicar aos
 * duplicados" só casa por descrição + valor exatos (ver
 * CashFlowApplyToDuplicatesTest), então não cobria esse caso. Aqui a
 * pessoa marca as linhas à mão, não importa o valor de cada uma.
 */
class CashFlowBulkEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_em_massa_aplica_necessidade_categoria_e_subcategoria_a_todas_as_selecionadas(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);
        $previdencia = ExpenseSubcategory::factory()->create(['category_id' => $investimentos->id, 'name' => 'Previdência Privada']);

        $boleto1 = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Pagamento de boleto - Icatu Seguros', 'amount' => '330.00', 'category_id' => $categoriaAntiga->id]);
        $boleto2 = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Pagamento de boleto - Icatu Seguros', 'amount' => '340.00', 'category_id' => $categoriaAntiga->id]);
        $boleto3 = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Pagamento de boleto - Icatu Seguros', 'amount' => '330.00', 'category_id' => $categoriaAntiga->id]);
        $naoSelecionado = ExpenseRecord::factory()->for($perfil, 'profile')->create(['description' => 'Outra coisa', 'amount' => '50.00', 'category_id' => $categoriaAntiga->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$boleto1->id, $boleto2->id, $boleto3->id])
            ->call('toggleBulkEditForm')
            ->set('bulkNecessity', 'investment')
            ->set('bulkCategoryId', $investimentos->id)
            ->set('bulkSubcategoryId', $previdencia->id)
            ->call('aplicarEdicaoEmMassa')
            ->assertHasNoErrors()
            ->assertSet('selecionadas', [])
            ->assertSet('showBulkEditForm', false);

        foreach ([$boleto1, $boleto2, $boleto3] as $boleto) {
            $boleto->refresh();
            self::assertSame(Necessity::Investment, $boleto->necessity);
            self::assertSame($investimentos->id, $boleto->category_id);
            self::assertSame($previdencia->id, $boleto->subcategory_id);
        }

        // Valores originais preservados — só a categorização mudou.
        self::assertSame('330.00', $boleto1->amount);
        self::assertSame('340.00', $boleto2->amount);

        self::assertSame($categoriaAntiga->id, $naoSelecionado->fresh()->category_id);
    }

    public function test_editar_em_massa_sem_subcategoria_e_bloqueado_fora_de_investimento(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->set('bulkNecessity', 'essential')
            ->set('bulkCategoryId', $categoria->id)
            ->call('aplicarEdicaoEmMassa')
            ->assertHasErrors(['bulkSubcategoryId']);
    }

    public function test_editar_em_massa_de_investimento_nao_exige_subcategoria(): void
    {
        [$perfil] = $this->criarPerfil();
        $investimentos = ExpenseCategory::factory()->create(['necessity' => Necessity::Investment]);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->set('bulkNecessity', 'investment')
            ->set('bulkCategoryId', $investimentos->id)
            ->call('aplicarEdicaoEmMassa')
            ->assertHasNoErrors();

        $despesa->refresh();
        self::assertSame(Necessity::Investment, $despesa->necessity);
        self::assertNull($despesa->subcategory_id);
    }

    public function test_criar_nova_subcategoria_no_texto_livre_funciona_na_edicao_em_massa(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->set('bulkNecessity', 'essential')
            ->set('bulkCategoryId', $categoria->id)
            ->set('bulkNewSubcategory', 'Subcategoria Em Massa')
            ->call('aplicarEdicaoEmMassa')
            ->assertHasNoErrors();

        $despesa->refresh();
        self::assertNotNull($despesa->subcategory_id);
        self::assertSame('Subcategoria Em Massa', $despesa->subcategory->name);
    }

    /** Despesa de cartão numa fatura já paga não pode ser editada — a edição em massa pula ela em vez de travar tudo. */
    public function test_despesa_em_fatura_ja_paga_e_pulada_e_as_outras_ainda_sao_atualizadas(): void
    {
        [$perfil] = $this->criarPerfil();
        $categoriaAntiga = ExpenseCategory::factory()->create();
        $categoriaNova = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoriaNova = ExpenseSubcategory::factory()->create(['category_id' => $categoriaNova->id]);
        $cartao = CreditCard::factory()->for($perfil, 'profile')->create();
        $fatura = CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => 2026,
            'month' => 8,
            'closing_date' => '2026-08-20',
            'due_date' => '2026-08-27',
            'total_amount' => '330.00',
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'paid_amount' => '330.00',
        ]);
        $despesaTravada = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'category_id' => $categoriaAntiga->id, 'credit_card_id' => $cartao->id, 'credit_card_invoice_id' => $fatura->id,
        ]);
        $despesaLivre = ExpenseRecord::factory()->for($perfil, 'profile')->create(['category_id' => $categoriaAntiga->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesaTravada->id, $despesaLivre->id])
            ->set('bulkNecessity', 'essential')
            ->set('bulkCategoryId', $categoriaNova->id)
            ->set('bulkSubcategoryId', $subcategoriaNova->id)
            ->call('aplicarEdicaoEmMassa')
            ->assertHasNoErrors();

        self::assertSame($categoriaAntiga->id, $despesaTravada->fresh()->category_id);
        self::assertSame($categoriaNova->id, $despesaLivre->fresh()->category_id);
    }

    /** BelongsToProfile já falha fechado: id de outro perfil na lista simplesmente não aparece no whereIn()->get(). */
    public function test_despesa_de_outro_perfil_na_lista_nao_e_afetada(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $categoriaOutroPerfil = ExpenseCategory::factory()->create();
        $despesaDeOutroPerfil = ExpenseRecord::factory()->for($outroPerfil, 'profile')->create(['category_id' => $categoriaOutroPerfil->id]);

        [$perfil] = $this->criarPerfil();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesaDeOutroPerfil->id])
            ->set('bulkNecessity', 'essential')
            ->set('bulkCategoryId', $categoria->id)
            ->set('bulkSubcategoryId', $subcategoria->id)
            ->call('aplicarEdicaoEmMassa')
            ->assertHasNoErrors();

        self::assertSame($categoriaOutroPerfil->id, $despesaDeOutroPerfil->fresh()->category_id);
    }

    public function test_limpar_selecao_esvazia_o_array(): void
    {
        [$perfil] = $this->criarPerfil();
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->call('limparSelecao')
            ->assertSet('selecionadas', []);
    }

    public function test_trocar_o_mes_limpa_a_selecao(): void
    {
        [$perfil] = $this->criarPerfil();
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->call('nextMonth')
            ->assertSet('selecionadas', []);
    }

    public function test_trocar_o_filtro_de_necessidade_limpa_a_selecao(): void
    {
        [$perfil] = $this->criarPerfil();
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create();

        Livewire::test(CashFlowIndex::class)
            ->set('selecionadas', [$despesa->id])
            ->set('necessity', 'essential')
            ->assertSet('selecionadas', []);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}

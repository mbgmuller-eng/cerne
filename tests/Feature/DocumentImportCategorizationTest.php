<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ponta a ponta: regra de categorização casando com um item do extrato, e o
 * caso que motivou tudo isso — o PIX semanal pra diarista dando baixa
 * sozinho na conta fixa, sem duplicar se o mesmo extrato subir de novo.
 */
class DocumentImportCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_casado_com_conta_fixa_pendente_da_baixa_ao_confirmar(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_balance' => '1000.00']);
        $categoria = ExpenseCategory::factory()->create(['name' => 'Habitação']);
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->weekly(6)->create([
            'name' => 'Diarista', 'amount' => '230.00', 'category_id' => $categoria->id, 'bank_account_id' => $conta->id,
        ]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $categoria->id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => $pagamento->due_date->addDay()->toDateString(), 'descricao' => 'PIX ADRIANA RODRIGUES', 'valor' => '230.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id);
        self::assertSame($pagamento->id, $component->get('fixedBillPaymentPorItem')[0]);
        self::assertStringContainsString('Diarista', $component->get('notaPorItem')[0]);
        self::assertContains(0, $component->get('aceitos'));

        $component->call('confirmar')->assertHasNoErrors();

        // FixedBillService::pay() cria o lançamento sem source_document_id
        // (não conhece o conceito de documento — é o mesmo método usado
        // pelo pagamento manual em Contas Fixas); confere pelo total.
        self::assertSame(1, ExpenseRecord::count());
        self::assertSame(FixedBillPaymentStatus::Paid, $pagamento->fresh()->status);
        self::assertSame('770.00', $conta->fresh()->current_balance); // 1000 - 230
    }

    public function test_segunda_importacao_da_mesma_semana_ja_paga_nao_duplica_nem_debita_de_novo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_balance' => '1000.00']);
        $categoria = ExpenseCategory::factory()->create(['name' => 'Habitação']);
        $contaFixa = FixedBill::factory()->for($perfil, 'profile')->weekly(6)->create([
            'name' => 'Diarista', 'amount' => '230.00', 'category_id' => $categoria->id, 'bank_account_id' => $conta->id,
        ]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $pagamento = FixedBillPayment::where('fixed_bill_id', $contaFixa->id)->orderBy('due_date')->first();
        app(FixedBillService::class)->pay($pagamento, '230.00', $pagamento->due_date, $membro->user_id);
        self::assertSame('770.00', $conta->fresh()->current_balance);

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'ADRIANA', 'category_id' => $categoria->id, 'fixed_bill_id' => $contaFixa->id,
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => $pagamento->due_date->addDay()->toDateString(), 'descricao' => 'PIX ADRIANA RODRIGUES', 'valor' => '230.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id);
        self::assertStringContainsString('Já dado baixa', $component->get('notaPorItem')[0]);
        self::assertNotContains(0, $component->get('aceitos'));

        // A pessoa força a marcação mesmo com o aviso.
        $component->set('aceitos', [0])->call('confirmar')->assertHasNoErrors();

        // Vira despesa solta (pra manter o extrato completo), mas SEM
        // debitar de novo — o dinheiro já saiu quando a conta fixa foi paga.
        self::assertSame(1, ExpenseRecord::where('source_document_id', $documento->id)->count());
        self::assertNull(ExpenseRecord::where('source_document_id', $documento->id)->sole()->bank_account_id);
        self::assertSame('770.00', $conta->fresh()->current_balance);
        self::assertSame(1, ExpenseRecord::where('description', 'Diarista')->count()); // só o pagamento original da conta fixa
    }

    public function test_regra_sem_conta_fixa_vinculada_so_aplica_categorizacao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['current_balance' => '1000.00']);
        $categoriaPadrao = ExpenseCategory::factory()->create(['name' => 'Outros']);
        $categoriaRegra = ExpenseCategory::factory()->create(['name' => 'Transporte']);
        $subcategoria = ExpenseSubcategory::create([
            'profile_id' => $perfil->id, 'category_id' => $categoriaRegra->id, 'name' => 'Aplicativo',
            'is_customizada' => false, 'is_active' => true,
        ]);

        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'UBER', 'category_id' => $categoriaRegra->id, 'subcategory_id' => $subcategoria->id,
            'necessity' => Necessity::Discretionary,
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'UBER TRIP 123', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->assertSee('Categorizado pela regra "UBER"', false)
            ->call('confirmar')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::where('source_document_id', $documento->id)->sole();
        self::assertSame($categoriaRegra->id, $lancamento->category_id);
        self::assertSame($subcategoria->id, $lancamento->subcategory_id);
        self::assertSame(Necessity::Discretionary, $lancamento->necessity);
        self::assertNotSame($categoriaPadrao->id, $lancamento->category_id);
    }

    /**
     * Sem regra que bata, o item chega na revisão sem necessidade,
     * categoria nem subcategoria — a tela precisa avisar que falta
     * categorizar, pra pessoa corrigir antes de confirmar (categorização
     * completa virou obrigatória pra despesa importada).
     */
    public function test_item_sem_regra_que_bata_mostra_aviso_de_categorizacao_faltando(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'PAGAMENTO SEM REGRA', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->assertSee('Falta categorizar');
    }

    /** Categorização incompleta bloqueia mesmo que a pessoa tente confirmar assim mesmo — não é só um lembrete visual. */
    public function test_confirmar_e_bloqueado_quando_falta_categorizar_item_sem_regra(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'PAGAMENTO SEM REGRA', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->call('confirmar')
            ->assertHasErrors('confirmar');

        self::assertSame(0, ExpenseRecord::where('source_document_id', $documento->id)->count());
    }

    /** Depois de categorizar manualmente (sem regra nenhuma), a importação segue normal. */
    public function test_confirmar_funciona_apos_categorizar_manualmente_o_item_sem_regra(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'PAGAMENTO SEM REGRA', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::where('source_document_id', $documento->id)->sole();
        self::assertSame($categoria->id, $lancamento->category_id);
        self::assertSame($subcategoria->id, $lancamento->subcategory_id);
        self::assertSame(Necessity::Essential, $lancamento->necessity);
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function criarExtrato(FinancialProfile $perfil, ProfileMember $membro, BankAccount $conta, array $itens): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'member_id' => $membro->id,
            'bank_account_id' => $conta->id,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => ['itens' => $itens],
        ]);
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

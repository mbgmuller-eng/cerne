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
use App\Models\IncomeCategorizationRule;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
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

    /** Sem subcategoria existente que sirva, a pessoa cria uma nova na hora — mesmo caminho de texto livre do Fluxo de Caixa. */
    public function test_criar_nova_subcategoria_no_texto_livre_satisfaz_a_categorizacao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'PAGAMENTO SEM REGRA', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('novaSubcategoriaPorItem.0', 'Subcategoria Nova')
            ->call('confirmar')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::where('source_document_id', $documento->id)->sole();
        self::assertNotNull($lancamento->subcategory_id);
        self::assertSame('Subcategoria Nova', $lancamento->subcategory->name);
    }

    /**
     * Motivado por um caso real: extrato com mais de um gasto pra uma
     * subcategoria que ainda não existe — antes, a pessoa só via a
     * subcategoria nova no <select> depois de confirmar a importação
     * inteira (ela só nascia no commit). Criando na hora (wire:blur), ela
     * já aparece pra escolher no item seguinte, sem esperar nada.
     */
    public function test_criar_subcategoria_ao_sair_do_campo_fica_disponivel_pros_outros_itens(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'PRESENTE ANIVERSARIO 1', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-12', 'descricao' => 'PRESENTE ANIVERSARIO 2', 'valor' => '45.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('novaSubcategoriaPorItem.0', 'Presentes')
            ->call('criarSubcategoriaAgora', 0);

        $subcategoria = ExpenseSubcategory::where('name', 'Presentes')->where('category_id', $categoria->id)->sole();

        // Já foi selecionada pro próprio item, e o texto livre foi limpo.
        self::assertSame($subcategoria->id, $component->get('subcategoriaPorItem')[0]);
        self::assertSame('', $component->get('novaSubcategoriaPorItem')[0]);

        // Escolhendo a mesma categoria no item seguinte, "Presentes" já
        // aparece como opção no <select> — sem precisar digitar de novo.
        $component->set('categoriaPorItem.1', $categoria->id)
            ->assertSee('Presentes');
    }

    /**
     * Motivado por um caso real: extrato de agosto reimportado num mês que
     * já tinha bastante coisa lançada — mesma conta, mesma data, mesmo
     * valor de um lançamento que já existe é sinal forte de duplicata.
     * Desmarca por padrão, mas não bloqueia (a pessoa pode ter duas
     * compras iguais no mesmo dia de verdade).
     */
    public function test_item_com_mesma_conta_data_e_valor_de_despesa_existente_mostra_aviso_e_desmarca(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Supermercado ABC',
            'bank_account_id' => $conta->id,
            'expense_date' => '2026-08-20',
            'amount' => '87.50',
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-08-20', 'descricao' => 'Compra no débito autorizada - Supermercado ABC', 'valor' => '87.50', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->assertSee('Possível duplicata', false)
            ->assertSee('Supermercado ABC', false);

        self::assertNotContains(0, $component->get('aceitos'));
    }

    public function test_item_sem_lancamento_parecido_nao_mostra_aviso_de_duplicata(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-08-20', 'descricao' => 'Compra inédita', 'valor' => '87.50', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->assertDontSee('Possível duplicata');

        self::assertContains(0, $component->get('aceitos'));
    }

    /** Marcar de volta um item apontado como duplicata ainda permite importar — o aviso é uma sugestão, não um bloqueio. */
    public function test_forcar_a_marcacao_de_um_item_apontado_como_duplicata_ainda_importa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);
        ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Compra legítima duas vezes no mesmo dia',
            'bank_account_id' => $conta->id,
            'expense_date' => '2026-08-20',
            'amount' => '30.00',
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-08-20', 'descricao' => 'Compra legítima duas vezes no mesmo dia', 'valor' => '30.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('aceitos', [0])
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors();

        self::assertSame(2, ExpenseRecord::where('bank_account_id', $conta->id)->count());
    }

    /** Mesma checagem do lado receita — mesma conta, data e valor de uma receita já lançada. */
    public function test_item_de_receita_com_mesma_conta_data_e_valor_mostra_aviso_de_duplicata(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        IncomeRecord::factory()->for($perfil, 'profile')->create([
            'description' => 'Salário',
            'bank_account_id' => $conta->id,
            'received_date' => '2026-08-05',
            'amount' => '5000.00',
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-08-05', 'descricao' => 'Crédito PIX Empresa X', 'valor' => '5000.00', 'tipo' => 'receita', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->assertSee('Possível duplicata', false);

        self::assertNotContains(0, $component->get('aceitos'));
    }

    /**
     * Motivado por um caso real: PIX mensal pra si mesmo, sem regra
     * nenhuma que bata — marca "criar regra também" na hora de revisar e
     * confirmar já deixa a regra pronta pra próxima importação, sem
     * precisar ir até a tela de Regras depois.
     */
    public function test_criar_regra_tambem_ao_confirmar_cadastra_a_regra_de_despesa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-24', 'descricao' => 'PIX ENVIADO MARCELO MULLER', 'valor' => '199.58', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', 'PIX ENVIADO MARCELO')
            ->set('regraValorExatoPorItem.0', true)
            ->call('confirmar')
            ->assertHasNoErrors();

        $regra = ExpenseCategorizationRule::sole();
        self::assertSame('PIX ENVIADO MARCELO', $regra->pattern);
        self::assertSame('199.58', $regra->amount);
        self::assertSame($categoria->id, $regra->category_id);
        self::assertSame($subcategoria->id, $regra->subcategory_id);
        self::assertSame(Necessity::Essential, $regra->necessity);
    }

    public function test_criar_regra_sem_travar_valor_deixa_amount_nulo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-24', 'descricao' => 'UBER TRIP 123', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'discretionary')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', 'UBER')
            ->call('confirmar')
            ->assertHasNoErrors();

        self::assertNull(ExpenseCategorizationRule::sole()->amount);
    }

    /** Marcar "criar regra" pré-preenche o padrão com a descrição (ver test_criar_regra_tambem_ao_confirmar_cadastra_a_regra_de_despesa) — o bloqueio cobre quem apaga esse texto de propósito e tenta confirmar mesmo assim. */
    public function test_criar_regra_marcado_com_padrao_apagado_bloqueia_o_confirmar(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-24', 'descricao' => 'UBER TRIP 123', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'discretionary')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', '')
            ->call('confirmar')
            ->assertHasErrors('confirmar');

        self::assertSame(0, ExpenseCategorizationRule::count());
        self::assertSame(0, ExpenseRecord::count());
    }

    /** Padrão já usado por outra regra: updateOrCreate atualiza em vez de duplicar (padrão é único por perfil). */
    public function test_criar_regra_com_padrao_ja_existente_atualiza_a_regra_em_vez_de_duplicar(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoriaAntiga = ExpenseCategory::factory()->create(['necessity' => null]);
        $categoriaNova = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoriaNova = ExpenseSubcategory::factory()->create(['category_id' => $categoriaNova->id]);
        ExpenseCategorizationRule::factory()->for($perfil, 'profile')->create([
            'pattern' => 'UBER', 'category_id' => $categoriaAntiga->id,
        ]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-24', 'descricao' => 'UBER TRIP 123', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'discretionary')
            ->set('categoriaPorItem.0', $categoriaNova->id)
            ->set('subcategoriaPorItem.0', $subcategoriaNova->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', 'UBER')
            ->call('confirmar')
            ->assertHasNoErrors();

        $regra = ExpenseCategorizationRule::sole();
        self::assertSame($categoriaNova->id, $regra->category_id);
    }

    public function test_criar_regra_tambem_funciona_pra_receita(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = IncomeCategory::factory()->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-05', 'descricao' => 'REEMBOLSO EMPRESA X', 'valor' => '500.00', 'tipo' => 'receita', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', 'REEMBOLSO')
            ->set('regraValorExatoPorItem.0', true)
            ->call('confirmar')
            ->assertHasNoErrors();

        $regra = IncomeCategorizationRule::sole();
        self::assertSame('REEMBOLSO', $regra->pattern);
        self::assertSame('500.00', $regra->amount);
        self::assertSame($categoria->id, $regra->category_id);
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

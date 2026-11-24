<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\Debt;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Na revisão do extrato, "Criar regra de categorização também" pode ligar a regra a uma conta fixa: a linha de agora dá baixa no vencimento mais
 * próximo da conta (sugerido, e confirmado ao importar) e as próximas importações com o mesmo texto também. O quadro "Ao salvar" diz isso.
 */
class DocumentImportRuleFixedBillTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private BankAccount $conta;

    private ExpenseCategory $categoria;

    private ExpenseSubcategory $subcategoria;

    private FixedBill $aluguel;

    private FixedBillPayment $vencimento;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '5000.00']);
        $this->categoria = ExpenseCategory::factory()->create(['name' => 'Habitação', 'necessity' => null]);
        $this->subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $this->categoria->id]);

        $this->aluguel = FixedBill::factory()->for($this->perfil, 'profile')->create([
            'name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 25, 'category_id' => $this->categoria->id, 'bank_account_id' => $this->conta->id,
        ]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        $this->vencimento = FixedBillPayment::query()->where('fixed_bill_id', $this->aluguel->id)->sole();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function extrato(array $itens): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id,
            'uploaded_by_user_id' => $this->membro->user_id,
            'member_id' => $this->membro->id,
            'bank_account_id' => $this->conta->id,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => ['itens' => $itens],
        ]);
    }

    private function linhaDoAluguel(string $data = '2026-09-23'): array
    {
        return ['data' => $data, 'descricao' => 'PIX IMOBILIARIA SOL', 'valor' => '1800.00', 'tipo' => 'despesa', 'categoria_sugerida' => null];
    }

    private function preparar(DocumentUpload $documento): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $this->categoria->id)
            ->set('subcategoriaPorItem.0', $this->subcategoria->id)
            ->set('criarRegraPorItem.0', true)
            ->set('regraPatternPorItem.0', 'IMOBILIARIA SOL');
    }

    public function test_a_lista_de_contas_fixas_aparece_junto_da_regra_e_nao_traz_conta_de_divida(): void
    {
        // A dívida ganha a conta fixa dela sozinha (DebtFixedBillSync), e essa quem baixa é a parcela: não pode ser oferecida à regra.
        app(\App\Services\DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Financiamento do carro', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 3,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'category_id' => $this->categoria->id, 'subcategory_id' => $this->subcategoria->id,
        ]);
        $this->assertSame(1, FixedBill::query()->whereNotNull('debt_id')->count());

        $documento = $this->extrato([$this->linhaDoAluguel()]);

        $this->preparar($documento)
            ->assertSee('Ligar à conta fixa')
            ->assertSee('Aluguel')
            ->assertDontSee('Financiamento do carro');
    }

    public function test_escolher_a_conta_fixa_da_baixa_na_linha_e_liga_a_regra(): void
    {
        $documento = $this->extrato([$this->linhaDoAluguel()]);

        $componente = $this->preparar($documento)->set('regraContaFixaPorItem.0', $this->aluguel->id);

        $this->assertSame($this->vencimento->id, $componente->get('fixedBillPaymentPorItem')[0]);
        $this->assertStringContainsString('Vai dar baixa na conta fixa "Aluguel", venc. 25/09.', $componente->get('notaPorItem')[0]);

        $componente->call('confirmar')->assertHasNoErrors();

        $regra = ExpenseCategorizationRule::query()->sole();
        $this->assertSame('IMOBILIARIA SOL', $regra->pattern);
        $this->assertSame($this->aluguel->id, $regra->fixed_bill_id);
        $this->assertSame(FixedBillPaymentStatus::Paid, $this->vencimento->fresh()->status);
        $this->assertSame(1, ExpenseRecord::query()->count());
        $this->assertSame('3200.00', $this->conta->fresh()->current_balance);
    }

    public function test_a_proxima_importacao_com_o_mesmo_texto_ja_da_baixa_pela_regra_criada(): void
    {
        $this->preparar($this->extrato([$this->linhaDoAluguel()]))->set('regraContaFixaPorItem.0', $this->aluguel->id)->call('confirmar');

        app(FixedBillService::class)->generateForMonth(2026, 10);
        $outubro = FixedBillPayment::query()->where('fixed_bill_id', $this->aluguel->id)->forPeriod(2026, 10)->sole();

        $segundo = $this->extrato([['data' => '2026-10-24', 'descricao' => 'PIX IMOBILIARIA SOL', 'valor' => '1800.00', 'tipo' => 'despesa', 'categoria_sugerida' => null]]);

        $componente = Livewire::test(DocumentsIndex::class)->call('revisar', $segundo->id);

        $this->assertSame($outubro->id, $componente->get('fixedBillPaymentPorItem')[0]);
    }

    public function test_sem_escolher_conta_a_regra_so_categoriza_e_nao_perde_a_ligacao_que_ja_tinha(): void
    {
        $existente = ExpenseCategorizationRule::factory()->for($this->perfil, 'profile')->create([
            'pattern' => 'IMOBILIARIA SOL', 'category_id' => $this->categoria->id, 'fixed_bill_id' => $this->aluguel->id,
        ]);

        // A regra já casa e a linha vem com a baixa sugerida; criar a regra de novo, sem escolher conta, não desliga a conta fixa.
        $documento = $this->extrato([$this->linhaDoAluguel()]);
        $this->preparar($documento)->call('confirmar')->assertHasNoErrors();

        $this->assertSame($this->aluguel->id, $existente->fresh()->fixed_bill_id);
        $this->assertSame(1, ExpenseCategorizationRule::query()->count());
    }

    public function test_tirar_a_conta_fixa_ou_a_regra_desfaz_a_baixa_sugerida(): void
    {
        $documento = $this->extrato([$this->linhaDoAluguel()]);

        $componente = $this->preparar($documento)->set('regraContaFixaPorItem.0', $this->aluguel->id);
        $this->assertNotNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);

        $componente->set('regraContaFixaPorItem.0', '');
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);
        $this->assertArrayNotHasKey(0, $componente->get('notaPorItem'));

        $componente->set('regraContaFixaPorItem.0', $this->aluguel->id)->set('criarRegraPorItem.0', false);
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);
        $this->assertSame('', $componente->get('regraContaFixaPorItem')[0]);
    }

    public function test_a_primeira_escolha_pode_chegar_como_a_lista_inteira_e_nao_quebra(): void
    {
        // No navegador, com a lista ainda vazia, o Livewire manda o conjunto (array, sem chave) em vez do campo do item: dava erro 500 antes de tratar.
        $componente = $this->preparar($this->extrato([$this->linhaDoAluguel()]));

        $componente->set('regraContaFixaPorItem', [0 => $this->aluguel->id]);
        $this->assertSame($this->vencimento->id, $componente->get('fixedBillPaymentPorItem')[0]);

        $componente->set('criarRegraPorItem', [0 => false]);
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);
        $this->assertSame('', $componente->get('regraContaFixaPorItem')[0]);
    }

    public function test_tirar_a_escolha_devolve_a_baixa_de_uma_regra_que_ja_existia(): void
    {
        $outra = FixedBill::factory()->for($this->perfil, 'profile')->create(['name' => 'Condomínio', 'amount' => '900.00', 'due_day' => 25, 'category_id' => $this->categoria->id]);
        app(FixedBillService::class)->generateForMonth(2026, 9);
        ExpenseCategorizationRule::factory()->for($this->perfil, 'profile')->create(['pattern' => 'IMOBILIARIA SOL', 'category_id' => $this->categoria->id, 'fixed_bill_id' => $outra->id]);

        $componente = Livewire::test(DocumentsIndex::class)->call('revisar', $this->extrato([$this->linhaDoAluguel()])->id);
        $baixaDaRegra = $componente->get('fixedBillPaymentPorItem')[0];
        $this->assertNotNull($baixaDaRegra);

        $componente->set('criarRegraPorItem.0', true)->set('regraContaFixaPorItem.0', $this->aluguel->id);
        $this->assertSame($this->vencimento->id, $componente->get('fixedBillPaymentPorItem')[0]);

        $componente->set('regraContaFixaPorItem.0', '');
        $this->assertSame($baixaDaRegra, $componente->get('fixedBillPaymentPorItem')[0]);
    }

    public function test_conta_fixa_sem_vencimento_perto_so_liga_a_regra_e_avisa(): void
    {
        FixedBillPayment::query()->delete();

        $componente = $this->preparar($this->extrato([$this->linhaDoAluguel()]))->set('regraContaFixaPorItem.0', $this->aluguel->id);

        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);
        $this->assertStringContainsString('não tem vencimento perto desta data', $componente->get('notaPorItem')[0]);

        $componente->call('confirmar')->assertHasNoErrors();

        $this->assertSame($this->aluguel->id, ExpenseCategorizationRule::query()->sole()->fixed_bill_id);
        $this->assertSame(1, ExpenseRecord::query()->count());
    }

    public function test_vencimento_ja_pago_nao_e_baixado_de_novo(): void
    {
        app(FixedBillService::class)->pay($this->vencimento, '1800.00', $this->vencimento->due_date, $this->membro->user_id);

        $componente = $this->preparar($this->extrato([$this->linhaDoAluguel()]))->set('regraContaFixaPorItem.0', $this->aluguel->id);

        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);
        $this->assertStringContainsString('já está pago', $componente->get('notaPorItem')[0]);
        $this->assertContains(0, $componente->get('aceitos'));
    }

    public function test_receita_e_estorno_nao_ligam_regra_a_conta_fixa(): void
    {
        $documento = $this->extrato([
            ['data' => '2026-09-23', 'descricao' => 'PIX RECEBIDO', 'valor' => '1800.00', 'tipo' => 'receita', 'categoria_sugerida' => null],
            $this->linhaDoAluguel(),
        ]);

        $componente = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id)->set('regraContaFixaPorItem.0', $this->aluguel->id);
        $this->assertSame('', $componente->get('regraContaFixaPorItem')[0]);
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);

        $componente->set('criarRegraPorItem.1', true)->set('regraContaFixaPorItem.1', $this->aluguel->id)->set('estornoPorItem.1', true);
        $this->assertSame('', $componente->get('regraContaFixaPorItem')[1]);
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[1] ?? null);
    }

    public function test_id_de_conta_fixa_de_outro_perfil_ou_inventado_e_recusado(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $alheia = FixedBill::factory()->for($outroPerfil, 'profile')->create(['name' => 'Conta alheia']);

        $componente = $this->preparar($this->extrato([$this->linhaDoAluguel()]))->set('regraContaFixaPorItem.0', $alheia->id);

        $this->assertSame('', $componente->get('regraContaFixaPorItem')[0]);
        $this->assertNull($componente->get('fixedBillPaymentPorItem')[0] ?? null);

        $componente->set('regraContaFixaPorItem.0', 'inventado')->call('confirmar')->assertHasNoErrors();
        $this->assertNull(ExpenseCategorizationRule::query()->sole()->fixed_bill_id);
    }

    public function test_o_ao_salvar_diz_a_regra_a_conta_fixa_e_o_vencimento_da_baixa(): void
    {
        $documento = $this->extrato([$this->linhaDoAluguel()]);

        $this->preparar($documento)
            ->set('regraContaFixaPorItem.0', $this->aluguel->id)
            ->assertSee('Regra "IMOBILIARIA SOL": será criada (ou atualizada) e ligada à conta fixa "Aluguel".')
            ->assertSee('Esta linha dá baixa no vencimento de 25/09, e as próximas importações com esse texto também.');
    }

    public function test_o_ao_salvar_sem_conta_fixa_diz_so_da_categoria_e_sem_vencimento_perto_diz_que_so_as_proximas_dao_baixa(): void
    {
        $documento = $this->extrato([$this->linhaDoAluguel()]);

        $this->preparar($documento)
            ->assertSee('Regra "IMOBILIARIA SOL": será criada (ou atualizada) com a categoria escolhida, e as próximas importações com esse texto já vêm categorizadas.');

        FixedBillPayment::query()->delete();

        $this->preparar($documento)
            ->set('regraContaFixaPorItem.0', $this->aluguel->id)
            ->assertSee('Esta linha não tem vencimento em aberto por perto: só as próximas importações com esse texto dão baixa.');
    }
}

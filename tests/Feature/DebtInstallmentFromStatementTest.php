<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Services\Extraction\DebtInstallmentMatcher;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Parcela de dívida na revisão do extrato bancário: a saída que parece o pagamento de uma parcela vem sugerida e, confirmada,
 * vira a despesa da parcela (na categoria da dívida), ligada a ela, mexendo no saldo uma vez só.
 */
class DebtInstallmentFromStatementTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Marcelo', 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function emprestimo(string $nome = 'Empréstimo pessoal', string $credor = 'Banco Santander', string $valor = '650.00', string $primeira = '2026-10-15'): Debt
    {
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        return app(DebtService::class)->create([
            'kind' => 'loan', 'name' => $nome, 'creditor' => $credor, 'amortization' => 'fixed', 'installment_amount' => $valor, 'term' => 6,
            'first_due_date' => $primeira, 'paid_before' => 0, 'category_id' => $cat->id,
            'subcategory_id' => $cat->subcategories()->where('name', 'Empréstimo')->first()?->id,
        ]);
    }

    private function conta(string $saldo = '5000.00'): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['bank_name' => 'Itaú', 'current_balance' => $saldo]);
    }

    private function extrato(BankAccount $conta, array $itens, DocumentType $tipo = DocumentType::BankStatement): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'uploaded_by_user_id' => $this->membro->user_id, 'member_id' => $this->membro->id,
            'bank_account_id' => $conta->id, 'document_type' => $tipo, 'original_filename' => 'extrato.pdf', 'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed, 'extraction_summary' => ['itens' => $itens],
        ]);
    }

    private function linha(array $sobre = []): array
    {
        return $sobre + ['data' => '2026-10-16', 'descricao' => 'DEBITO PARCELA EMPRESTIMO SANTANDER', 'valor' => '650.00', 'tipo' => 'despesa', 'categoria_sugerida' => null];
    }

    private function matcher(): DebtInstallmentMatcher
    {
        return app(DebtInstallmentMatcher::class);
    }

    private function casar(array $item): array
    {
        $data = CarbonImmutable::parse($item['data']);

        return $this->matcher()->match($this->matcher()->candidates($data, $data), $item['descricao'], $item['valor'], $data);
    }

    // ------------------------------------------------------------------ o reconhecimento

    public function test_valor_igual_e_data_perto_do_vencimento_sugere_a_parcela(): void
    {
        $divida = $this->emprestimo();

        $res = $this->casar($this->linha(['descricao' => 'DEBITO AUTOMATICO']));

        self::assertSame($divida->installments[0]->id, $res['sugerida']->id);
        self::assertStringContainsString('parcela 1 de 6', DebtInstallmentMatcher::label($res['sugerida']));
    }

    public function test_valor_diferente_so_sugere_com_o_nome_do_credor_na_descricao(): void
    {
        $this->emprestimo(valor: '650.00');

        // 12% a mais (multa de atraso): sem pista de texto não casa, com o nome do banco casa
        self::assertNull($this->casar($this->linha(['descricao' => 'DEBITO AUTOMATICO', 'valor' => '728.00']))['sugerida']);
        self::assertNotNull($this->casar($this->linha(['descricao' => 'PARCELA SANTANDER', 'valor' => '728.00']))['sugerida']);
        self::assertNull($this->casar($this->linha(['descricao' => 'PARCELA SANTANDER', 'valor' => '900.00']))['sugerida'], 'diferença grande demais');
    }

    public function test_data_longe_do_vencimento_nao_sugere(): void
    {
        $this->emprestimo(primeira: '2026-12-15');

        self::assertNull($this->casar($this->linha(['data' => '2026-10-16']))['sugerida']);
    }

    public function test_so_parcela_em_aberto_de_divida_ativa_e_considerada(): void
    {
        $divida = $this->emprestimo();
        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-10-14'));

        self::assertNull($this->casar($this->linha())['sugerida'], 'a parcela já foi paga');
    }

    public function test_entre_duas_parcelas_escolhe_a_de_valor_mais_perto(): void
    {
        $this->emprestimo('Carro', 'Banco A', '650.00', '2026-10-15');
        $outra = $this->emprestimo('Moto', 'Banco B', '655.00', '2026-10-15');

        $res = $this->casar($this->linha(['descricao' => 'DEBITO', 'valor' => '655.00']));

        self::assertSame($outra->installments[0]->id, $res['sugerida']->id);
        self::assertCount(2, $res['opcoes']);
    }

    public function test_receita_e_valor_zero_nao_casam(): void
    {
        $this->emprestimo();

        self::assertNull($this->casar($this->linha(['valor' => '0']))['sugerida']);
    }

    // ------------------------------------------------------------------ a revisão

    public function test_a_revisao_ja_vem_sugerida_e_nao_pede_categoria(): void
    {
        $divida = $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha(), $this->linha(['descricao' => 'Mercado', 'valor' => '80.00'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('parcelaDividaPorItem.0', $divida->installments[0]->id)->assertSee('Parcela de dívida');
        $tela->assertSee('Vai dar baixa na parcela 1');
        self::assertFalse($tela->get('itensFaltandoCategoria')[0]);
        self::assertTrue($tela->get('itensFaltandoCategoria')[1]);
        self::assertArrayNotHasKey(1, $tela->get('parcelaDividaPorItem'));
    }

    public function test_a_pessoa_desfaz_a_sugestao_e_a_linha_volta_a_ser_despesa_comum(): void
    {
        $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha()]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->set('parcelaDividaPorItem.0', '');

        self::assertTrue($tela->get('itensFaltandoCategoria')[0]);
    }

    public function test_fatura_e_transferencia_tiram_a_parcela_da_linha(): void
    {
        $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha()]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('marcarPagamentoFatura', 0);
        $tela->assertSet('parcelaDividaPorItem.0', '');
    }

    public function test_fatura_de_cartao_nao_sugere_parcela_de_divida(): void
    {
        $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha()], DocumentType::CreditCardInvoice);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertSame([], $tela->get('parcelaDividaPorItem'));
    }

    // ------------------------------------------------------------------ confirmar

    public function test_confirmar_cria_a_despesa_da_parcela_na_categoria_da_divida_e_liga_as_duas(): void
    {
        $divida = $this->emprestimo();
        $conta = $this->conta('5000.00');
        $doc = $this->extrato($conta, [$this->linha()]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('confirmar')->assertHasNoErrors();

        $parcela = $divida->installments()->first()->fresh();
        self::assertSame(DebtInstallmentStatus::Paid, $parcela->status);
        self::assertNotNull($parcela->expense_id);

        $despesa = ExpenseRecord::query()->sole();
        self::assertSame($despesa->id, $parcela->expense_id);
        self::assertSame($divida->category_id, $despesa->category_id);
        self::assertSame('650.00', (string) $despesa->amount);
        self::assertSame('2026-10-16', $parcela->paid_on->toDateString());
        self::assertSame('4350.00', $conta->fresh()->current_balance, 'o saldo cai uma vez só');
        self::assertSame(1, DebtInstallment::query()->whereNotNull('expense_id')->count());
    }

    public function test_pago_a_mais_com_multa_registra_o_valor_pago(): void
    {
        $divida = $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha(['valor' => '660.00'])]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('confirmar');

        $parcela = $divida->installments()->first()->fresh();
        self::assertSame('660.00', (string) $parcela->paid_amount);
        self::assertSame('650.00', (string) $parcela->amount);
    }

    public function test_se_a_parcela_foi_paga_depois_da_revisao_a_linha_vira_despesa_comum_sem_baixar_de_novo(): void
    {
        $divida = $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha()]);
        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        app(DebtService::class)->pay($divida->installments[0], CarbonImmutable::parse('2026-10-15'));
        $tela->set('categoriaPorItem.0', ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail()->id)->set('necessidadePorItem.0', 'essential')
            ->call('confirmar');

        self::assertSame(2, ExpenseRecord::query()->count(), 'a da baixa anterior e a linha comum');
        self::assertSame(1, DebtInstallment::query()->whereNotNull('expense_id')->count());
    }

    public function test_estorno_nunca_baixa_parcela(): void
    {
        $divida = $this->emprestimo();
        $doc = $this->extrato($this->conta(), [$this->linha()]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->set('estornoPorItem.0', true)->call('confirmar');

        self::assertSame(DebtInstallmentStatus::Open, $divida->installments()->first()->fresh()->status);
    }

    public function test_dividas_de_outro_perfil_nao_sao_sugeridas(): void
    {
        $this->emprestimo();

        $outro = User::factory()->create();
        $perfilB = FinancialProfile::factory()->create(['owner_user_id' => $outro->id]);
        $membroB = ProfileMember::factory()->create(['profile_id' => $perfilB->id, 'user_id' => $outro->id, 'role' => MemberRole::Primary]);
        $this->actingAs($outro);
        app(ProfileContext::class)->set($perfilB, $membroB);

        self::assertNull($this->casar($this->linha())['sugerida']);
    }
}

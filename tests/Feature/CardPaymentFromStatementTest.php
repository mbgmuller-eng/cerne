<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\DocumentCommitService;
use App\Services\Extraction\InvoicePaymentMatcher;
use App\Services\InvoiceService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pagamento de fatura de cartão que aparece numa linha de extrato: reconhecido por texto, valor e data, com baixa na
 * fatura cadastrada (nunca automática: a pessoa confirma na revisão) ou, sem fatura, só o saldo da conta mexe.
 */
class CardPaymentFromStatementTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        ExpenseCategory::factory()->create(['name' => 'Alimentação']);
    }

    private function cartao(string $nome = 'Nubank Roxinho', string $banco = 'Nubank'): CreditCard
    {
        return CreditCard::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')
            ->create(['card_name' => $nome, 'bank_name' => $banco, 'last_four_digits' => '1234']);
    }

    private function fatura(CreditCard $cartao, array $sobre = []): CreditCardInvoice
    {
        return CreditCardInvoice::create($sobre + [
            'profile_id' => $this->perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => 2026, 'month' => 10,
            'closing_date' => '2026-10-02',
            'due_date' => '2026-10-10',
            'total_amount' => '2340.00',
            'status' => InvoiceStatus::Closed,
        ]);
    }

    private function conta(string $saldo = '5000.00'): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => $saldo]);
    }

    private function extrato(BankAccount $conta, array $itens, DocumentType $tipo = DocumentType::BankStatement, array $sobre = []): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create($sobre + [
            'profile_id' => $this->perfil->id,
            'uploaded_by_user_id' => $this->membro->user_id,
            'member_id' => $this->membro->id,
            'bank_account_id' => $conta->id,
            'document_type' => $tipo,
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => ['itens' => $itens],
        ]);
    }

    private function linha(array $sobre = []): array
    {
        return $sobre + ['data' => '2026-10-10', 'descricao' => 'Pagamento fatura cartão Nubank', 'valor' => '2340.00', 'tipo' => 'despesa', 'categoria_sugerida' => null];
    }

    // ---- o casamento

    public function test_valor_data_e_texto_sugerem_a_fatura_aberta(): void
    {
        $fatura = $this->fatura($this->cartao());
        $matcher = app(InvoicePaymentMatcher::class);

        $res = $matcher->match($matcher->invoices(), 'Pagamento fatura cartão Nubank', '2340.00', CarbonImmutable::parse('2026-10-10'));

        self::assertTrue($res['texto']);
        self::assertTrue($res['forte']);
        self::assertSame([$fatura->id], $res['sugeridas']->pluck('id')->all());
        self::assertNull($res['ja_paga']);
    }

    public function test_tolerancia_de_um_real_e_janela_de_datas(): void
    {
        $this->fatura($this->cartao());
        $matcher = app(InvoicePaymentMatcher::class);
        $faturas = $matcher->invoices();

        self::assertCount(1, $matcher->match($faturas, 'Fatura do cartão', '2340.80', CarbonImmutable::parse('2026-10-12'))['sugeridas'], 'R$ 0,80 de diferença');
        self::assertCount(0, $matcher->match($faturas, 'Fatura do cartão', '2342.00', CarbonImmutable::parse('2026-10-12'))['sugeridas'], 'R$ 2,00 de diferença');
        self::assertCount(0, $matcher->match($faturas, 'Fatura do cartão', '2340.00', CarbonImmutable::parse('2026-12-20'))['sugeridas'], 'meses depois do vencimento');
        self::assertCount(0, $matcher->match($faturas, 'Fatura do cartão', '2340.00', CarbonImmutable::parse('2026-09-01'))['sugeridas'], 'antes do fechamento');
    }

    public function test_sem_texto_so_o_valor_exato_perto_do_vencimento_basta(): void
    {
        $this->fatura($this->cartao());
        $matcher = app(InvoicePaymentMatcher::class);
        $faturas = $matcher->invoices();

        self::assertCount(1, $matcher->match($faturas, 'PIX enviado', '2340.00', CarbonImmutable::parse('2026-10-11'))['sugeridas']);
        self::assertCount(0, $matcher->match($faturas, 'PIX enviado', '2340.50', CarbonImmutable::parse('2026-10-11'))['sugeridas'], 'sem texto, valor aproximado não basta');
        self::assertCount(0, $matcher->match($faturas, 'PIX enviado', '2340.00', CarbonImmutable::parse('2026-10-25'))['sugeridas'], 'sem texto, longe do vencimento não basta');
    }

    public function test_fatura_ja_paga_e_reconhecida_e_nao_e_oferecida_para_baixa(): void
    {
        $fatura = $this->fatura($this->cartao(), ['status' => InvoiceStatus::Paid, 'paid_at' => '2026-10-10', 'paid_amount' => '2340.00']);
        $matcher = app(InvoicePaymentMatcher::class);

        $res = $matcher->match($matcher->invoices(), 'Pagamento fatura cartão', '2340.00', CarbonImmutable::parse('2026-10-10'));

        self::assertCount(0, $res['sugeridas']);
        self::assertSame($fatura->id, $res['ja_paga']->id);
    }

    public function test_nome_do_cartao_na_descricao_e_pista_forte_e_pagamento_de_conta_comum_nao(): void
    {
        $this->fatura($this->cartao('Itaú Click', 'Itaú'));
        $matcher = app(InvoicePaymentMatcher::class);
        $faturas = $matcher->invoices();

        self::assertTrue($matcher->textHint('PGTO FATURA ITAU CLICK', $faturas)['forte']);
        self::assertTrue($matcher->textHint('Cartão de crédito', $faturas)['forte']);
        self::assertFalse($matcher->textHint('Pagamento da fatura de energia', $faturas)['forte']);
        self::assertFalse($matcher->textHint('Mercado', $faturas)['texto']);
    }

    public function test_cartao_de_outro_perfil_nunca_e_sugerido(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        $outroCartao = CreditCard::factory()->create(['profile_id' => $outroPerfil->id, 'member_id' => ProfileMember::factory()->create(['profile_id' => $outroPerfil->id])->id]);
        CreditCardInvoice::create([
            'profile_id' => $outroPerfil->id, 'credit_card_id' => $outroCartao->id, 'year' => 2026, 'month' => 10,
            'closing_date' => '2026-10-02', 'due_date' => '2026-10-10', 'total_amount' => '2340.00', 'status' => InvoiceStatus::Closed,
        ]);

        self::assertCount(0, app(InvoicePaymentMatcher::class)->invoices());
    }

    // ---- a revisão

    public function test_a_revisao_ja_vem_com_a_baixa_sugerida_marcada_e_sem_pedir_categoria(): void
    {
        $fatura = $this->fatura($this->cartao());
        $conta = $this->conta();
        $doc = $this->extrato($conta, [$this->linha(), $this->linha(['descricao' => 'Mercado', 'valor' => '80.00', 'data' => '2026-10-11'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('faturaPorItem.0', $fatura->id);
        self::assertContains(0, $tela->get('aceitos'));
        self::assertFalse($tela->get('itensFaltandoCategoria')[0], 'pagamento de fatura não pede categoria');
        self::assertTrue($tela->get('itensFaltandoCategoria')[1], 'o mercado continua pedindo');
        self::assertSame($fatura->id, $tela->get('faturaOpcoesPorItem')[0][0]['id']);
        self::assertTrue($tela->get('faturaOpcoesPorItem')[0][0]['sugerida']);
        $tela->assertSee('Pagamento de fatura de cartão:')->assertSee('Sugerida: Nubank Roxinho');
    }

    public function test_texto_claro_de_cartao_sem_fatura_cadastrada_vira_so_saldo(): void
    {
        $conta = $this->conta();
        $doc = $this->extrato($conta, [$this->linha(['descricao' => 'Cartão de crédito Santander', 'valor' => '999.99'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('faturaPorItem.0', 'sem')->assertSee('Debita a conta, mas não vira despesa');
        self::assertContains(0, $tela->get('aceitos'));
    }

    public function test_texto_fraco_so_mostra_a_caixa_sem_decidir_pela_pessoa(): void
    {
        $conta = $this->conta();
        $doc = $this->extrato($conta, [$this->linha(['descricao' => 'Pagamento da fatura de energia', 'valor' => '210.00'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertNull($tela->get('faturaPorItem')[0] ?? null);
        self::assertTrue($tela->get('itensFaltandoCategoria')[0], 'segue como despesa comum até a pessoa dizer o contrário');
    }

    public function test_fatura_ja_paga_vem_desmarcada_com_aviso(): void
    {
        $this->fatura($this->cartao(), ['status' => InvoiceStatus::Paid, 'paid_at' => '2026-10-10', 'paid_amount' => '2340.00']);
        $doc = $this->extrato($this->conta(), [$this->linha()]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertNotContains(0, $tela->get('aceitos'));
        self::assertStringContainsString('já está marcada como paga', $tela->get('notaPorItem')[0]);
    }

    public function test_a_pessoa_pode_dizer_que_nao_e_pagamento_de_fatura_ou_apontar_uma_linha_comum(): void
    {
        $this->fatura($this->cartao());
        $doc = $this->extrato($this->conta(), [$this->linha(), $this->linha(['descricao' => 'Transferência', 'valor' => '2340.00', 'data' => '2026-10-25'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);
        $tela->set('faturaPorItem.0', '');
        self::assertTrue($tela->get('itensFaltandoCategoria')[0]);

        self::assertArrayNotHasKey(1, $tela->get('faturaPorItem'));
        $tela->call('marcarPagamentoFatura', 1);
        $tela->assertSet('faturaPorItem.1', 'sem');
        self::assertFalse($tela->get('itensFaltandoCategoria')[1]);
    }

    public function test_a_confirmacao_da_revisao_da_baixa_na_fatura_e_nao_cria_despesa(): void
    {
        $fatura = $this->fatura($this->cartao());
        $conta = $this->conta('5000.00');
        $doc = $this->extrato($conta, [$this->linha()]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('confirmar')->assertHasNoErrors();

        $fatura->refresh();
        self::assertSame(InvoiceStatus::Paid, $fatura->status);
        self::assertSame('2340.00', $fatura->paid_amount);
        self::assertSame('2026-10-10', $fatura->paid_at->toDateString());
        self::assertSame($conta->id, $fatura->paid_from_account_id);
        self::assertSame('2660.00', $conta->fresh()->current_balance);
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count(), 'nada vira despesa');

        $doc->refresh();
        self::assertSame(ProcessingStatus::Committed, $doc->processing_status);
        self::assertSame($fatura->id, $doc->card_payment_log[0]['fatura_id']);
        self::assertTrue($doc->card_payment_log[0]['saldo_movido']);
    }

    // ---- o commit

    public function test_pagamento_sem_fatura_debita_a_conta_e_nao_cria_despesa(): void
    {
        $conta = $this->conta('1000.00');
        $doc = $this->extrato($conta, [$this->linha(['valor' => '300.00'])]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['fatura' => [0 => 'sem']]);

        self::assertSame('700.00', $conta->fresh()->current_balance);
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count());
        $log = $doc->fresh()->card_payment_log[0];
        self::assertNull($log['fatura_id']);
        self::assertSame('300.00', $log['valor']);
        self::assertTrue($log['saldo_movido']);
    }

    public function test_fatura_ja_paga_no_commit_nao_paga_nem_debita_de_novo(): void
    {
        $fatura = $this->fatura($this->cartao(), ['status' => InvoiceStatus::Paid, 'paid_at' => '2026-10-09', 'paid_amount' => '2340.00']);
        $conta = $this->conta('1000.00');
        $doc = $this->extrato($conta, [$this->linha()]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['fatura' => [0 => $fatura->id]]);

        self::assertSame('1000.00', $conta->fresh()->current_balance);
        self::assertSame('2026-10-09', $fatura->fresh()->paid_at->toDateString());
        self::assertTrue($doc->fresh()->card_payment_log[0]['ja_estava_paga']);
        self::assertFalse($doc->fresh()->card_payment_log[0]['saldo_movido']);
    }

    public function test_pagar_duas_vezes_a_mesma_fatura_so_debita_uma(): void
    {
        $fatura = $this->fatura($this->cartao());
        $conta = $this->conta('5000.00');
        $servico = app(InvoiceService::class);

        self::assertTrue($servico->payFromStatement($fatura, $conta, '2340.00', CarbonImmutable::parse('2026-10-10')));
        self::assertFalse($servico->payFromStatement($fatura->fresh(), $conta, '2340.00', CarbonImmutable::parse('2026-10-10')));

        self::assertSame('2660.00', $conta->fresh()->current_balance);
    }

    public function test_estorna_o_pagamento_e_o_saldo_volta(): void
    {
        $fatura = $this->fatura($this->cartao());
        $conta = $this->conta('5000.00');
        app(InvoiceService::class)->payFromStatement($fatura, $conta, '2340.00', CarbonImmutable::parse('2026-10-10'));

        app(InvoiceService::class)->unpay($fatura->fresh());

        self::assertSame('5000.00', $conta->fresh()->current_balance);
        self::assertSame(InvoiceStatus::Closed, $fatura->fresh()->status);
    }

    public function test_fatura_que_sumiu_entre_a_revisao_e_a_confirmacao_aborta_tudo(): void
    {
        $conta = $this->conta('1000.00');
        $doc = $this->extrato($conta, [$this->linha(['descricao' => 'Mercado', 'valor' => '50.00', 'categoria_sugerida' => 'Alimentação']), $this->linha()]);

        try {
            app(DocumentCommitService::class)->commit($doc, [0, 1], $this->membro->user_id, ['fatura' => [1 => '00000000-0000-0000-0000-000000000000']]);
            self::fail('deveria recusar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('não existe mais', $e->getMessage());
        }

        self::assertSame('1000.00', $conta->fresh()->current_balance, 'a transação desfez o mercado também');
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count());
    }

    public function test_estorno_nunca_e_tratado_como_pagamento_de_fatura(): void
    {
        $conta = $this->conta('1000.00');
        $doc = $this->extrato($conta, [$this->linha(['descricao' => 'Estorno compra', 'valor' => '100.00'])]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['fatura' => [0 => 'sem'], 'estorno' => [0 => true]]);

        self::assertSame(1, ExpenseRecord::withoutProfileScope()->count());
        self::assertNull($doc->fresh()->card_payment_log);
    }

    // ---- relatório de outro aplicativo

    public function test_relatorio_de_outro_app_com_saldo_desligado_marca_a_fatura_paga_sem_mexer_no_saldo(): void
    {
        $fatura = $this->fatura($this->cartao());
        $conta = $this->conta('1000.00');
        $doc = $this->extrato($conta, [$this->linha()], DocumentType::ExternalReport, ['applies_to_balance' => false]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['fatura' => [0 => $fatura->id]]);

        $fatura->refresh();
        self::assertSame(InvoiceStatus::Paid, $fatura->status);
        self::assertNull($fatura->paid_from_account_id, 'sem conta de origem, estornar depois não devolve saldo que nunca saiu');
        self::assertSame('1000.00', $conta->fresh()->current_balance);
        self::assertFalse($doc->fresh()->card_payment_log[0]['saldo_movido']);

        app(InvoiceService::class)->unpay($fatura->fresh());
        self::assertSame('1000.00', $conta->fresh()->current_balance);
    }

    public function test_relatorio_de_outro_app_com_pagamento_reconhecido_vem_marcado_e_sem_fatura_continua_desmarcado(): void
    {
        $fatura = $this->fatura($this->cartao());
        $doc = $this->extrato($this->conta(), [
            $this->linha(),
            $this->linha(['descricao' => 'Cartão crédito Santander', 'valor' => '777.00', 'data' => '2026-10-11']),
        ], DocumentType::ExternalReport, ['applies_to_balance' => true]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('faturaPorItem.0', $fatura->id)->assertSet('faturaPorItem.1', 'sem');
        self::assertContains(0, $tela->get('aceitos'));
        self::assertNotContains(1, $tela->get('aceitos'), 'como já era: sem fatura que case, segue desmarcada');
    }

    public function test_fatura_em_pdf_nao_ganha_o_atalho_de_pagamento(): void
    {
        $cartao = $this->cartao();
        $doc = $this->extrato($this->conta(), [$this->linha(['descricao' => 'Compra'])], DocumentType::CreditCardInvoice, ['credit_card_id' => $cartao->id, 'bank_account_id' => null]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('marcarPagamentoFatura', 0);

        self::assertSame([], $tela->get('faturaPorItem'));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\DocumentCommitService;
use App\Services\Extraction\OwnTransferMatcher;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Transferência entre contas do próprio perfil na revisão do extrato: reconhecida por texto, nome ou par de valor e data;
 * ao confirmar, só o saldo da conta do extrato mexe e nada vira receita ou despesa.
 */
class OwnTransferFromStatementTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = User::factory()->create(['name' => 'Marcelo Bernardes Muller']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Marcelo Bernardes Muller', 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        ExpenseCategory::factory()->create(['name' => 'Alimentação']);
    }

    private function conta(string $banco, string $saldo = '1000.00'): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['bank_name' => $banco, 'current_balance' => $saldo]);
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
        return $sobre + ['data' => '2026-10-10', 'descricao' => 'Transferência entre contas', 'valor' => '500.00', 'tipo' => 'despesa', 'categoria_sugerida' => null];
    }

    private function reconhecer(BankAccount $conta, array $item): array
    {
        return app(OwnTransferMatcher::class)->prepare($conta->id, [$item])->match($item);
    }

    // ---- o reconhecimento

    public function test_texto_claro_de_transferencia_entre_contas_vem_selecionado(): void
    {
        $res = $this->reconhecer($this->conta('Itaú'), $this->linha());

        self::assertTrue($res['selecionar']);
        self::assertStringContainsString('transferência entre contas', $res['motivo']);
    }

    public function test_pix_com_o_nome_de_um_membro_do_perfil_vem_selecionado_mesmo_com_nome_do_meio_abreviado(): void
    {
        $conta = $this->conta('Itaú');

        self::assertTrue($this->reconhecer($conta, $this->linha(['descricao' => 'PIX ENVIADO MARCELO B MULLER']))['selecionar']);
        self::assertTrue($this->reconhecer($conta, $this->linha(['descricao' => 'TED MARCELO BERNARDES MULLER', 'tipo' => 'receita']))['selecionar']);
    }

    public function test_pix_para_outra_pessoa_ou_nome_sem_via_de_transferencia_nao_e_reconhecido(): void
    {
        $conta = $this->conta('Itaú');

        self::assertFalse($this->reconhecer($conta, $this->linha(['descricao' => 'PIX ENVIADO JOAO MULLER']))['sugerir'], 'só o sobrenome não basta');
        self::assertFalse($this->reconhecer($conta, $this->linha(['descricao' => 'Marcelo Muller - mensalidade']))['sugerir'], 'sem PIX ou TED, o nome sozinho não conta');
        self::assertFalse($this->reconhecer($conta, $this->linha(['descricao' => 'Mercado Muller']))['sugerir']);
    }

    public function test_par_de_valor_e_data_em_outra_conta_so_sugere_sem_selecionar(): void
    {
        $itau = $this->conta('Itaú');
        $nubank = $this->conta('Nubank');
        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $nubank->id,
            'description' => 'Recebido', 'amount' => '500.00', 'received_date' => '2026-10-11']);

        $res = $this->reconhecer($itau, $this->linha(['descricao' => 'Saque', 'valor' => '500.00', 'data' => '2026-10-10']));

        self::assertFalse($res['selecionar']);
        self::assertTrue($res['sugerir']);
        self::assertStringContainsString('Nubank', $res['motivo']);
        self::assertStringContainsString('11/10', $res['motivo']);
    }

    public function test_nome_mais_par_de_valor_seleciona_e_o_par_precisa_ser_de_sentido_oposto_e_ate_tres_dias(): void
    {
        $itau = $this->conta('Itaú');
        $nubank = $this->conta('Nubank');
        // Saída igual na outra conta: não é par de uma SAÍDA.
        ExpenseRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $nubank->id,
            'description' => 'Outra', 'amount' => '500.00', 'expense_date' => '2026-10-10', 'necessity' => 'essential', 'category_id' => ExpenseCategory::first()->id]);
        // Entrada igual, mas quatro dias depois: fora da janela.
        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $nubank->id,
            'description' => 'Longe', 'amount' => '500.00', 'received_date' => '2026-10-14']);

        self::assertFalse($this->reconhecer($itau, $this->linha(['descricao' => 'Saque', 'data' => '2026-10-10']))['sugerir']);

        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $nubank->id,
            'description' => 'Perto', 'amount' => '500.00', 'received_date' => '2026-10-13']);

        $res = $this->reconhecer($itau, $this->linha(['descricao' => 'PIX ENVIADO MARCELO MULLER', 'data' => '2026-10-10']));
        self::assertTrue($res['selecionar']);
        self::assertStringContainsString('uma entrada igual', $res['motivo']);
    }

    public function test_lancamento_da_mesma_conta_nunca_e_par(): void
    {
        $itau = $this->conta('Itaú');
        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $itau->id,
            'description' => 'Na mesma conta', 'amount' => '500.00', 'received_date' => '2026-10-10']);

        self::assertFalse($this->reconhecer($itau, $this->linha(['descricao' => 'Saque']))['sugerir']);
    }

    public function test_categoria_transferencia_de_relatorio_de_outro_app_e_pista_forte(): void
    {
        $res = $this->reconhecer($this->conta('Itaú'), $this->linha(['descricao' => 'Acerto', 'categoria_origem' => 'Transferência']));

        self::assertTrue($res['selecionar']);
    }

    // ---- rótulos de banco com o nome truncado (BTG)

    public function test_nome_truncado_depois_do_traco_com_duas_palavras_ou_mais_vem_selecionado(): void
    {
        $conta = $this->conta('BTG');

        foreach (['ENVIO TRANSFERÊNCIA - Marcelo Bernardes', 'RECEBIMENTO TRANSFERÊNCIA - Marcelo Bernardes Muller', 'ENVIO TRANSFERÊNCIA - Marcelo B D G Müller'] as $descricao) {
            self::assertTrue($this->reconhecer($conta, $this->linha(['descricao' => $descricao]))['selecionar'], $descricao);
        }
    }

    public function test_so_o_primeiro_nome_apenas_sugere_e_com_par_de_valor_seleciona(): void
    {
        $btg = $this->conta('BTG');
        $itau = $this->conta('Itaú');
        $linha = $this->linha(['descricao' => 'TRANSFERÊNCIA A CRÉDITO VIA PIX - Marcelo', 'tipo' => 'receita', 'valor' => '300.00', 'data' => '2026-10-10']);

        $sem = $this->reconhecer($btg, $linha);
        self::assertTrue($sem['sugerir']);
        self::assertFalse($sem['selecionar']);
        self::assertStringContainsString('primeiro nome', $sem['motivo']);

        ExpenseRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'bank_account_id' => $itau->id,
            'description' => 'Saída', 'amount' => '300.00', 'expense_date' => '2026-10-09', 'necessity' => 'essential', 'category_id' => ExpenseCategory::first()->id]);

        $com = $this->reconhecer($btg, $linha);
        self::assertTrue($com['selecionar']);
        self::assertStringContainsString('uma saída igual', $com['motivo']);
    }

    public function test_nome_de_outra_pessoa_nao_e_reconhecido(): void
    {
        $conta = $this->conta('BTG');

        foreach (['ENVIO TRANSFERÊNCIA - Marcelo Silva', 'TED ENVIADA - Happy Viagens', 'ENVIO TRANSFERÊNCIA - Marcelo Bernardes Costa', 'TRANSFERÊNCIA A CRÉDITO VIA PIX - Joana'] as $descricao) {
            self::assertFalse($this->reconhecer($conta, $this->linha(['descricao' => $descricao]))['sugerir'], $descricao);
        }
    }

    public function test_marcar_todas_as_sugeridas_de_uma_vez_e_o_aviso_some(): void
    {
        $doc = $this->extrato($this->conta('BTG'), [
            $this->linha(['descricao' => 'TRANSFERÊNCIA A CRÉDITO VIA PIX - Marcelo', 'tipo' => 'receita', 'valor' => '100.00']),
            $this->linha(['descricao' => 'TRANSFERÊNCIA A CRÉDITO VIA PIX - Marcelo', 'tipo' => 'receita', 'valor' => '200.00']),
            $this->linha(['descricao' => 'ENVIO TRANSFERÊNCIA - Marcelo Bernardes', 'valor' => '50.00']),
            $this->linha(['descricao' => 'Mercado', 'valor' => '80.00']),
        ]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertSame(2, $tela->get('transferenciasSugeridasPendentes'), 'a de nome completo já veio marcada');
        $tela->assertSee('2 linhas parecem transferência entre as suas contas');

        $tela->call('marcarTransferenciasSugeridas');

        self::assertSame(0, $tela->get('transferenciasSugeridasPendentes'));
        self::assertTrue($tela->get('transferenciaPorItem')[0]);
        self::assertTrue($tela->get('transferenciaPorItem')[1]);
        self::assertArrayNotHasKey(3, $tela->get('transferenciaPorItem'), 'o mercado continua despesa comum');
        $tela->assertDontSee('parecem transferência entre as suas contas');
    }

    public function test_marcar_todas_nao_pega_linha_que_ja_e_pagamento_de_fatura(): void
    {
        $doc = $this->extrato($this->conta('BTG'), [$this->linha(['descricao' => 'PIX - Marcelo', 'valor' => '100.00'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);
        $tela->set('transferenciaPorItem.0', false)->call('marcarPagamentoFatura', 0)->call('marcarTransferenciasSugeridas');

        $tela->assertSet('faturaPorItem.0', 'sem');
        self::assertFalse($tela->get('transferenciaPorItem')[0] ?? false);
    }
    // ---- a revisão

    public function test_a_revisao_ja_marca_a_transferencia_e_nao_pede_categoria(): void
    {
        $doc = $this->extrato($this->conta('Itaú'), [$this->linha(), $this->linha(['descricao' => 'Mercado', 'valor' => '80.00'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('transferenciaPorItem.0', true)->assertSee('Transferência entre minhas contas');
        self::assertFalse($tela->get('itensFaltandoCategoria')[0]);
        self::assertTrue($tela->get('itensFaltandoCategoria')[1]);
        self::assertContains(0, $tela->get('aceitos'));
    }

    public function test_entrada_tambem_vira_transferencia_e_nao_pede_nada(): void
    {
        $doc = $this->extrato($this->conta('Itaú'), [$this->linha(['tipo' => 'receita', 'descricao' => 'PIX RECEBIDO MARCELO MULLER'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        $tela->assertSet('transferenciaPorItem.0', true)->assertSee('não vira receita');
    }

    public function test_a_pessoa_desmarca_e_a_linha_volta_a_ser_despesa_comum(): void
    {
        $doc = $this->extrato($this->conta('Itaú'), [$this->linha()]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->set('transferenciaPorItem.0', false);

        self::assertTrue($tela->get('itensFaltandoCategoria')[0]);
    }

    public function test_apontar_a_mao_uma_linha_comum(): void
    {
        $doc = $this->extrato($this->conta('Itaú'), [$this->linha(['descricao' => 'Acerto de contas'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);
        self::assertArrayNotHasKey(0, $tela->get('transferenciaPorItem'));

        $tela->call('marcarTransferencia', 0)->assertSet('transferenciaPorItem.0', true);
        self::assertFalse($tela->get('itensFaltandoCategoria')[0]);
    }

    public function test_transferencia_e_pagamento_de_fatura_se_excluem(): void
    {
        $doc = $this->extrato($this->conta('Itaú'), [$this->linha(['descricao' => 'Acerto'])]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('marcarTransferencia', 0);
        $tela->assertSet('transferenciaPorItem.0', true);

        $tela->call('marcarPagamentoFatura', 0);
        $tela->assertSet('transferenciaPorItem.0', false)->assertSet('faturaPorItem.0', 'sem');

        $tela->set('transferenciaPorItem.0', true);
        $tela->assertSet('faturaPorItem.0', '');
    }

    public function test_confirmar_a_revisao_move_so_o_saldo_e_nao_cria_lancamento(): void
    {
        $itau = $this->conta('Itaú', '1000.00');
        $doc = $this->extrato($itau, [$this->linha(['valor' => '300.00']), $this->linha(['tipo' => 'receita', 'valor' => '50.00', 'descricao' => 'TED MARCELO MULLER'])]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->call('confirmar')->assertHasNoErrors();

        self::assertSame('750.00', $itau->fresh()->current_balance); // 1000 - 300 + 50
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count());
        self::assertSame(0, IncomeRecord::withoutProfileScope()->count());

        $doc->refresh();
        self::assertSame(ProcessingStatus::Committed, $doc->processing_status);
        self::assertSame(['saida', 'entrada'], array_column($doc->transfer_log, 'tipo'));
        self::assertTrue($doc->transfer_log[0]['saldo_movido']);
    }

    // ---- o commit

    public function test_relatorio_de_outro_app_sem_atualizar_saldo_nao_mexe_em_nada(): void
    {
        $itau = $this->conta('Itaú', '1000.00');
        $doc = $this->extrato($itau, [$this->linha()], DocumentType::ExternalReport, ['applies_to_balance' => false]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['transferencia' => [0 => true]]);

        self::assertSame('1000.00', $itau->fresh()->current_balance);
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count());
        self::assertFalse($doc->fresh()->transfer_log[0]['saldo_movido']);
    }

    public function test_estorno_nunca_e_transferencia(): void
    {
        $itau = $this->conta('Itaú', '1000.00');
        $doc = $this->extrato($itau, [$this->linha(['descricao' => 'Estorno compra', 'valor' => '100.00'])]);

        app(DocumentCommitService::class)->commit($doc, [0], $this->membro->user_id, ['transferencia' => [0 => true], 'estorno' => [0 => true]]);

        self::assertSame(1, ExpenseRecord::withoutProfileScope()->count());
        self::assertNull($doc->fresh()->transfer_log);
    }

    public function test_os_dois_extratos_importados_cada_um_acerta_o_seu_saldo_sem_duplicar(): void
    {
        $itau = $this->conta('Itaú', '1000.00');
        $nubank = $this->conta('Nubank', '200.00');
        $saida = $this->extrato($itau, [$this->linha(['valor' => '400.00'])]);
        $entrada = $this->extrato($nubank, [$this->linha(['tipo' => 'receita', 'valor' => '400.00', 'data' => '2026-10-11'])]);
        $servico = app(DocumentCommitService::class);

        $servico->commit($saida, [0], $this->membro->user_id, ['transferencia' => [0 => true]]);
        $servico->commit($entrada, [0], $this->membro->user_id, ['transferencia' => [0 => true]]);

        self::assertSame('600.00', $itau->fresh()->current_balance);
        self::assertSame('600.00', $nubank->fresh()->current_balance);
        self::assertSame(0, ExpenseRecord::withoutProfileScope()->count() + IncomeRecord::withoutProfileScope()->count());
    }

    public function test_linha_de_outro_perfil_nunca_e_par(): void
    {
        $itau = $this->conta('Itaú');
        $outroPerfil = FinancialProfile::factory()->create();
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);
        $contaAlheia = BankAccount::factory()->create(['profile_id' => $outroPerfil->id, 'member_id' => $outroMembro->id]);
        IncomeRecord::factory()->create(['profile_id' => $outroPerfil->id, 'member_id' => $outroMembro->id, 'bank_account_id' => $contaAlheia->id,
            'description' => 'De outro perfil', 'amount' => '500.00', 'received_date' => '2026-10-10']);

        self::assertFalse($this->reconhecer($itau, $this->linha(['descricao' => 'Saque']))['sugerir']);
    }
}

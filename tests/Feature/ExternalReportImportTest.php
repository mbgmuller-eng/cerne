<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\DocumentSchemas;
use App\Services\Extraction\ExternalReportReview;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importar o relatório de outro aplicativo financeiro (Minhas Finanças etc.): a IA lê, a revisão é a do extrato
 * (com pistas por linha e resumo por categoria de origem), a conta é escolhida no envio e o saldo só muda se a
 * pessoa pedir.
 */
class ExternalReportImportTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private BankAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.documents.disk'));
        Queue::fake();
        config(['cerne.ai.api_key' => 'chave-de-teste']);

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['bank_name' => 'Nubank', 'current_balance' => '1000.00']);
    }

    /** @return array<string, mixed> um lançamento como a IA devolve, já normalizado */
    private function item(array $sobre = []): array
    {
        return $sobre + [
            'data' => '2026-08-10', 'data_cadastro' => '2026-08-10', 'descricao' => 'Mercado', 'valor' => '100.00', 'tipo' => 'despesa',
            'categoria_origem' => 'Alimentação', 'subcategoria_origem' => 'Outros', 'conta_origem' => 'Nubank', 'cartao_origem' => '',
            'parcela_atual' => null, 'parcela_total' => null, 'categoria_sugerida' => 'Alimentação',
        ];
    }

    private function relatorio(array $itens, array $sobre = []): DocumentUpload
    {
        return DocumentUpload::create($sobre + [
            'uploaded_by_user_id' => $this->membro->user_id,
            'member_id' => $this->membro->id,
            'bank_account_id' => $this->conta->id,
            'applies_to_balance' => false,
            'document_type' => DocumentType::ExternalReport,
            'original_filename' => 'despesas.pdf',
            'storage_path' => config('cerne.documents.path').'/x.pdf',
            'institution_name' => 'minhas finanças',
            'processing_status' => ProcessingStatus::Completed,
            'records_extracted' => count($itens),
            'extraction_summary' => ['itens' => $itens, 'observacoes' => null],
        ]);
    }

    private function categoria(string $nome, array $sobre = []): ExpenseCategory
    {
        return ExpenseCategory::factory()->create(['name' => $nome] + $sobre);
    }

    // ---- leitura e normalização

    public function test_o_esquema_de_leitura_respeita_o_limite_de_campos_anulaveis_da_api(): void
    {
        $conta = function (mixed $no) use (&$conta): int {
            return is_array($no) ? ((isset($no['type']) && is_array($no['type'])) ? 1 : 0) + array_sum(array_map($conta, $no)) : 0;
        };

        self::assertLessThanOrEqual(16, $conta(DocumentSchemas::for(DocumentType::ExternalReport)));
        self::assertTrue(DocumentType::ExternalReport->isExtractable());
    }

    public function test_normalizar_limpa_o_traco_de_coluna_vazia_e_copia_a_categoria_de_origem(): void
    {
        $dados = ExternalReportReview::normalize(['itens' => [
            ['categoria_origem' => ' Moradia ', 'subcategoria_origem' => '-', 'conta_origem' => 'Nubank', 'cartao_origem' => '-'],
            ['categoria_origem' => '', 'subcategoria_origem' => '', 'conta_origem' => '', 'cartao_origem' => ''],
        ]]);

        self::assertSame('Moradia', $dados['itens'][0]['categoria_origem']);
        self::assertSame('Moradia', $dados['itens'][0]['categoria_sugerida'], 'o extrato bancário já lê este campo');
        self::assertSame('', $dados['itens'][0]['subcategoria_origem']);
        self::assertSame('', $dados['itens'][0]['cartao_origem']);
        self::assertNull($dados['itens'][1]['categoria_sugerida']);
    }

    // ---- conferência do total declarado

    public function test_soma_que_confere_com_o_total_do_relatorio(): void
    {
        $doc = $this->relatorio([$this->item(['valor' => '100.00']), $this->item(['valor' => '50.50'])]);
        $doc->update(['extraction_summary' => $doc->extraction_summary + ['total_declarado' => '150.50']]);

        $r = ExternalReportReview::reconciliation($doc);

        self::assertTrue($r['confere']);
        self::assertSame('150.50', $r['soma']);
        self::assertSame('0.00', $r['diferenca']);
    }

    public function test_soma_que_nao_confere_mostra_a_diferenca(): void
    {
        $doc = $this->relatorio([$this->item(['valor' => '100.00'])]);
        $doc->update(['extraction_summary' => $doc->extraction_summary + ['total_declarado' => '250.00']]);

        $r = ExternalReportReview::reconciliation($doc);

        self::assertFalse($r['confere']);
        self::assertSame('-150.00', $r['diferenca']);
    }

    public function test_sem_total_declarado_nao_ha_o_que_conferir(): void
    {
        self::assertNull(ExternalReportReview::reconciliation($this->relatorio([$this->item()])));
    }

    public function test_relatorio_misto_aceita_o_saldo_liquido_ou_a_soma_de_um_lado(): void
    {
        $itens = [$this->item(['valor' => '300.00']), $this->item(['valor' => '1000.00', 'tipo' => 'receita'])];
        $doc = $this->relatorio($itens);

        foreach (['1300.00', '300.00', '1000.00', '700.00'] as $total) {
            $doc->update(['extraction_summary' => ['itens' => $itens, 'total_declarado' => $total]]);
            self::assertTrue(ExternalReportReview::reconciliation($doc->fresh())['confere'], "total $total");
        }
    }

    // ---- pistas por linha

    public function test_pistas_de_parcela_recorrente_fatura_e_outra_conta(): void
    {
        $doc = $this->relatorio([
            $this->item(['descricao' => 'Acordo Itau 14/48', 'parcela_atual' => 14, 'parcela_total' => 48, 'data_cadastro' => '2025-07-04']),
            $this->item(['descricao' => 'Aluguel casa', 'data_cadastro' => '2025-05-28']),
            $this->item(['descricao' => 'Cartão crédito Nubank']),
            $this->item(['descricao' => 'Copel', 'conta_origem' => 'Minha Carteira']),
            $this->item(['descricao' => 'Mercado']),
        ]);

        $d = ExternalReportReview::hints($doc);

        self::assertSame('14/48', $d[0]['parcela']);
        self::assertFalse($d[0]['recorrente'], 'parcela não é "provável recorrente"');
        self::assertTrue($d[1]['recorrente'], 'cadastrada meses antes do gasto');
        self::assertTrue($d[2]['fatura']);
        self::assertSame('Minha Carteira', $d[3]['outra_conta']);
        self::assertSame([null, false, false, null], [$d[4]['parcela'], $d[4]['recorrente'], $d[4]['fatura'], $d[4]['cartao']]);
        self::assertNull($d[4]['outra_conta']);
    }

    public function test_so_marca_outra_conta_quando_o_relatorio_mistura_contas(): void
    {
        $doc = $this->relatorio([$this->item(['conta_origem' => 'Minha Carteira']), $this->item(['conta_origem' => 'Minha Carteira'])]);

        foreach (ExternalReportReview::hints($doc) as $dica) {
            self::assertNull($dica['outra_conta']);
        }
    }

    public function test_reconhece_pagamento_de_fatura_de_cartao_sem_confundir_com_outras_contas(): void
    {
        foreach (['Cartão crédito Nubank', 'Cartao credito santander', 'Pagamento da fatura', 'Fatura do cartão Itaú'] as $texto) {
            self::assertTrue(ExternalReportReview::looksLikeCardPayment($texto), $texto);
        }

        foreach (['Vivo internet casa', 'Fatura de energia Copel', 'Cartão postal', 'Mercado'] as $texto) {
            self::assertFalse(ExternalReportReview::looksLikeCardPayment($texto), $texto);
        }
    }

    // ---- resumo por categoria de origem

    public function test_grupos_guardam_as_posicoes_originais_e_ignoram_receita_e_o_ja_resolvido(): void
    {
        $doc = $this->relatorio([
            $this->item(['categoria_origem' => 'Moradia', 'valor' => '10.00']),                // 0
            $this->item(['categoria_origem' => 'Alimentação', 'valor' => '20.00']),            // 1
            $this->item(['categoria_origem' => 'Moradia', 'valor' => '30.00']),                // 2
            $this->item(['categoria_origem' => 'Moradia', 'valor' => '99.00', 'tipo' => 'receita']), // 3
            $this->item(['categoria_origem' => 'Alimentação', 'valor' => '40.00']),            // 4 (já resolvido abaixo)
        ]);
        $doc->update(['excluded_item_indices' => [4]]);

        $grupos = ExternalReportReview::groups($doc->fresh());

        self::assertSame(['Alimentação', 'Moradia'], $grupos->pluck('rotulo')->all(), 'em ordem alfabética');
        self::assertSame([1], $grupos[0]['indices']);
        self::assertSame([0, 2], $grupos[1]['indices'], 'as posições são as do relatório, não as do grupo');
        self::assertSame('40.00', $grupos[1]['total']);
    }

    public function test_categoria_de_origem_casa_pelo_nome_ou_por_sinonimo(): void
    {
        $categorias = collect([$this->categoria('Habitação'), $this->categoria('Alimentação'), $this->categoria('Financeiros'), $this->categoria('Transporte')]);

        self::assertSame($categorias[1]->id, ExternalReportReview::suggestCategoryId('alimentacao', $categorias), 'sem acento nem maiúscula');
        self::assertSame($categorias[0]->id, ExternalReportReview::suggestCategoryId('Moradia', $categorias));
        self::assertSame($categorias[2]->id, ExternalReportReview::suggestCategoryId('Pagamentos', $categorias));
        self::assertSame($categorias[3]->id, ExternalReportReview::suggestCategoryId('Veículo', $categorias));
        self::assertNull(ExternalReportReview::suggestCategoryId('Serviços', $categorias), 'sem equivalente: a pessoa decide');
        self::assertNull(ExternalReportReview::suggestCategoryId('', $categorias));
    }

    // ---- envio

    public function test_o_envio_exige_a_conta_e_nao_atualiza_o_saldo_por_padrao(): void
    {
        $tela = Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('despesas.pdf', 100, 'application/pdf'))
            ->set('documentType', 'external_report')
            ->call('enviar')
            ->assertHasErrors('uploadBankAccountId');

        self::assertSame(0, DocumentUpload::count());

        $tela->set('uploadBankAccountId', $this->conta->id)->call('enviar')->assertHasNoErrors();

        $doc = DocumentUpload::sole();
        self::assertSame(DocumentType::ExternalReport, $doc->document_type);
        self::assertSame($this->conta->id, $doc->bank_account_id);
        self::assertFalse($doc->applies_to_balance);
        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_marcar_atualizar_o_saldo_vale_para_aquele_envio(): void
    {
        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('despesas.pdf', 100, 'application/pdf'))
            ->set('documentType', 'external_report')
            ->set('uploadBankAccountId', $this->conta->id)
            ->set('updateBalance', true)
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('updateBalance', false, 'o próximo envio volta ao padrão');

        self::assertTrue(DocumentUpload::sole()->applies_to_balance);
    }

    public function test_extrato_bancario_continua_atualizando_o_saldo(): void
    {
        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->set('documentType', 'bank_statement')
            ->set('uploadBankAccountId', $this->conta->id)
            ->call('enviar')
            ->assertHasNoErrors();

        self::assertTrue(DocumentUpload::sole()->applies_to_balance);
    }

    public function test_limite_diario_de_leituras_de_relatorio_e_descartar_nao_devolve(): void
    {
        config(['cerne.ai.external_imports_per_day' => 2]);
        $primeiro = $this->relatorio([$this->item()]);
        $this->relatorio([$this->item()]);

        Livewire::test(DocumentsIndex::class)
            ->call('descartar', $primeiro->id)
            ->set('arquivo', UploadedFile::fake()->create('despesas.pdf', 100, 'application/pdf'))
            ->set('documentType', 'external_report')
            ->set('uploadBankAccountId', $this->conta->id)
            ->call('enviar')
            ->assertHasErrors('arquivo');

        self::assertSame(2, DocumentUpload::count());
        Queue::assertNothingPushed();
    }

    public function test_o_limite_do_relatorio_nao_afeta_extrato_nem_fatura(): void
    {
        config(['cerne.ai.external_imports_per_day' => 1]);
        $this->relatorio([$this->item()]);

        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->set('documentType', 'bank_statement')
            ->set('uploadBankAccountId', $this->conta->id)
            ->call('enviar')
            ->assertHasNoErrors();
    }

    public function test_descartar_relatorio_apaga_o_arquivo_e_esconde_o_registro(): void
    {
        $doc = $this->relatorio([$this->item()]);
        Storage::disk(config('cerne.documents.disk'))->put($doc->storage_path, 'pdf');

        $tela = Livewire::test(DocumentsIndex::class)->assertSee('despesas.pdf')->call('descartar', $doc->id);

        Storage::disk(config('cerne.documents.disk'))->assertMissing($doc->storage_path);
        self::assertNotNull($doc->fresh()->dismissed_at, 'o registro fica, escondido');
        $tela->assertDontSee('despesas.pdf');
    }

    public function test_o_tipo_aparece_na_tela_importar(): void
    {
        Livewire::test(DocumentsIndex::class)->assertSee('Relatório de outro aplicativo');
    }

    // ---- revisão

    public function test_a_revisao_abre_com_fatura_desmarcada_conta_por_linha_e_categoria_sugerida(): void
    {
        $alimentacao = $this->categoria('Alimentação');
        $doc = $this->relatorio([
            $this->item(['descricao' => 'Mercado']),
            $this->item(['descricao' => 'Cartão crédito Nubank', 'valor' => '3508.60']),
            $this->item(['descricao' => 'Copel', 'conta_origem' => 'Minha Carteira', 'categoria_origem' => 'Moradia']),
            $this->item(['descricao' => 'Padaria']),
        ]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertSame([0, 2, 3], $tela->get('aceitos'), 'o pagamento de fatura vem desmarcado');
        self::assertTrue($tela->get('dicasPorItem')[1]['fatura']);
        self::assertArrayHasKey(2, $tela->get('contaPorItem'), 'linha de outra conta de origem pode apontar para outra conta do Cerne');
        self::assertSame($alimentacao->id, $tela->get('categoriaPorItem')[0], 'Alimentação do outro app casa com Alimentação do Cerne');
        self::assertArrayNotHasKey(2, $tela->get('categoriaPorItem'), 'Moradia não tem equivalente aqui: a pessoa decide');
    }

    public function test_compra_no_cartao_de_credito_vem_desmarcada_com_aviso(): void
    {
        $doc = $this->relatorio([
            $this->item(['descricao' => 'Mercado']),
            $this->item(['descricao' => 'Farmácia', 'conta_origem' => '', 'cartao_origem' => 'Cartão Verde']),
        ]);

        $tela = Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id);

        self::assertSame('Cartão Verde', ExternalReportReview::hints($doc)[1]['cartao']);
        self::assertSame([0], $tela->get('aceitos'), 'a compra no cartão não vira saída da conta sem a pessoa decidir');
        $tela->assertSee('Compra no cartão Cartão Verde');
    }

    public function test_aplicar_grupo_categoriza_o_grupo_inteiro_menos_o_que_uma_regra_ja_decidiu(): void
    {
        $alimentacao = $this->categoria('Alimentação');
        $outra = $this->categoria('Lazer');
        ExpenseCategorizationRule::create(['pattern' => 'Cinema', 'category_id' => $outra->id, 'necessity' => Necessity::Discretionary, 'is_active' => true]);

        $doc = $this->relatorio([
            $this->item(['descricao' => 'Mercado']),
            $this->item(['descricao' => 'Cinema']),
            $this->item(['descricao' => 'Padaria']),
        ]);

        $tela = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->set('grupoCategoria.0', $alimentacao->id)
            ->set('grupoNecessidade.0', Necessity::Essential->value)
            ->call('aplicarGrupo', 0);

        self::assertSame(Necessity::Essential->value, $tela->get('necessidadePorItem')[0]);
        self::assertSame(Necessity::Essential->value, $tela->get('necessidadePorItem')[2]);
        self::assertSame($outra->id, $tela->get('categoriaPorItem')[1], 'a regra "Cinema" manda');
        self::assertSame(Necessity::Discretionary->value, $tela->get('necessidadePorItem')[1]);
    }

    public function test_relatorio_de_outro_app_nao_exige_subcategoria_para_estar_completo(): void
    {
        $alimentacao = $this->categoria('Alimentação');
        $doc = $this->relatorio([$this->item()]);

        $faltando = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->set('grupoNecessidade.0', Necessity::Essential->value)
            ->set('grupoCategoria.0', $alimentacao->id)
            ->call('aplicarGrupo', 0)
            ->viewData('itensFaltandoCategoria');

        self::assertFalse($faltando[0], 'categoria e necessidade bastam');
    }

    public function test_a_tela_mostra_a_conferencia_do_total_e_o_resumo_por_categoria(): void
    {
        $this->categoria('Alimentação');
        $doc = $this->relatorio([$this->item(['valor' => '100.00']), $this->item(['valor' => '50.00'])]);
        $doc->update(['extraction_summary' => $doc->extraction_summary + ['total_declarado' => '150.00']]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->assertSee('confere com o total que o relatório declara')
            ->assertSee('Resumo por categoria do outro aplicativo')
            ->assertSee('2 lançamentos')
            ->assertSee('Origem: Alimentação / Outros');

        $doc->update(['extraction_summary' => array_merge($doc->extraction_summary, ['total_declarado' => '999.00'])]);

        Livewire::test(DocumentsIndex::class)->call('revisar', $doc->id)->assertSee('A soma não confere com o total do relatório');
    }

    // ---- confirmação e saldo

    private function confirmar(DocumentUpload $doc, array $ajustes = []): void
    {
        $alimentacao = ExpenseCategory::query()->where('name', 'Alimentação')->first() ?? $this->categoria('Alimentação');

        $tela = Livewire::test(DocumentsIndex::class)
            ->call('revisar', $doc->id)
            ->set('grupoCategoria.0', $alimentacao->id)
            ->set('grupoNecessidade.0', Necessity::Essential->value)
            ->call('aplicarGrupo', 0);

        foreach ($ajustes as $caminho => $valor) {
            $tela->set($caminho, $valor);
        }

        $tela->call('confirmar')->assertHasNoErrors();
    }

    public function test_confirmar_sem_atualizar_o_saldo_grava_com_a_conta_mas_nao_mexe_nele(): void
    {
        $doc = $this->relatorio([
            $this->item(['descricao' => 'Acordo Itau 14/48', 'valor' => '44.31', 'parcela_atual' => 14, 'parcela_total' => 48]),
            $this->item(['descricao' => 'Mercado', 'valor' => '200.00']),
        ]);

        $this->confirmar($doc);

        self::assertSame('1000.00', $this->conta->fresh()->current_balance, 'o saldo de hoje já reflete agosto');

        $despesas = ExpenseRecord::query()->orderBy('description')->get();
        self::assertCount(2, $despesas);
        foreach ($despesas as $d) {
            self::assertSame($this->conta->id, $d->bank_account_id, 'a conta fica registrada para filtrar');
            self::assertFalse($d->affects_balance);
            self::assertSame($doc->id, $d->source_document_id);
            self::assertSame('2026-08-10', $d->expense_date->toDateString());
        }

        $parcela = $despesas->firstWhere('description', 'Acordo Itau 14/48');
        self::assertSame('Importado de minhas finanças · categoria de origem: Alimentação / Outros · parcela 14/48', $parcela->notes);
        self::assertSame(ProcessingStatus::Committed, $doc->fresh()->processing_status);
    }

    public function test_confirmar_atualizando_o_saldo_baixa_as_despesas_e_soma_as_receitas(): void
    {
        IncomeCategory::factory()->create(['name' => 'Salário']);
        $doc = $this->relatorio([
            $this->item(['valor' => '300.00']),
            $this->item(['descricao' => 'Salário', 'valor' => '1000.00', 'tipo' => 'receita', 'categoria_origem' => 'Salário', 'categoria_sugerida' => 'Salário']),
        ], ['applies_to_balance' => true]);

        $this->confirmar($doc);

        self::assertSame('1700.00', $this->conta->fresh()->current_balance, '1000 - 300 + 1000');
        self::assertTrue(ExpenseRecord::sole()->affects_balance);
        self::assertTrue(IncomeRecord::sole()->affects_balance);
    }

    public function test_linha_de_outra_conta_de_origem_pode_ir_para_outra_conta_do_cerne(): void
    {
        $carteira = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['bank_name' => 'Carteira', 'current_balance' => '50.00']);
        $doc = $this->relatorio([
            $this->item(['descricao' => 'Mercado']),
            $this->item(['descricao' => 'Copel', 'valor' => '50.00', 'conta_origem' => 'Minha Carteira']),
            $this->item(['descricao' => 'Padaria']),
        ], ['applies_to_balance' => true]);

        $this->confirmar($doc, ['contaPorItem.1' => $carteira->id]);

        self::assertSame($carteira->id, ExpenseRecord::where('description', 'Copel')->sole()->bank_account_id);
        self::assertSame($this->conta->id, ExpenseRecord::where('description', 'Mercado')->sole()->bank_account_id);
        self::assertSame('800.00', $this->conta->fresh()->current_balance, '1000 - 100 - 100: só as duas linhas da conta do envio');
        self::assertSame('0.00', $carteira->fresh()->current_balance, '50 - 50: a linha da carteira baixou a carteira');
    }

    public function test_sem_escolher_outra_conta_a_linha_fica_na_conta_do_envio(): void
    {
        $doc = $this->relatorio([$this->item(['descricao' => 'Mercado']), $this->item(['descricao' => 'Copel', 'conta_origem' => 'Minha Carteira']), $this->item(['descricao' => 'Padaria'])]);

        $this->confirmar($doc);

        self::assertSame(3, ExpenseRecord::where('bank_account_id', $this->conta->id)->count());
    }

    public function test_pagamento_de_fatura_desmarcado_nao_e_importado_a_menos_que_a_pessoa_marque(): void
    {
        $doc = $this->relatorio([$this->item(['descricao' => 'Mercado']), $this->item(['descricao' => 'Cartão crédito Nubank', 'valor' => '3508.60'])]);

        $this->confirmar($doc);

        self::assertSame(['Mercado'], ExpenseRecord::pluck('description')->all());
        self::assertSame([1], $doc->fresh()->pendingItemIndices(), 'continua pendente para decidir depois');
    }

    // ---- editar e apagar depois respeitam a decisão do saldo

    public function test_apagar_despesa_importada_sem_saldo_nao_devolve_nada_ao_saldo(): void
    {
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['amount' => '150.00', 'bank_account_id' => $this->conta->id, 'affects_balance' => false]);

        Livewire::test(CashFlowIndex::class)->call('deleteExpense', $despesa->id);

        self::assertNull(ExpenseRecord::find($despesa->id));
        self::assertSame('1000.00', $this->conta->fresh()->current_balance);
    }

    public function test_apagar_despesa_normal_continua_devolvendo_ao_saldo(): void
    {
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['amount' => '150.00', 'bank_account_id' => $this->conta->id]);

        Livewire::test(CashFlowIndex::class)->call('deleteExpense', $despesa->id);

        self::assertSame('1150.00', $this->conta->fresh()->current_balance);
    }

    public function test_editar_despesa_importada_sem_saldo_nao_mexe_no_saldo(): void
    {
        $categoria = $this->categoria('Alimentação');
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'amount' => '100.00', 'bank_account_id' => $this->conta->id, 'affects_balance' => false, 'category_id' => $categoria->id,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->set('expenseAmount', '250.00')
            ->call('saveExpense')
            ->assertHasNoErrors();

        self::assertSame('250.00', $despesa->fresh()->amount);
        self::assertSame('1000.00', $this->conta->fresh()->current_balance);
        self::assertFalse($despesa->fresh()->affects_balance, 'a decisão original continua valendo');
    }

    public function test_apagar_e_editar_receita_importada_sem_saldo_nao_mexe_no_saldo(): void
    {
        $categoria = IncomeCategory::factory()->create();
        $receita = IncomeRecord::factory()->for($this->perfil, 'profile')->create(['amount' => '500.00', 'bank_account_id' => $this->conta->id, 'affects_balance' => false, 'category_id' => $categoria->id]);

        Livewire::test(CashFlowIndex::class)->call('editIncome', $receita->id)->set('incomeAmount', '800.00')->call('saveIncome')->assertHasNoErrors();
        self::assertSame('1000.00', $this->conta->fresh()->current_balance);

        Livewire::test(CashFlowIndex::class)->call('deleteIncome', $receita->id);
        self::assertSame('1000.00', $this->conta->fresh()->current_balance);
    }
}

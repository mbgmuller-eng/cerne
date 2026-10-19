<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\Debts\ConsortiumImport;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Models\Debt;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Services\Extraction\ConsortiumImportService;
use App\Services\Extraction\DocumentSchemas;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívidas: extrato do consórcio por importação (parcelas realmente pagas, puladas, flex em %), parcelas puladas no cadastro manual.
 * Os números do extrato são os de um extrato real da Ademicon (grupo 1020, cota 3503): 31 parcelas vencidas, a 3 e a 12 sem pagamento.
 */
class ConsortiumImportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 19:00:00'));
        $this->seed(TaxonomySeeder::class);
        Storage::fake(config('cerne.documents.disk'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{numero: int, pagamento: string, valor_a_pagar: string, valor_pago: string}> */
    private function parcelasLidas(): array
    {
        $linhas = [
            [1, '2024-04-05', '336.40', '336.40'], [2, '2024-05-15', '382.77', '336.40'], [4, '2024-07-15', '384.27', '384.27'],
            [5, '2024-08-15', '384.27', '430.57'], [6, '2024-09-16', '384.17', '384.17'], [7, '2024-10-15', '384.17', '384.17'],
            [8, '2024-11-25', '384.27', '384.27'], [9, '2024-12-16', '384.17', '384.17'], [10, '2025-01-15', '406.19', '406.19'],
            [11, '2025-02-17', '406.30', '406.30'], [13, '2025-04-15', '406.30', '406.30'], [14, '2025-05-15', '407.96', '407.96'],
            [15, '2025-06-16', '407.96', '407.96'], [16, '2025-07-15', '407.96', '407.96'], [17, '2025-08-15', '407.98', '408.09'],
            [18, '2025-09-15', '407.98', '407.98'], [19, '2025-10-15', '407.98', '407.98'], [20, '2025-11-17', '407.98', '407.98'],
            [21, '2025-12-15', '407.98', '407.98'], [22, '2026-01-15', '433.40', '433.40'], [23, '2026-02-18', '433.40', '433.40'],
            [24, '2026-03-16', '433.40', '433.40'], [25, '2026-04-15', '433.40', '433.40'], [26, '2026-05-15', '433.40', '433.40'],
            [27, '2026-06-15', '433.40', '433.40'], [28, '2026-07-15', '433.40', '433.40'], [29, '2026-08-17', '433.40', '433.40'],
            [30, '2026-09-15', '433.40', '433.40'], [31, '2026-10-15', '433.40', '433.40'],
        ];

        return array_map(fn (array $l) => ['numero' => $l[0], 'pagamento' => $l[1], 'valor_a_pagar' => $l[2], 'valor_pago' => $l[3]], $linhas);
    }

    /** @return array<string, mixed> o item que a leitura por IA devolve para o extrato de exemplo */
    private function itemDoExtrato(): array
    {
        return [
            'grupo' => '001020', 'cota' => '3503-00', 'contrato' => '0000629283', 'administradora' => 'Ademicon', 'tipo_bem' => 'imovel',
            'plano_meses' => 220, 'valor_credito' => '112316.98', 'valor_bem' => '100000.00', 'taxa_adm_percentual' => '24.0000',
            'data_adesao' => '2024-04-05', 'ultimo_reajuste' => '2025-12-20', 'vencimento_atual' => '2026-10-15', 'data_extrato' => '2026-10-16',
            'valor_contribuicao_mensal' => '433.40', 'percentual_fundo_comum_mensal' => '0.2304', 'percentual_seguro' => '0.046371',
            'total_pago' => '11841.10', 'a_pagar_total' => '128238.06', 'parcelas_a_pagar' => 189,
            'parcelas' => $this->parcelasLidas(), 'campos_incertos' => [],
        ];
    }

    private function leitura(array $item = null): DocumentUpload
    {
        return DocumentUpload::create([
            'uploaded_by_user_id' => $this->usuario->id, 'member_id' => $this->membro->id, 'document_type' => DocumentType::ConsortiumStatement,
            'original_filename' => 'extrato.pdf', 'storage_path' => 'documentos/extrato.pdf', 'size_bytes' => 1000,
            'processing_status' => ProcessingStatus::Completed, 'records_extracted' => 1,
            'extraction_summary' => ['itens' => [$item ?? $this->itemDoExtrato()], 'observacoes' => null],
        ]);
    }

    private function servico(): ConsortiumImportService
    {
        return app(ConsortiumImportService::class);
    }

    private function consorcioManual(array $campos = []): Debt
    {
        return app(DebtService::class)->createConsortium(array_merge([
            'consortium_type' => 'property', 'name' => 'Consórcio de imóvel', 'creditor' => 'Ademicon', 'credit_amount' => '100000.00',
            'installment_amount' => '433.40', 'term' => 218, 'first_due_date' => '2024-04-15', 'paid_before' => 29, 'is_flex' => true,
            'adjustment_index' => 'incc', 'adjustment_every_months' => 12, 'adjustment_first_after_months' => 12,
        ], $campos));
    }

    // ------------------------------------------------------------------ a linha de revisão

    public function test_a_revisao_traz_as_parcelas_lidas_as_puladas_e_a_soma_confere_com_o_total_do_extrato(): void
    {
        $linha = $this->servico()->rowFor($this->leitura());

        self::assertSame('001020 / 3503-00', $linha['group']);
        self::assertSame('property', $linha['consortium_type']);
        self::assertSame(220, $linha['term']);
        self::assertSame('112316.98', $linha['credit_amount']);
        self::assertSame('433.40', $linha['installment_amount']);
        self::assertSame(31, $linha['anchor_number']);
        self::assertSame([3, 12], $linha['skipped']);
        self::assertCount(29, $linha['installments']);
        self::assertSame('11841.10', $linha['read_paid']);
        self::assertSame($linha['declared_paid'], $linha['read_paid']);
        self::assertSame('new', $linha['target']);
    }

    public function test_o_flex_e_sugerido_pelo_fundo_comum_pago_e_so_quando_a_conta_fecha(): void
    {
        self::assertSame('50', $this->servico()->suggestFlex(220, '0.2304'));
        self::assertSame('30', $this->servico()->suggestFlex(220, '0.3182'));   // 70% de 0,4545
        self::assertSame('', $this->servico()->suggestFlex(220, '0.4545'), 'parcela cheia: não é flex');
        self::assertSame('', $this->servico()->suggestFlex(220, '0.1000'), 'redução maior que 50%: não sugere');
        self::assertSame('', $this->servico()->suggestFlex(0, '0.2304'));
    }

    public function test_sugere_atualizar_o_cadastro_manual_sem_grupo_em_vez_de_duplicar(): void
    {
        $manual = $this->consorcioManual();

        $linha = $this->servico()->rowFor($this->leitura());

        self::assertSame($manual->id, $linha['target']);
        self::assertSame('Consórcio de imóvel', $linha['name']);
        self::assertSame('incc', $linha['adjustment_index']);
    }

    public function test_reconhece_o_consorcio_ja_importado_pelo_grupo_e_cota(): void
    {
        $this->consorcioManual(['consortium_group' => '1020 / 3503-00', 'name' => 'Ademicon casa']);
        $this->consorcioManual(['name' => 'Outro sem grupo', 'consortium_type' => 'vehicle']);

        self::assertSame('Ademicon casa', $this->servico()->rowFor($this->leitura())['name']);
    }

    // ------------------------------------------------------------------ gravar

    public function test_importar_um_consorcio_novo_grava_o_que_foi_realmente_pago_e_marca_as_puladas(): void
    {
        $leitura = $this->leitura();
        $linha = $this->servico()->rowFor($leitura);
        $linha['adjustment_index'] = 'incc';

        $divida = $this->servico()->commit($leitura, $linha);

        self::assertSame(220, $divida->term);
        self::assertSame(220, $divida->installments()->count());
        self::assertSame(29, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertSame([3, 12], $divida->installments()->where('status', DebtInstallmentStatus::Diluted)->pluck('number')->all());
        self::assertSame(189, $divida->installments()->where('status', DebtInstallmentStatus::Open)->count());
        self::assertSame('433.40', (string) $divida->installments()->where('number', 220)->first()->amount);
        self::assertSame('2024-04-15', $divida->first_due_date->toDateString());
        self::assertSame('2026-10-15', $divida->installments()->where('number', 31)->first()->due_date->toDateString());

        $quinta = $divida->installments()->where('number', 5)->first();
        self::assertSame('430.57', (string) $quinta->paid_amount, 'o valor pago de verdade, não o da parcela');
        self::assertSame('384.27', (string) $quinta->amount);
        self::assertSame('2024-08-15', $quinta->paid_on->toDateString());
        self::assertNull($quinta->expense_id);

        self::assertSame(50, $divida->flex_percent);
        self::assertSame('112316.98', (string) $divida->credit_amount);
        self::assertSame('2026-12-20', $divida->next_adjustment_on->toDateString(), 'um ano depois do último reajuste informado');
        self::assertSame('001020 / 3503-00', $divida->consortium_group);
        self::assertSame('Consórcio', $divida->category->name);
    }

    public function test_o_pdf_e_apagado_e_a_leitura_fica_resolvida_ao_confirmar(): void
    {
        $leitura = $this->leitura();
        Storage::disk(config('cerne.documents.disk'))->put($leitura->storage_path, 'pdf');

        $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        $leitura->refresh();
        self::assertSame(ProcessingStatus::Committed, $leitura->processing_status);
        self::assertFalse(Storage::disk(config('cerne.documents.disk'))->exists('documentos/extrato.pdf'), 'o extrato traz CPF e endereço');
    }

    public function test_atualizar_o_cadastro_manual_corrige_prazo_parcelas_e_nao_duplica(): void
    {
        $manual = $this->consorcioManual();
        $leitura = $this->leitura();

        $divida = $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        self::assertSame($manual->id, $divida->id);
        self::assertSame(1, Debt::query()->count());
        self::assertSame(220, $divida->term, 'o manual tinha 218');
        self::assertSame(220, $divida->installments()->count());
        self::assertSame([3, 12], $divida->installments()->where('status', DebtInstallmentStatus::Diluted)->pluck('number')->all());
        self::assertSame('430.57', (string) $divida->installments()->where('number', 5)->first()->paid_amount);
        self::assertSame('Consórcio de imóvel', $divida->name);
    }

    public function test_parcela_paga_pelo_cerne_nao_e_mexida_pelo_extrato(): void
    {
        $divida = $this->consorcioManual();
        $service = app(DebtService::class);
        $parcela32 = $divida->installments()->where('number', 32)->first();
        $service->pay($parcela32, CarbonImmutable::parse('2026-11-15'));

        $leitura = $this->leitura();
        $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        $parcela32->refresh();
        self::assertSame(DebtInstallmentStatus::Paid, $parcela32->status);
        self::assertNotNull($parcela32->expense_id);
    }

    public function test_importar_de_novo_nao_muda_nada(): void
    {
        $leitura = $this->leitura();
        $linha = $this->servico()->rowFor($leitura);
        $divida = $this->servico()->commit($leitura, $linha);

        $outra = $this->leitura();
        $linha2 = $this->servico()->rowFor($outra);
        self::assertSame($divida->id, $linha2['target'], 'agora reconhece pelo grupo e cota');
        $this->servico()->commit($outra, $linha2);

        self::assertSame(1, Debt::query()->count());
        self::assertSame(220, $divida->installments()->count());
        self::assertSame(29, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertSame(2, $divida->installments()->where('status', DebtInstallmentStatus::Diluted)->count());
    }

    public function test_extrato_mais_novo_marca_a_parcela_seguinte_como_paga(): void
    {
        $leitura = $this->leitura();
        $divida = $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        $item = $this->itemDoExtrato();
        $item['parcelas'][] = ['numero' => 32, 'pagamento' => '2026-11-16', 'valor_a_pagar' => '433.40', 'valor_pago' => '433.40'];
        $item['vencimento_atual'] = '2026-11-15';
        $item['parcelas_a_pagar'] = 188;
        $segunda = $this->leitura($item);
        $this->servico()->commit($segunda, $this->servico()->rowFor($segunda));

        self::assertSame(30, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertSame(188, $divida->installments()->where('status', DebtInstallmentStatus::Open)->count());
        self::assertSame('2024-04-15', $divida->fresh()->first_due_date->toDateString(), 'a âncora nova cai no mesmo cronograma');
    }

    public function test_o_objetivo_avanca_pelas_parcelas_pagas_do_extrato(): void
    {
        $leitura = $this->leitura();
        $linha = $this->servico()->rowFor($leitura);
        $linha['goal_choice'] = 'new';
        $linha['goal_name'] = 'Trocar de apartamento';

        $divida = $this->servico()->commit($leitura, $linha);

        $objetivo = Goal::query()->where('linked_debt_id', $divida->id)->sole();
        self::assertSame(['paid' => 29, 'total' => 220], $objetivo->installmentProgress());
    }

    public function test_estimativa_da_parcela_cheia_vem_do_saldo_a_pagar_do_extrato(): void
    {
        $leitura = $this->leitura();
        $divida = $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        // 128.238,06 / 189 = 678,51 de fundo e taxa, mais 0,046371% da carta de seguro (52,08)
        self::assertSame('730.59', $divida->estimatedFullInstallment());
        self::assertSame(189, $divida->statement_remaining);
    }

    public function test_sem_extrato_nao_ha_estimativa(): void
    {
        self::assertNull($this->consorcioManual()->estimatedFullInstallment());
    }

    public function test_datas_e_valores_invalidos_sao_recusados(): void
    {
        $leitura = $this->leitura();
        $linha = $this->servico()->rowFor($leitura);
        $linha['anchor_number'] = 0;

        $this->expectException(InvalidArgumentException::class);
        $this->servico()->commit($leitura, $linha);
    }

    // ------------------------------------------------------------------ cadastro manual

    public function test_cadastro_manual_aceita_parcelas_puladas_e_flex_em_percentual(): void
    {
        $divida = $this->consorcioManual(['paid_before' => 29, 'skipped_installments' => [3, 12], 'flex_percent' => 50, 'is_flex' => false, 'term' => 220]);

        self::assertSame(29, $divida->installments()->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertSame([3, 12], $divida->installments()->where('status', DebtInstallmentStatus::Diluted)->pluck('number')->all());
        self::assertSame(DebtInstallmentStatus::Open, $divida->installments()->where('number', 32)->first()->status);
        self::assertSame(50, $divida->flex_percent);
        self::assertTrue($divida->is_flex);
    }

    public function test_flex_so_aceita_de_10_a_50_de_10_em_10(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->consorcioManual(['flex_percent' => 15]);
    }

    public function test_parcela_pulada_fora_do_que_ja_venceu_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->consorcioManual(['paid_before' => 5, 'skipped_installments' => [40]]);
    }

    public function test_formulario_manual_recebe_flex_e_puladas(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'consortium')->set('consortiumType', 'property')->set('creditAmount', '100000,00')->set('installmentAmount', '433,40')
            ->set('term', '220')->set('firstDueDate', '2024-04-15')->set('paidBefore', '29')->set('skippedInstallments', '3, 12')->set('flexPercent', '50')
            ->set('adjustmentIndex', '')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        self::assertSame(50, $divida->flex_percent);
        self::assertSame(2, $divida->installments()->where('status', DebtInstallmentStatus::Diluted)->count());
    }

    public function test_formulario_manual_recusa_texto_nas_parcelas_puladas(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'consortium')->set('creditAmount', '100000')->set('installmentAmount', '433,40')->set('term', '220')->set('firstDueDate', '2024-04-15')
            ->set('paidBefore', '29')->set('skippedInstallments', 'a terceira')->call('save')->assertHasErrors(['skippedInstallments']);
    }

    public function test_tela_marca_e_reabre_parcela_pulada(): void
    {
        $divida = $this->consorcioManual(['term' => 220]);
        $parcela = $divida->installments()->where('number', 3)->first();
        $parcela->update(['status' => DebtInstallmentStatus::Open, 'paid_on' => null, 'paid_amount' => null]);

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('skip', $parcela->id)->assertHasNoErrors();
        self::assertSame(DebtInstallmentStatus::Diluted, $parcela->fresh()->status);

        Livewire::test(DebtShow::class, ['debt' => $divida->fresh()])->assertSee('pulada')->call('reopen', $parcela->id);
        self::assertSame(DebtInstallmentStatus::Open, $parcela->fresh()->status);
    }

    public function test_contemplacao_sugere_a_parcela_cheia_estimada_pelo_extrato(): void
    {
        $leitura = $this->leitura();
        $divida = $this->servico()->commit($leitura, $this->servico()->rowFor($leitura));

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openContemplation')
            ->assertSet('newInstallmentAmount', '730.59')->assertSet('remainingInstallments', '189');
    }

    public function test_lista_mostra_pagas_puladas_flex_e_a_proxima_parcela_de_cada_contrato(): void
    {
        $this->consorcioManual(['paid_before' => 29, 'skipped_installments' => [3, 12], 'flex_percent' => 50, 'term' => 220]);
        app(DebtService::class)->create([
            'kind' => 'loan', 'name' => 'Empréstimo pessoal', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'category_id' => \App\Models\ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail()->id,
        ]);

        Livewire::test(DebtsIndex::class)
            ->assertSee('29 de 220 pagas')->assertSee('2 puladas')->assertSee('flex de 50%')
            ->assertSee('próxima: parcela 32')
            ->assertSee('0 de 6 pagas')->assertSee('próxima: parcela 1');
    }

    // ------------------------------------------------------------------ tela de importação

    public function test_envio_do_pdf_cria_a_leitura_e_dispara_o_job(): void
    {
        Queue::fake();
        config(['cerne.ai.api_key' => 'chave-de-teste']);

        Livewire::test(ConsortiumImport::class)->call('openUpload')
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->call('enviar')->assertHasNoErrors();

        $leitura = DocumentUpload::query()->sole();
        self::assertSame(DocumentType::ConsortiumStatement, $leitura->document_type);
        Queue::assertPushed(ProcessDocumentJob::class, fn (ProcessDocumentJob $j) => $j->documentId === $leitura->id);
    }

    public function test_limite_diario_de_leituras(): void
    {
        config(['cerne.ai.consortium_imports_per_day' => 1]);
        $this->leitura();

        Livewire::test(ConsortiumImport::class)->call('openUpload')
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->call('enviar')->assertHasErrors(['arquivo']);

        self::assertSame(1, DocumentUpload::query()->count());
    }

    public function test_revisar_e_confirmar_pela_tela_cadastra_e_leva_ao_consorcio(): void
    {
        $leitura = $this->leitura();

        $teste = Livewire::test(ConsortiumImport::class)
            ->assertSee('extrato.pdf')
            ->call('revisar', $leitura->id)->assertSet('showReview', true)
            ->set('row.adjustment_index', 'incc')
            ->call('confirmar')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        $teste->assertRedirect(route('debts.show', $divida));
        self::assertSame(ProcessingStatus::Committed, $leitura->fresh()->processing_status);
    }

    public function test_a_confirmacao_valida_os_campos(): void
    {
        $leitura = $this->leitura();

        Livewire::test(ConsortiumImport::class)->call('revisar', $leitura->id)
            ->set('row.credit_amount', '')->set('row.term', '0')->set('row.flex_percent', '15')
            ->call('confirmar')->assertHasErrors(['row.credit_amount', 'row.term', 'row.flex_percent']);

        self::assertSame(0, Debt::query()->count());
    }

    public function test_descartar_apaga_o_pdf_e_nao_cria_nada(): void
    {
        $leitura = $this->leitura();
        Storage::disk(config('cerne.documents.disk'))->put($leitura->storage_path, 'pdf');

        Livewire::test(ConsortiumImport::class)->call('descartar', $leitura->id);

        self::assertSame(0, Debt::query()->count());
        self::assertSame(ProcessingStatus::Committed, $leitura->fresh()->processing_status);
        self::assertFalse(Storage::disk(config('cerne.documents.disk'))->exists($leitura->storage_path));
    }

    public function test_cada_pessoa_so_ve_as_proprias_leituras(): void
    {
        $leitura = $this->leitura();

        $outro = User::factory()->create();
        $this->actingAs($outro);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        Livewire::test(ConsortiumImport::class)->assertDontSee('extrato.pdf');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(ConsortiumImport::class)->call('revisar', $leitura->id);
    }

    public function test_a_leitura_de_extrato_nao_aparece_na_tela_importar(): void
    {
        self::assertFalse(DocumentType::ConsortiumStatement->availableInImportScreen());
        self::assertSame('consórcios', DocumentType::ConsortiumStatement->destination());
    }

    // ------------------------------------------------------------------ esquema da leitura

    public function test_esquema_do_extrato_lista_todos_os_campos_como_obrigatorios(): void
    {
        $item = DocumentSchemas::for(DocumentType::ConsortiumStatement)['properties']['itens']['items'];

        self::assertEqualsCanonicalizing(array_keys($item['properties']), $item['required']);
        self::assertFalse($item['additionalProperties']);
        self::assertStringContainsString('EXTRATO DO CONSORCIADO', DocumentSchemas::promptFor(DocumentType::ConsortiumStatement));
    }
}

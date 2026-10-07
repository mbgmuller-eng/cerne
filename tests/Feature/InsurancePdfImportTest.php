<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InsuranceType;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\Documents\DocumentsIndex;
use App\Livewire\Insurance\InsuranceImport;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DocumentProcessed;
use App\Services\Extraction\DocumentSchemas;
use App\Support\NotificationPresenter;
use App\Support\PersonName;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importar apólice por PDF em Seguros: a IA lê, a pessoa confere, e só então vira apólice (regra 5 do
 * CLAUDE.md). O PDF fica em Documentos, ligado à apólice.
 */
class InsurancePdfImportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.documents.disk'));
        Storage::fake(config('cerne.document_vault.disk'));
        Queue::fake();
        config(['cerne.ai.api_key' => 'chave-de-teste']);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo Müller']);

        $this->entrar($this->usuario);
    }

    private function entrar(User $usuario, bool $comoConsultor = false): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $comoConsultor ? null : $this->titular, $comoConsultor);
    }

    /** @return array<string, mixed> */
    private function apolice(array $sobre = []): array
    {
        return $sobre + [
            'tipo' => 'carro',
            'seguradora' => 'Porto Seguro',
            'numero_apolice' => '0531.64.123456',
            'segurado' => 'MARCELO BUARQUE MULLER',
            'objeto_segurado' => 'Honda Civic 2022, placa ABC1D23',
            'valor_segurado' => '120000.00',
            'coberturas' => [
                ['nome' => 'Colisão', 'valor' => '120000.00', 'franquia' => 'R$ 3.500,00'],
                ['nome' => 'Assistência 24h', 'valor' => null, 'franquia' => null],
            ],
            'premio' => '250.00',
            'periodicidade' => 'monthly',
            'premio_total_anual' => '3000.00',
            'inicio_vigencia' => '2026-03-01',
            'fim_vigencia' => '2027-03-01',
            'beneficiarios' => [],
            'observacoes_item' => 'Carência de 30 dias para vidros.',
            'campos_incertos' => ['fim_vigencia'],
        ];
    }

    /** Uma leitura pronta para revisar, com o PDF já guardado na pasta de importação. */
    private function leitura(array $itens, ProcessingStatus $status = ProcessingStatus::Completed, ?User $dono = null): DocumentUpload
    {
        $caminho = config('cerne.documents.path').'/'.$this->perfil->id.'/'.uniqid().'.pdf';
        Storage::disk(config('cerne.documents.disk'))->put($caminho, '%PDF-1.4 apolice de teste');

        return DocumentUpload::create([
            'uploaded_by_user_id' => ($dono ?? $this->usuario)->id,
            'document_type' => DocumentType::InsurancePolicy,
            'original_filename' => 'apolice-porto.pdf',
            'storage_path' => $caminho,
            'size_bytes' => 25,
            'processing_status' => $status,
            'records_extracted' => count($itens),
            'extraction_summary' => ['itens' => $itens, 'observacoes' => null],
        ]);
    }

    // ---- envio

    public function test_envio_guarda_o_pdf_e_manda_ler_em_segundo_plano(): void
    {
        Livewire::test(InsuranceImport::class)
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 200, 'application/pdf'))
            ->call('enviar')
            ->assertHasNoErrors();

        $leitura = DocumentUpload::sole();
        self::assertSame(DocumentType::InsurancePolicy, $leitura->document_type);
        self::assertSame(ProcessingStatus::Pending, $leitura->processing_status);
        self::assertSame($this->usuario->id, $leitura->uploaded_by_user_id);
        Storage::disk(config('cerne.documents.disk'))->assertExists($leitura->storage_path);
        Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->documentId === $leitura->id);
    }

    public function test_sem_chave_da_ia_o_envio_fica_na_fila_sem_falhar(): void
    {
        config(['cerne.ai.api_key' => null]);

        Livewire::test(InsuranceImport::class)
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 200, 'application/pdf'))
            ->call('enviar')
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessDocumentJob::class);
        self::assertSame(ProcessingStatus::Pending, DocumentUpload::sole()->processing_status);
    }

    public function test_so_aceita_pdf(): void
    {
        Livewire::test(InsuranceImport::class)
            ->set('arquivo', UploadedFile::fake()->create('foto.png', 100, 'image/png'))
            ->call('enviar')
            ->assertHasErrors('arquivo');

        self::assertSame(0, DocumentUpload::count());
    }

    public function test_limite_diario_de_leituras_por_perfil(): void
    {
        config(['cerne.ai.insurance_imports_per_day' => 2]);
        $this->leitura([], ProcessingStatus::Failed);
        $this->leitura([], ProcessingStatus::Committed);

        Livewire::test(InsuranceImport::class)
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'))
            ->call('enviar')
            ->assertHasErrors('arquivo');

        self::assertSame(2, DocumentUpload::count());
        Queue::assertNothingPushed();
    }

    public function test_descartar_nao_devolve_a_leitura_do_limite_diario(): void
    {
        config(['cerne.ai.insurance_imports_per_day' => 1]);
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('descartar', $leitura->id)
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'))
            ->call('enviar')
            ->assertHasErrors('arquivo');

        $leitura->refresh();
        self::assertSame(ProcessingStatus::Committed, $leitura->processing_status);
        Storage::disk(config('cerne.documents.disk'))->assertMissing($leitura->storage_path);
    }

    public function test_leitura_em_andamento_nao_pode_ser_descartada(): void
    {
        $leitura = $this->leitura([], ProcessingStatus::Processing);

        Livewire::test(InsuranceImport::class)->call('descartar', $leitura->id);

        self::assertSame(ProcessingStatus::Processing, $leitura->refresh()->processing_status);
        Storage::disk(config('cerne.documents.disk'))->assertExists($leitura->storage_path);
    }

    public function test_tentar_de_novo_recoloca_a_leitura_que_falhou_na_fila(): void
    {
        $leitura = $this->leitura([], ProcessingStatus::Failed);
        $leitura->update(['error_message' => 'API sem crédito']);

        Livewire::test(InsuranceImport::class)->call('tentarDeNovo', $leitura->id);

        $leitura->refresh();
        self::assertSame(ProcessingStatus::Pending, $leitura->processing_status);
        self::assertNull($leitura->error_message);
        Queue::assertPushed(ProcessDocumentJob::class);
    }

    // ---- privacidade das leituras

    public function test_cada_pessoa_so_ve_e_revisa_as_proprias_leituras(): void
    {
        $outro = User::factory()->create();
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $outro->id, 'name' => 'Cônjuge']);
        $dela = $this->leitura([$this->apolice()], dono: $outro);
        $minha = $this->leitura([$this->apolice()]);

        $tela = Livewire::test(InsuranceImport::class);
        self::assertSame([$minha->id], $tela->instance()->leituras->pluck('id')->all());

        $this->expectException(ModelNotFoundException::class);
        $tela->call('revisar', $dela->id);
    }

    // ---- revisão

    public function test_revisao_chega_preenchida_com_o_que_a_ia_leu_e_reconhece_o_membro(): void
    {
        $leitura = $this->leitura([$this->apolice()]);

        $tela = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id);

        $linha = $tela->get('rows')[0];
        self::assertTrue($linha['import']);
        self::assertSame('new', $linha['target']);
        self::assertSame('carro', $linha['tipo']);
        self::assertSame('Porto Seguro', $linha['seguradora']);
        self::assertSame('0531.64.123456', $linha['numero']);
        self::assertSame($this->titular->id, $linha['member_id'], 'o segurado do PDF é o titular do perfil');
        self::assertSame('', $linha['pessoa']);
        self::assertSame('120000.00', $linha['valor_segurado']);
        self::assertSame('250.00', $linha['premio']);
        self::assertSame('2026-03-01', $linha['inicio']);
        self::assertSame('2027-03-01', $linha['fim']);
        self::assertCount(2, $linha['coberturas']);
        self::assertSame(['vencimento'], $linha['incertos']);
        $tela->assertSee('A leitura ficou em dúvida em')->assertSee('vencimento');
    }

    public function test_segurado_que_nao_e_membro_vira_outra_pessoa(): void
    {
        $leitura = $this->leitura([$this->apolice(['segurado' => 'Maria da Silva'])]);

        $linha = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->get('rows')[0];

        self::assertSame('', $linha['member_id']);
        self::assertSame('Maria da Silva', $linha['pessoa']);
    }

    public function test_numero_de_apolice_ja_cadastrado_propoe_atualizar_aquela_apolice(): void
    {
        $existente = $this->apoliceCadastrada(['policy_number' => '053164123456']);
        $leitura = $this->leitura([$this->apolice()]);

        $linha = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->get('rows')[0];

        self::assertSame($existente->id, $linha['target'], 'compara só os dígitos do número');
    }

    // ---- confirmar: criar

    public function test_confirmar_cria_a_apolice_e_guarda_o_pdf_em_documentos_ligado_a_ela(): void
    {
        $leitura = $this->leitura([$this->apolice(['beneficiarios' => [['nome' => 'Ana', 'percentual' => 100, 'parentesco' => 'Filha']]])]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->call('confirmar')
            ->assertHasNoErrors()
            ->assertDispatched('policies-imported')
            ->assertSet('showReview', false);

        $apolice = InsurancePolicy::sole();
        self::assertSame(InsuranceType::Carro, $apolice->insurance_type);
        self::assertSame('Porto Seguro', $apolice->insurer_name);
        self::assertSame('0531.64.123456', $apolice->policy_number);
        self::assertSame('Honda Civic 2022, placa ABC1D23', $apolice->insured_item);
        self::assertSame('120000.00', $apolice->coverage_amount);
        self::assertSame('250.00', $apolice->monthly_premium);
        self::assertSame('3000.00', $apolice->annual_premium);
        self::assertSame('monthly', $apolice->payment_frequency->value);
        self::assertSame('2026-03-01', $apolice->start_date->toDateString());
        self::assertSame('2027-03-01', $apolice->expiry_date->toDateString());
        self::assertSame($this->titular->id, $apolice->member_id);
        self::assertSame($this->usuario->id, $apolice->created_by_user_id);
        self::assertFalse($apolice->is_private);
        self::assertSame([
            ['name' => 'Colisão', 'value' => '120000.00', 'deductible' => 'R$ 3.500,00'],
            ['name' => 'Assistência 24h', 'value' => null, 'deductible' => null],
        ], $apolice->coverages);
        self::assertEquals([['name' => 'Ana', 'percentage' => 100, 'relationship' => 'Filha']], $apolice->beneficiaries);

        $documento = Document::sole();
        self::assertSame($apolice->id, $documento->insurance_policy_id);
        self::assertSame('insurance_policy', $documento->category->value);
        self::assertSame($this->titular->id, $documento->member_id);
        self::assertSame('Apólice Porto Seguro 0531.64.123456', $documento->title);
        Storage::disk(config('cerne.document_vault.disk'))->assertExists($documento->storage_path);

        $leitura->refresh();
        self::assertSame(ProcessingStatus::Committed, $leitura->processing_status);
        Storage::disk(config('cerne.documents.disk'))->assertMissing($leitura->storage_path);
    }

    public function test_o_que_a_pessoa_corrige_na_revisao_e_o_que_vale(): void
    {
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.0.seguradora', 'Allianz')
            ->set('rows.0.premio', '199.90')
            ->set('rows.0.privado', true)
            ->set('rows.0.member_id', '')
            ->set('rows.0.pessoa', 'Filha')
            ->call('confirmar')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::sole();
        self::assertSame('Allianz', $apolice->insurer_name);
        self::assertSame('199.90', $apolice->monthly_premium);
        self::assertTrue($apolice->is_private);
        self::assertNull($apolice->member_id);
        self::assertSame('Filha', $apolice->insured_person_name);
    }

    public function test_seguradora_nova_vai_para_a_fila_de_aprovacao_do_admin(): void
    {
        $leitura = $this->leitura([$this->apolice(['seguradora' => 'Seguradora Inventada XYZ'])]);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar')->assertHasNoErrors();

        self::assertSame(1, \App\Models\Insurer::withoutGlobalScopes()->where('name', 'Seguradora Inventada XYZ')->whereNull('dismissed_at')->count());
    }

    // ---- confirmar: atualizar (renovação)

    public function test_confirmar_atualizando_uma_apolice_existente_nao_duplica_e_preserva_o_que_e_da_pessoa(): void
    {
        $existente = $this->apoliceCadastrada([
            'policy_number' => '0531.64.123456', 'monthly_premium' => '200.00', 'notes' => 'Minha anotação',
            'is_private' => true, 'coverages' => [['name' => 'Antiga', 'value' => '1.00']],
        ]);
        $leitura = $this->leitura([$this->apolice(['premio' => '260.00', 'observacoes_item' => 'Nota da IA', 'coberturas' => []])]);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar')->assertHasNoErrors();

        self::assertSame(1, InsurancePolicy::count());
        $existente->refresh();
        self::assertSame('260.00', $existente->monthly_premium);
        self::assertSame('2027-03-01', $existente->expiry_date->toDateString());
        self::assertSame('Minha anotação', $existente->notes, 'anotação da pessoa não é sobrescrita');
        self::assertTrue($existente->is_private);
        self::assertSame($this->titular->id, $existente->member_id);
        self::assertSame([['name' => 'Antiga', 'value' => '1.00']], $existente->coverages, 'lista vazia no PDF não apaga as proteções já cadastradas');
        self::assertSame($existente->id, Document::sole()->insurance_policy_id);
    }

    public function test_o_mesmo_pdf_nao_e_guardado_duas_vezes_na_mesma_apolice(): void
    {
        $this->apoliceCadastrada(['policy_number' => '0531.64.123456']);

        foreach ([1, 2] as $_) {
            $leitura = $this->leitura([$this->apolice()]);
            Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar')->assertHasNoErrors();
        }

        self::assertSame(1, Document::count());
    }

    public function test_um_pdf_novo_da_renovacao_vira_um_segundo_documento_da_apolice(): void
    {
        $apolice = $this->apoliceCadastrada(['policy_number' => '0531.64.123456']);
        $primeira = $this->leitura([$this->apolice()]);
        $segunda = $this->leitura([$this->apolice()]);
        $segunda->update(['original_filename' => 'renovacao-2027.pdf', 'size_bytes' => 99]);

        foreach ([$primeira, $segunda] as $leitura) {
            Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar')->assertHasNoErrors();
        }

        self::assertSame(2, Document::where('insurance_policy_id', $apolice->id)->count());
    }

    // ---- validação e linhas desmarcadas

    public function test_confirmar_barra_dado_invalido_e_nao_grava_nada(): void
    {
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.0.seguradora', '')
            ->set('rows.0.premio', '')
            ->set('rows.0.inicio', '')
            ->set('rows.0.fim', '2020-01-01')
            ->set('rows.0.inicio', '2026-03-01')
            ->call('confirmar')
            ->assertHasErrors(['rows.0.seguradora', 'rows.0.premio', 'rows.0.fim']);

        self::assertSame(0, InsurancePolicy::count());
        self::assertSame(0, Document::count());
        self::assertSame(ProcessingStatus::Completed, $leitura->refresh()->processing_status);
    }

    public function test_membro_de_outro_perfil_e_barrado(): void
    {
        $intruso = ProfileMember::factory()->create();
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.0.member_id', $intruso->id)
            ->call('confirmar')
            ->assertHasErrors('rows.0.member_id');

        self::assertSame(0, InsurancePolicy::count());
    }

    public function test_apolice_de_destino_de_outro_perfil_e_barrada(): void
    {
        $outraPessoa = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outraPessoa->id]);
        $alheia = InsurancePolicy::withoutProfileScope()->create([
            'profile_id' => $outroPerfil->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'Outra',
            'monthly_premium' => '10.00', 'payment_frequency' => 'monthly', 'start_date' => '2024-01-01', 'is_active' => true,
        ]);
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.0.target', $alheia->id)
            ->call('confirmar')
            ->assertHasErrors('rows.0.target');

        self::assertSame('10.00', $alheia->refresh()->monthly_premium);
    }

    public function test_percentuais_dos_beneficiarios_precisam_somar_100(): void
    {
        $leitura = $this->leitura([$this->apolice(['beneficiarios' => [
            ['nome' => 'Ana', 'percentual' => 60, 'parentesco' => 'Filha'],
            ['nome' => 'Beto', 'percentual' => 30, 'parentesco' => 'Filho'],
        ]])]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->call('confirmar')
            ->assertHasErrors('rows.0.beneficiarios');

        self::assertSame(0, InsurancePolicy::count());
    }

    public function test_desmarcar_todas_as_apolices_nao_confirma_nada(): void
    {
        $leitura = $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.0.import', false)
            ->call('confirmar')
            ->assertHasErrors('rows');

        self::assertSame(0, InsurancePolicy::count());
    }

    public function test_pdf_com_duas_apolices_importa_so_a_marcada_e_finaliza_a_leitura(): void
    {
        $leitura = $this->leitura([$this->apolice(), $this->apolice(['numero_apolice' => '999', 'tipo' => 'residencia', 'objeto_segurado' => 'Casa'])]);

        Livewire::test(InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->set('rows.1.import', false)
            ->call('confirmar')
            ->assertHasNoErrors();

        self::assertSame(['0531.64.123456'], InsurancePolicy::pluck('policy_number')->all());
        $leitura->refresh();
        self::assertSame(ProcessingStatus::Committed, $leitura->processing_status);
        self::assertSame([0], $leitura->imported_item_indices);
        self::assertSame([1], $leitura->excluded_item_indices);
    }

    // ---- corretor

    public function test_corretor_que_importa_ja_compartilha_a_apolice_com_ele_mesmo(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->entrar($corretor, comoConsultor: true);
        $leitura = $this->leitura([$this->apolice()], dono: $corretor);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar')->assertHasNoErrors();

        $apolice = InsurancePolicy::sole();
        self::assertSame($corretor->id, $apolice->broker_id);
        self::assertSame($apolice->id, Document::sole()->insurance_policy_id);
    }

    // ---- avisos e telas

    public function test_aviso_de_leitura_pronta_de_apolice_leva_para_seguros(): void
    {
        $leitura = $this->leitura([$this->apolice()]);
        $notificacao = new DatabaseNotification(['data' => DocumentProcessed::forDocument($leitura)->toArray($this->usuario)]);

        $apresentado = NotificationPresenter::present($notificacao);

        self::assertSame(route('insurance.index'), $apresentado['url']);
    }

    public function test_aviso_de_extrato_continua_levando_para_importar(): void
    {
        $notificacao = new DatabaseNotification(['data' => ['kind' => 'document_processed', 'title' => 'x.pdf', 'status' => 'completed', 'document_type' => 'bank_statement']]);

        self::assertSame(route('documents.index'), NotificationPresenter::present($notificacao)['url']);
    }

    public function test_tela_de_seguros_tem_o_botao_e_lista_as_leituras_da_pessoa(): void
    {
        $this->leitura([$this->apolice()]);

        Livewire::test(InsuranceIndex::class)
            ->assertSee('Importar apólice (PDF)')
            ->assertSee('apolice-porto.pdf')
            ->assertSee('Aguardando revisão')
            ->assertSee('Revisar');
    }

    public function test_cartao_da_apolice_mostra_protecoes_com_franquia_e_beneficiarios(): void
    {
        $this->apoliceCadastrada([
            'coverages' => [
                ['name' => 'Colisão', 'value' => '120000.00', 'deductible' => 'R$ 3.500,00'],
                ['name' => 'Assistência 24h', 'value' => null, 'deductible' => null],
            ],
            'beneficiaries' => [['name' => 'Ana', 'percentage' => 60.0], ['name' => 'Beto', 'percentage' => 40.0]],
        ]);

        Livewire::test(InsuranceIndex::class)
            ->assertSee('Colisão')
            ->assertSee('Franquia: R$ 3.500,00')
            ->assertSee('Assistência 24h')
            ->assertSee('Beneficiários:')
            ->assertSee('Ana (60%)')
            ->assertSee('Beto (40%)');
    }

    // ---- tela Importar não lida mais com apólice

    public function test_tela_importar_nao_oferece_nem_aceita_o_tipo_apolice(): void
    {
        Livewire::test(DocumentsIndex::class)
            ->assertDontSee('Apólice de seguro')
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'))
            ->set('documentType', 'insurance_policy')
            ->call('enviar')
            ->assertHasErrors('documentType');
    }

    public function test_leituras_de_apolice_nao_aparecem_na_lista_da_tela_importar(): void
    {
        $this->leitura([$this->apolice()]);

        self::assertCount(0, Livewire::test(DocumentsIndex::class)->instance()->documents);
    }

    // ---- esquema e nomes

    public function test_esquema_de_apolice_declara_todo_campo_como_obrigatorio(): void
    {
        $confere = function (array $no, string $caminho) use (&$confere): void {
            if (($no['type'] ?? null) === 'object' && isset($no['properties'])) {
                self::assertSame(array_keys($no['properties']), $no['required'], "required incompleto em $caminho");
                self::assertFalse($no['additionalProperties'], "additionalProperties em $caminho");
                foreach ($no['properties'] as $nome => $filho) {
                    $confere($filho, "$caminho.$nome");
                }
            }
            if (isset($no['items'])) {
                $confere($no['items'], $caminho.'[]');
            }
        };

        $confere(DocumentSchemas::for(DocumentType::InsurancePolicy), 'apolice');
    }

    public function test_nenhum_esquema_passa_do_limite_de_campos_anulaveis_da_api(): void
    {
        // A API recusa (400) esquema com mais de 16 campos "valor ou nulo". Só aparece com a chave real.
        foreach (DocumentType::cases() as $tipo) {
            if (! $tipo->isExtractable()) {
                continue;
            }

            $conta = function (mixed $no) use (&$conta): int {
                if (! is_array($no)) {
                    return 0;
                }

                return (isset($no['type']) && is_array($no['type']) ? 1 : 0) + array_sum(array_map($conta, $no));
            };

            self::assertLessThanOrEqual(16, $conta(DocumentSchemas::for($tipo)), "esquema de {$tipo->value} passa do limite da API");
        }
    }

    public function test_comparacao_de_nomes_tolera_nome_completo_acento_e_corte(): void
    {
        self::assertTrue(PersonName::sameAs('Marcelo Müller', 'MARCELO BUARQUE DE GUSMAO MULLER'));
        self::assertTrue(PersonName::sameAs('Ana Souza', 'ana souza'));
        self::assertFalse(PersonName::sameAs('Ana Souza', 'Ana Silva'));
        self::assertFalse(PersonName::sameAs('Ana', 'Ana Souza'), 'um nome só não basta');
    }

    /** @param  array<string, mixed>  $sobre */
    private function apoliceCadastrada(array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::create($sobre + [
            'member_id' => $this->titular->id,
            'insurance_type' => InsuranceType::Carro,
            'insurer_name' => 'Porto Seguro',
            'monthly_premium' => '100.00',
            'payment_frequency' => 'monthly',
            'start_date' => '2025-03-01',
            'is_active' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\PolicyRevisionSource;
use App\Enums\ProcessingStatus;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyRevision;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ImportantDatesService;
use App\Services\InsuranceImportService;
use App\Services\InsurancePolicyHistoryService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Linha do tempo da apólice: desde quando começou e como cada reajuste mexeu no custo. A apólice guarda só
 * o estado de hoje; cada mudança relevante vira uma versão datada.
 */
class InsurancePolicyHistoryTest extends TestCase
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
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo Müller']);

        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->titular);
    }

    private function historico(): InsurancePolicyHistoryService
    {
        return app(InsurancePolicyHistoryService::class);
    }

    private function apolice(array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::create($sobre + [
            'member_id' => $this->titular->id,
            'insurance_type' => InsuranceType::Vida,
            'insurer_name' => 'Icatu Seguros',
            'policy_number' => '91.100.576',
            'coverage_amount' => '100000.00',
            'monthly_premium' => '100.00',
            'payment_frequency' => 'monthly',
            'start_date' => '2024-01-10',
            'is_active' => true,
        ]);
    }

    /** @return list<array{string, string}> [data, custo mensal] da mais antiga para a mais recente */
    private function linhaDoTempo(InsurancePolicy $apolice): array
    {
        return InsurancePolicyRevision::withoutGlobalScopes()
            ->where('insurance_policy_id', $apolice->id)->orderBy('effective_on')->get()
            ->map(fn (InsurancePolicyRevision $v) => [$v->effective_on->toDateString(), $v->monthly_premium])
            ->all();
    }

    // ---- cadastro e edição em Seguros

    public function test_apolice_cadastrada_em_seguros_ganha_a_primeira_versao_desde_o_inicio_da_vigencia(): void
    {
        $apolice = $this->apolice();
        $this->historico()->recordCreated($apolice);

        $versao = InsurancePolicyRevision::sole();
        self::assertSame('2024-01-10', $versao->effective_on->toDateString());
        self::assertSame(PolicyRevisionSource::Created, $versao->source);
        self::assertSame('100.00', $versao->monthly_premium);
        self::assertSame($this->perfil->id, $versao->profile_id);
    }

    public function test_editar_o_custo_guarda_a_situacao_anterior_e_a_nova(): void
    {
        $apolice = $this->apolice(); // cadastrada antes do histórico existir: sem versão nenhuma

        $this->historico()->apply($apolice, ['monthly_premium' => '110.00'], PolicyRevisionSource::Manual);

        self::assertSame([['2026-10-07', '100.00'], ['2026-10-08', '110.00']], $this->linhaDoTempo($apolice));
        $baseline = InsurancePolicyRevision::where('effective_on', '2026-10-07')->sole();
        self::assertSame(PolicyRevisionSource::Baseline, $baseline->source);
        self::assertSame('110.00', $apolice->refresh()->monthly_premium);
    }

    public function test_editar_so_o_que_nao_e_acompanhado_nao_cria_versao(): void
    {
        $apolice = $this->apolice();

        $resultado = $this->historico()->apply($apolice, ['notes' => 'Só uma anotação', 'insured_item' => 'Apólice principal'], PolicyRevisionSource::Manual);

        self::assertNull($resultado);
        self::assertSame(0, InsurancePolicyRevision::count());
        self::assertSame('Só uma anotação', $apolice->refresh()->notes);
    }

    public function test_duas_edicoes_no_mesmo_dia_atualizam_a_mesma_versao(): void
    {
        $apolice = $this->apolice();
        $this->historico()->recordCreated($apolice);

        $this->historico()->apply($apolice, ['monthly_premium' => '110.00'], PolicyRevisionSource::Manual);
        $this->historico()->apply($apolice, ['monthly_premium' => '115.00'], PolicyRevisionSource::Manual);

        self::assertSame([['2024-01-10', '100.00'], ['2026-10-08', '115.00']], $this->linhaDoTempo($apolice));
    }

    public function test_mudar_o_vencimento_ou_as_protecoes_tambem_e_acompanhado(): void
    {
        $apolice = $this->apolice();
        $this->historico()->recordCreated($apolice);

        $this->historico()->apply($apolice, ['expiry_date' => '2027-01-10'], PolicyRevisionSource::Manual);
        self::assertSame(2, InsurancePolicyRevision::count());

        $this->travel(1)->days();
        $this->historico()->apply($apolice, ['coverages' => [['name' => 'Morte', 'value' => '100000.00']]], PolicyRevisionSource::Manual);
        self::assertSame(3, InsurancePolicyRevision::count());
    }

    // ---- renovação do profissional

    public function test_renovacao_registrada_pelo_profissional_entra_na_linha_do_tempo_com_a_nota(): void
    {
        $apolice = $this->apolice();
        $consultor = User::factory()->consultant()->create();

        app(ImportantDatesService::class)->registerPolicyRenewal($apolice, $consultor, '120.00', '150000.00', 'Reajuste anual do índice');

        $versao = InsurancePolicyRevision::where('source', 'renewal')->sole();
        self::assertSame('120.00', $versao->monthly_premium);
        self::assertSame('150000.00', $versao->coverage_amount);
        self::assertSame('Reajuste anual do índice', $versao->notes);
        self::assertSame(2, InsurancePolicyRevision::count(), 'a situação de antes também entra');
    }

    public function test_renovacao_sem_perfil_ativo_nao_duplica_as_versoes_ja_existentes(): void
    {
        $apolice = $this->apolice();
        $this->historico()->recordCreated($apolice);
        $consultor = User::factory()->consultant()->create();

        // A tela do consultor mexe em apólices de vários clientes: não há um perfil ativo.
        app(ProfileContext::class)->clear();
        $semEscopo = InsurancePolicy::withoutProfileScope()->findOrFail($apolice->id);
        app(ImportantDatesService::class)->registerPolicyRenewal($semEscopo, $consultor, '120.00', null, null);

        self::assertSame([['2024-01-10', '100.00'], ['2026-10-08', '120.00']], $this->linhaDoTempo($apolice));
    }

    // ---- importação por PDF

    private function leitura(array $item, string $arquivo = 'apolice.pdf', int $tamanho = 100): DocumentUpload
    {
        $caminho = config('cerne.documents.path').'/'.$this->perfil->id.'/'.uniqid().'.pdf';
        Storage::disk(config('cerne.documents.disk'))->put($caminho, '%PDF-1.4 teste');

        return DocumentUpload::create([
            'uploaded_by_user_id' => $this->usuario->id,
            'document_type' => DocumentType::InsurancePolicy,
            'original_filename' => $arquivo,
            'storage_path' => $caminho,
            'size_bytes' => $tamanho,
            'processing_status' => ProcessingStatus::Completed,
            'records_extracted' => 1,
            'extraction_summary' => ['itens' => [$item], 'observacoes' => null],
        ]);
    }

    /** @return array<string, mixed> */
    private function itemDoPdf(array $sobre = []): array
    {
        return $sobre + [
            'tipo' => 'vida', 'seguradora' => 'Icatu Seguros', 'numero_apolice' => '91.100.576', 'segurado' => 'Marcelo Müller',
            'objeto_segurado' => '', 'valor_segurado' => '100000.00', 'coberturas' => [], 'premio' => '100.00',
            'periodicidade' => 'monthly', 'premio_total_anual' => null, 'inicio_vigencia' => '2024-01-10', 'fim_vigencia' => null,
            'vigente_desde' => '2025-01-10', 'beneficiarios' => [], 'observacoes_item' => '', 'campos_incertos' => [],
        ];
    }

    private function importar(array $item, string $arquivo = 'apolice.pdf', int $tamanho = 100, ?callable $ajusta = null): void
    {
        $servico = app(InsuranceImportService::class);
        $leitura = $this->leitura($item, $arquivo, $tamanho);
        $linhas = $servico->rowsFor($leitura);

        if ($ajusta !== null) {
            $linhas = $ajusta($linhas);
        }

        $servico->commit($leitura, $linhas, $this->titular);
    }

    public function test_importar_o_pdf_de_cada_ano_monta_a_linha_do_tempo_com_o_pdf_de_cada_versao(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2025-01-10', 'premio' => '100.00']), 'cert-2025.pdf', 101);
        $this->importar($this->itemDoPdf(['vigente_desde' => '2026-01-10', 'premio' => '112.00', 'valor_segurado' => '110000.00']), 'cert-2026.pdf', 102);

        $apolice = InsurancePolicy::sole();
        self::assertSame('112.00', $apolice->monthly_premium);
        self::assertSame('110000.00', $apolice->coverage_amount);
        self::assertSame([['2025-01-10', '100.00'], ['2026-01-10', '112.00']], $this->linhaDoTempo($apolice));

        $versoes = $apolice->revisions()->with('document')->get();
        self::assertSame('cert-2026.pdf', $versoes[0]->document->original_filename);
        self::assertSame('cert-2025.pdf', $versoes[1]->document->original_filename);
        self::assertSame(2, Document::count());
    }

    public function test_apolice_criada_pela_importacao_ja_tem_a_primeira_versao_no_dia_que_o_papel_manda(): void
    {
        $this->importar($this->itemDoPdf(['numero_apolice' => '777', 'vigente_desde' => '2025-05-01']));

        $versao = InsurancePolicyRevision::sole();
        self::assertSame('2025-05-01', $versao->effective_on->toDateString());
        self::assertSame(PolicyRevisionSource::Import, $versao->source);
        self::assertNotNull($versao->document_id);
    }

    public function test_sem_data_de_vigencia_no_papel_a_versao_vale_desde_o_inicio_da_vigencia(): void
    {
        $this->importar($this->itemDoPdf(['numero_apolice' => '777', 'vigente_desde' => null, 'inicio_vigencia' => '2024-03-01']));

        self::assertSame('2024-03-01', InsurancePolicyRevision::sole()->effective_on->toDateString());
    }

    public function test_enviar_de_novo_o_mesmo_pdf_nao_duplica_a_versao(): void
    {
        $this->importar($this->itemDoPdf(), 'cert.pdf', 100);
        $this->importar($this->itemDoPdf(), 'cert.pdf', 100);

        self::assertSame(1, InsurancePolicyRevision::count());
        self::assertSame(1, Document::count());
    }

    public function test_pdf_antigo_so_entra_no_historico_e_nao_mexe_na_apolice_de_hoje(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2026-01-10', 'premio' => '112.00']), 'cert-2026.pdf', 102);

        // Mais velho que a versão que o Cerne já tem: a tela sugere "só histórico" sozinha.
        $servico = app(InsuranceImportService::class);
        $leitura = $this->leitura($this->itemDoPdf(['vigente_desde' => '2025-01-10', 'premio' => '100.00']), 'cert-2025.pdf', 101);
        $linhas = $servico->rowsFor($leitura);
        self::assertTrue($linhas[0]['somente_historico']);

        $servico->commit($leitura, $linhas, $this->titular);

        $apolice = InsurancePolicy::sole();
        self::assertSame('112.00', $apolice->monthly_premium, 'o custo de hoje não é trocado pelo de um papel velho');
        self::assertSame([['2025-01-10', '100.00'], ['2026-01-10', '112.00']], $this->linhaDoTempo($apolice));
    }

    public function test_so_historico_desmarcado_faz_o_papel_valer_como_atual(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2026-01-10', 'premio' => '112.00']), 'cert-2026.pdf', 102);

        $this->importar($this->itemDoPdf(['vigente_desde' => '2025-01-10', 'premio' => '100.00']), 'cert-2025.pdf', 101, function (array $linhas) {
            $linhas[0]['somente_historico'] = false;

            return $linhas;
        });

        self::assertSame('100.00', InsurancePolicy::sole()->monthly_premium);
    }

    public function test_pdf_mais_novo_nao_vem_marcado_como_so_historico(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2025-01-10']), 'cert-2025.pdf', 101);

        $linhas = app(InsuranceImportService::class)->rowsFor($this->leitura($this->itemDoPdf(['vigente_desde' => '2026-01-10']), 'cert-2026.pdf', 102));

        self::assertFalse($linhas[0]['somente_historico']);
        self::assertSame('2026-01-10', $linhas[0]['vigente_desde']);
    }

    public function test_o_inicio_da_apolice_nunca_anda_para_frente_mas_um_pdf_mais_antigo_o_recua(): void
    {
        $this->importar($this->itemDoPdf(['numero_apolice' => '555', 'inicio_vigencia' => '2025-03-01', 'vigente_desde' => '2025-03-01']), 'a.pdf', 101);

        // Renovação de bem: o PDF traz o início do período novo, que não pode virar o início da apólice.
        $this->importar($this->itemDoPdf(['numero_apolice' => '555', 'inicio_vigencia' => '2026-03-01', 'vigente_desde' => '2026-03-01', 'premio' => '120.00']), 'b.pdf', 102);
        self::assertSame('2025-03-01', InsurancePolicy::sole()->start_date->toDateString());

        // Um PDF anterior ao que se conhecia recua a data de início, mesmo entrando só como histórico.
        $this->importar($this->itemDoPdf(['numero_apolice' => '555', 'inicio_vigencia' => '2024-03-01', 'vigente_desde' => '2024-03-01', 'premio' => '90.00']), 'c.pdf', 103);
        self::assertSame('2024-03-01', InsurancePolicy::sole()->start_date->toDateString());
        self::assertSame('120.00', InsurancePolicy::sole()->monthly_premium);
    }

    public function test_versao_antiga_sem_data_e_barrada_na_tela(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2026-01-10']), 'cert-2026.pdf', 102);
        $leitura = $this->leitura($this->itemDoPdf(['vigente_desde' => '2025-01-10']), 'cert-2025.pdf', 101);

        Livewire::test(\App\Livewire\Insurance\InsuranceImport::class)
            ->call('revisar', $leitura->id)
            ->assertSet('rows.0.somente_historico', true)
            ->set('rows.0.vigente_desde', '')
            ->call('confirmar')
            ->assertHasErrors('rows.0.vigente_desde');
    }

    // ---- tela

    public function test_o_cartao_da_apolice_mostra_o_historico_so_com_data_custo_e_capital_por_morte(): void
    {
        $this->importar($this->itemDoPdf(['vigente_desde' => '2025-01-10', 'premio' => '100.00']), 'cert-2025.pdf', 101);
        $this->importar($this->itemDoPdf(['vigente_desde' => '2026-01-10', 'premio' => '112.00']), 'cert-2026.pdf', 102);

        $versaoNova = InsurancePolicyRevision::where('effective_on', '2026-01-10')->sole();

        $tela = Livewire::test(InsuranceIndex::class);
        $texto = preg_replace('/\s+/', ' ', strip_tags($tela->html()));

        self::assertStringContainsString('Custo: R$ 112,00/mês · Capital por morte qualquer causa: R$ 100.000,00', $texto);
        self::assertStringContainsString('Custo: R$ 100,00/mês · Capital por morte qualquer causa: R$ 100.000,00', $texto);
        // O comparativo (% de reajuste e "antes") não aparece: as duas versões lado a lado já mostram a evolução.
        self::assertStringNotContainsString('Reajuste', $texto);
        self::assertStringNotContainsString('+12,0%', $texto);
        self::assertStringNotContainsString('antes R$', $texto);

        $tela
            ->assertSee('Histórico da apólice · 2 registros')
            ->assertSee('10/01/2026')
            ->assertSee('10/01/2025')
            ->assertSee('Ver o PDF desta versão')
            ->assertSeeHtml('href="'.route('documents.vault.file', $versaoNova->document).'"')
            ->assertSee('Apólice iniciada em')
            ->assertSee('10/01/2024');
    }

    public function test_apolice_sem_historico_nao_mostra_o_bloco(): void
    {
        $this->apolice();

        Livewire::test(InsuranceIndex::class)->assertDontSee('Histórico da apólice');
    }

    public function test_cadastro_e_edicao_pela_tela_de_seguros_entram_no_historico(): void
    {
        $tela = Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Icatu Seguros')
            ->set('policyMonthlyPremium', '100')
            ->set('policyPaymentFrequency', 'monthly')
            ->set('policyStartDate', '2024-01-10')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::sole();
        self::assertSame([['2024-01-10', '100.00']], $this->linhaDoTempo($apolice));

        $tela->call('editPolicy', $apolice->id)->set('policyMonthlyPremium', '108')->call('savePolicy')->assertHasNoErrors();

        self::assertSame([['2024-01-10', '100.00'], ['2026-10-08', '108.00']], $this->linhaDoTempo($apolice));
    }

    // ---- privacidade e isolamento

    public function test_o_historico_de_uma_apolice_oculta_do_conjuge_nao_aparece_para_ele(): void
    {
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjuge->id, 'name' => 'Cônjuge']);

        $privada = $this->apolice(['is_private' => true, 'insurer_name' => 'Seguradora Reservada']);
        $this->historico()->recordCreated($privada);
        $this->historico()->apply($privada, ['monthly_premium' => '130.00'], PolicyRevisionSource::Manual);

        // Com algo oculto no perfil a tela separa por pessoa; o dono abre a aba dele.
        Livewire::test(InsuranceIndex::class)->call('setViewAs', $this->titular->id)->assertSee('Histórico da apólice');

        $this->actingAs($conjuge);
        app(ProfileContext::class)->set($this->perfil, $membroConjuge);

        Livewire::test(InsuranceIndex::class)
            ->assertDontSee('Seguradora Reservada')
            ->assertDontSee('Histórico da apólice')
            ->assertDontSee('Reajuste');
    }

    public function test_versoes_de_outro_perfil_nunca_aparecem(): void
    {
        $alheia = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $alheia->id]);
        $outraApolice = InsurancePolicy::withoutProfileScope()->create([
            'profile_id' => $outroPerfil->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'Outra',
            'monthly_premium' => '10.00', 'payment_frequency' => 'monthly', 'start_date' => '2024-01-01', 'is_active' => true,
        ]);
        $this->historico()->recordCreated($outraApolice);

        self::assertSame(0, InsurancePolicyRevision::count(), 'o escopo de perfil esconde a versão do outro cliente');
        self::assertSame(1, InsurancePolicyRevision::withoutGlobalScopes()->count());
    }

    public function test_excluir_a_apolice_leva_a_linha_do_tempo_junto(): void
    {
        $apolice = $this->apolice();
        $this->historico()->recordCreated($apolice);

        Livewire::test(InsuranceIndex::class)->call('deletePolicy', $apolice->id);

        self::assertSame(0, InsurancePolicyRevision::withoutGlobalScopes()->count());
    }

    public function test_forma_de_pagamento_trimestral_e_normalizada_para_o_mes(): void
    {
        $apolice = $this->apolice(['payment_frequency' => 'quarterly', 'monthly_premium' => '300.00']);
        $this->historico()->recordCreated($apolice);

        self::assertSame(PaymentFrequency::Quarterly, InsurancePolicyRevision::sole()->payment_frequency);
        self::assertSame('100.00', InsurancePolicyRevision::sole()->normalizedMonthlyCost());
    }
}

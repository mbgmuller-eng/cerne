<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\DocumentType;
use App\Enums\InsuranceType;
use App\Enums\ProcessingStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InsuranceImportService;
use App\Support\LifeCoverage;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Como a área de Seguros mostra a apólice: sem "cobertura total" na barra, "cliente desde" pelo início da
 * apólice, valor por categoria = morte qualquer causa (sem contar a mesma apólice duas vezes), "Vitalícia"
 * quando não há vencimento, e o padrão de proteções do seguro de vida.
 */
class InsuranceDisplayStandardsTest extends TestCase
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

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Imad Hadi']);

        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->titular);
    }

    /** Coberturas como o certificado da Icatu traz, fora de ordem e com o que não se mostra. */
    private function coberturasDoCertificado(): array
    {
        return [
            ['name' => 'Serviço de Assistência Funeral Familiar', 'value' => '12000.00', 'deductible' => null],
            ['name' => 'Invalidez Permanente Total ou Parcial por Acidente', 'value' => '450972.23', 'deductible' => null],
            ['name' => 'Morte Qualquer Causa', 'value' => '1043917.20', 'deductible' => null],
            ['name' => 'Apoio Financeiro', 'value' => '2087.83', 'deductible' => null],
            ['name' => 'Diagnóstico Definitivo de 24 Doenças Graves', 'value' => '338229.17', 'deductible' => null],
            ['name' => 'Diária por Internação Hospitalar - Adicional UTI', 'value' => '678.55', 'deductible' => '4 dias'],
            ['name' => 'Adiantamento por Doença em Estágio Terminal', 'value' => '1043917.20', 'deductible' => null],
            ['name' => 'Indenização Especial de Invalidez por Doença', 'value' => '104391.72', 'deductible' => null],
            ['name' => 'Seguro Viagem Nacional e Internacional', 'value' => null, 'deductible' => null],
        ];
    }

    private function apolice(array $sobre = []): InsurancePolicy
    {
        return InsurancePolicy::create($sobre + [
            'member_id' => $this->titular->id,
            'insurance_type' => InsuranceType::Vida,
            'insurer_name' => 'Icatu Seguros',
            'policy_number' => '91.626.226',
            'coverage_amount' => '1043917.20',
            'monthly_premium' => '952.30',
            'payment_frequency' => 'monthly',
            'start_date' => '2025-06-17',
            'is_active' => true,
        ]);
    }

    // ---- padrão de proteções do seguro de vida

    public function test_vida_segue_a_ordem_padrao_e_deixa_de_fora_adiantamento_viagem_e_apoio_financeiro(): void
    {
        $lista = LifeCoverage::standardize($this->coberturasDoCertificado());

        self::assertSame([
            'Morte qualquer causa',
            'Invalidez por acidente',
            'Invalidez por doença',
            'Doenças graves',
            'Internação hospitalar',
            'Serviço funeral · Familiar',
        ], array_column($lista, 'name'));
    }

    public function test_funeral_mostra_o_tipo_e_nao_o_valor(): void
    {
        foreach ([['Serviço de Assistência Funeral Familiar', 'Serviço funeral · Familiar'], ['Assistência Funeral Individual', 'Serviço funeral · Individual'], ['Assistência Funeral', 'Serviço funeral']] as [$original, $esperado]) {
            $item = LifeCoverage::standardize([['name' => $original, 'value' => '12000.00']])[0];

            self::assertSame($esperado, $item['name']);
            self::assertNull($item['value'], 'o valor do funeral não se informa');
        }
    }

    public function test_o_padrao_e_idempotente_e_nao_repete_a_mesma_protecao(): void
    {
        $uma = LifeCoverage::standardize($this->coberturasDoCertificado());

        self::assertSame($uma, LifeCoverage::standardize($uma));

        $repetida = LifeCoverage::standardize([
            ['name' => 'Morte Qualquer Causa', 'value' => '100.00'],
            ['name' => 'Morte Natural ou Acidental', 'value' => '999.00'],
        ]);
        self::assertCount(1, $repetida);
        self::assertSame('100.00', $repetida[0]['value'], 'vale a primeira');
    }

    public function test_protecao_fora_do_padrao_segue_depois_das_seis_sem_se_perder(): void
    {
        $lista = LifeCoverage::standardize([
            ['name' => 'Diária por Incapacidade Temporária - Módulo 1', 'value' => '300.00'],
            ['name' => 'Morte Qualquer Causa', 'value' => '100000.00'],
        ]);

        self::assertSame(['Morte qualquer causa', 'Diária por Incapacidade Temporária - Módulo 1'], array_column($lista, 'name'));
    }

    public function test_internacao_mostra_que_o_valor_e_da_diaria_e_a_franquia(): void
    {
        $linhas = collect(LifeCoverage::present($this->coberturasDoCertificado()))->keyBy('name');

        self::assertSame('Valor da diária · Franquia: 4 dias', $linhas['Internação hospitalar']['note']);
        self::assertNull($linhas['Morte qualquer causa']['note']);
    }

    public function test_capital_principal_do_seguro_de_vida_e_o_de_morte_qualquer_causa(): void
    {
        self::assertSame('1043917.20', LifeCoverage::deathCapital($this->coberturasDoCertificado(), '1.00'));
        self::assertSame('500.00', LifeCoverage::deathCapital([], '500.00'), 'cadastro manual: vale o valor informado');
        self::assertNull(LifeCoverage::deathCapital(null, null));
    }

    public function test_a_tela_mostra_as_protecoes_do_seguro_de_vida_na_ordem_padrao(): void
    {
        $this->apolice(['coverages' => $this->coberturasDoCertificado()]);

        $html = Livewire::test(InsuranceIndex::class)->html();

        $posicoes = array_map(fn (string $t) => strpos($html, $t), ['Morte qualquer causa', 'Invalidez por acidente', 'Invalidez por doença', 'Doenças graves', 'Internação hospitalar', 'Serviço funeral · Familiar']);
        self::assertNotContains(false, $posicoes, 'todas as seis aparecem');
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'na ordem do padrão');

        foreach (['Adiantamento', 'Seguro Viagem', 'Apoio Financeiro'] as $fora) {
            self::assertStringNotContainsString($fora, $html);
        }
        self::assertStringNotContainsString('R$ 12.000,00', $html, 'funeral sem valor');
        self::assertStringContainsString('Valor da diária', $html);
    }

    public function test_outros_tipos_continuam_mostrando_as_protecoes_como_foram_gravadas(): void
    {
        $this->apolice([
            'insurance_type' => InsuranceType::Carro, 'policy_number' => '5',
            'coverages' => [['name' => 'Roubo e furto', 'value' => '50000.00', 'deductible' => '10%'], ['name' => 'Assistência 24h', 'value' => null, 'deductible' => null]],
        ]);

        Livewire::test(InsuranceIndex::class)->assertSee('Roubo e furto')->assertSee('Franquia: 10%')->assertSee('Assistência 24h');
    }

    // ---- barra superior

    public function test_a_barra_nao_mostra_mais_a_cobertura_total(): void
    {
        $this->apolice();

        Livewire::test(InsuranceIndex::class)
            ->assertDontSee('Cobertura total')
            ->assertSee('Custo mensal')
            ->assertSee('R$ 952,30');
    }

    public function test_cliente_desde_e_o_inicio_da_apolice_mais_antiga_e_nao_a_data_do_cadastro(): void
    {
        $this->apolice(['start_date' => '2025-06-17']);
        $this->apolice(['policy_number' => '2', 'insurer_name' => 'Azos', 'start_date' => '2024-02-10']);

        // Hoje (2026) é a data de cadastro; a barra mostra o início da vigência.
        Livewire::test(InsuranceIndex::class)->assertSee('cliente desde fev/2024');
    }

    public function test_sem_apolice_cliente_desde_cai_na_data_de_entrada(): void
    {
        Livewire::test(InsuranceIndex::class)->assertSee('cliente desde '.$this->perfil->created_at->translatedFormat('M/Y'));
    }

    public function test_a_contagem_da_barra_nao_conta_o_mesmo_cadastro_duas_vezes(): void
    {
        $this->apolice(['policy_number' => '91.626.226']);
        $this->apolice(['policy_number' => '91626226']);

        Livewire::test(InsuranceIndex::class)->assertSee('1 apólice ativa');
    }

    // ---- por categoria de risco

    public function test_vida_mostra_morte_qualquer_causa_como_valor_da_categoria(): void
    {
        $this->apolice(['coverages' => $this->coberturasDoCertificado(), 'coverage_amount' => '1.00']);

        $tela = Livewire::test(InsuranceIndex::class);

        $tela->assertSee('Morte qualquer causa')->assertSee('R$ 1.043.917,20');
        self::assertSame('1043917.20', $tela->viewData('byType')['vida']['cobertura'], 'vale o capital de morte, não o valor solto da apólice');
    }

    public function test_seguradoras_diferentes_e_apolices_diferentes_da_mesma_seguradora_somam(): void
    {
        $this->apolice(['policy_number' => 'A', 'insurer_name' => 'Icatu Seguros', 'coverage_amount' => '500000.00', 'monthly_premium' => '100.00']);
        $this->apolice(['policy_number' => 'B', 'insurer_name' => 'Azos', 'coverage_amount' => '300000.00', 'monthly_premium' => '50.00']);
        $this->apolice(['policy_number' => 'C', 'insurer_name' => 'Icatu Seguros', 'coverage_amount' => '200000.00', 'monthly_premium' => '25.00']);

        $vida = Livewire::test(InsuranceIndex::class)->viewData('byType')['vida'];

        self::assertSame('1000000.00', $vida['cobertura']);
        self::assertSame('175.00', $vida['mensal']);
        self::assertSame(3, $vida['quantidade']);
    }

    public function test_atualizar_a_apolice_nao_a_transforma_em_outra_e_nao_dobra_o_total(): void
    {
        $apolice = $this->apolice(['coverage_amount' => '1000000.00', 'monthly_premium' => '839.85']);
        // Renovação: o certificado novo atualiza a MESMA apólice.
        $apolice->update(['coverage_amount' => '1043917.20', 'monthly_premium' => '952.30']);

        $vida = Livewire::test(InsuranceIndex::class)->viewData('byType')['vida'];

        self::assertSame('1043917.20', $vida['cobertura']);
        self::assertSame('952.30', $vida['mensal']);
        self::assertSame(1, $vida['quantidade']);
    }

    public function test_dois_cadastros_do_mesmo_numero_na_mesma_seguradora_contam_uma_vez_so(): void
    {
        $antiga = $this->apolice(['policy_number' => '91.626.226', 'insurer_name' => 'ICATU', 'coverage_amount' => '1000000.00', 'monthly_premium' => '839.85']);
        $antiga->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $this->apolice(['policy_number' => '91626226', 'insurer_name' => 'Icatu Seguros S/A', 'coverage_amount' => '1043917.20', 'monthly_premium' => '952.30']);

        // "Icatu Seguros S/A" não é uma grafia conhecida, então ainda conta como outra seguradora.
        $vida = Livewire::test(InsuranceIndex::class)->viewData('byType')['vida'];
        self::assertSame(2, $vida['quantidade']);

        // Com a mesma seguradora ("ICATU" e "Icatu Seguros" são a mesma) e o mesmo número, é um cadastro só.
        InsurancePolicy::query()->where('insurer_name', 'Icatu Seguros S/A')->update(['insurer_name' => 'Icatu Seguros']);

        $vida = Livewire::test(InsuranceIndex::class)->viewData('byType')['vida'];
        self::assertSame(1, $vida['quantidade']);
        self::assertSame('1043917.20', $vida['cobertura'], 'fica a mais recente');
        self::assertSame('952.30', $vida['mensal']);
    }

    public function test_numero_igual_em_seguradoras_diferentes_sao_apolices_diferentes(): void
    {
        $this->apolice(['policy_number' => '123', 'insurer_name' => 'Icatu Seguros', 'coverage_amount' => '100.00']);
        $this->apolice(['policy_number' => '123', 'insurer_name' => 'Azos', 'coverage_amount' => '200.00']);

        self::assertSame('300.00', Livewire::test(InsuranceIndex::class)->viewData('byType')['vida']['cobertura']);
    }

    public function test_outros_tipos_usam_o_valor_segurado_e_nao_levam_o_rotulo_de_morte(): void
    {
        $this->apolice(['insurance_type' => InsuranceType::Carro, 'policy_number' => 'carro-1', 'coverage_amount' => '90000.00']);

        $tela = Livewire::test(InsuranceIndex::class);

        self::assertSame('90000.00', $tela->viewData('byType')['carro']['cobertura']);
        $tela->assertDontSee('Morte qualquer causa');
    }

    // ---- vigência

    public function test_seguro_de_vida_sem_vencimento_e_vitalicio(): void
    {
        $this->apolice(['expiry_date' => null]);

        Livewire::test(InsuranceIndex::class)->assertSee('Vitalícia')->assertDontSee('Sem vencimento');
    }

    public function test_com_vencimento_mostra_a_data_e_outros_tipos_sem_data_nao_viram_vitalicios(): void
    {
        $this->apolice(['expiry_date' => '2030-05-20', 'policy_number' => 'v']);
        $this->apolice(['insurance_type' => InsuranceType::Carro, 'expiry_date' => null, 'policy_number' => 'c']);

        $html = Livewire::test(InsuranceIndex::class)->html();

        self::assertStringContainsString('20/05/2030', $html);
        self::assertStringContainsString('Sem vencimento', $html, 'o carro sem data continua "Sem vencimento"');
        self::assertStringNotContainsString('Vitalícia', $html, 'vitalícia é do seguro de vida');
    }

    public function test_a_carteira_do_consultor_tambem_diz_vitalicia(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active]);
        $this->apolice(['expiry_date' => null]);
        app(ProfileContext::class)->clear();

        $this->actingAs($consultor);
        Livewire::test(PortfolioInsurance::class)->assertSee('Vitalícia');
    }

    // ---- importação já chega padronizada

    public function test_a_revisao_de_um_pdf_de_vida_chega_no_padrao_e_grava_o_capital_de_morte(): void
    {
        $caminho = config('cerne.documents.path').'/'.$this->perfil->id.'/x.pdf';
        Storage::disk(config('cerne.documents.disk'))->put($caminho, '%PDF-1.4 x');

        $leitura = DocumentUpload::create([
            'uploaded_by_user_id' => $this->usuario->id, 'document_type' => DocumentType::InsurancePolicy, 'original_filename' => 'cert.pdf',
            'storage_path' => $caminho, 'size_bytes' => 10, 'processing_status' => ProcessingStatus::Completed, 'records_extracted' => 1,
            'extraction_summary' => ['observacoes' => null, 'itens' => [[
                'tipo' => 'vida', 'seguradora' => 'Icatu Seguros', 'numero_apolice' => '91.626.226', 'segurado' => 'Imad Hadi', 'objeto_segurado' => '',
                'valor_segurado' => '1.00', 'premio' => '952.30', 'periodicidade' => 'monthly', 'premio_total_anual' => null,
                'inicio_vigencia' => '2025-06-17', 'fim_vigencia' => null, 'vigente_desde' => '2026-07-17', 'beneficiarios' => [], 'observacoes_item' => '', 'campos_incertos' => [],
                'coberturas' => array_map(fn (array $c) => ['nome' => $c['name'], 'valor' => $c['value'], 'franquia' => $c['deductible'] ?? ''], $this->coberturasDoCertificado()),
            ]]],
        ]);

        $servico = app(InsuranceImportService::class);
        $linhas = $servico->rowsFor($leitura);

        self::assertSame(
            ['Morte qualquer causa', 'Invalidez por acidente', 'Invalidez por doença', 'Doenças graves', 'Internação hospitalar', 'Serviço funeral · Familiar'],
            array_column($linhas[0]['coberturas'], 'nome'),
        );
        self::assertSame('1043917.20', $linhas[0]['valor_segurado'], 'o valor segurado vira o capital de morte qualquer causa');

        $servico->commit($leitura, $linhas, $this->titular);

        $apolice = InsurancePolicy::sole();
        self::assertSame('1043917.20', $apolice->coverage_amount);
        self::assertSame('Serviço funeral · Familiar', collect($apolice->coverages)->last()['name']);
        self::assertNull(collect($apolice->coverages)->last()['value']);
        self::assertNotContains('Apoio Financeiro', array_column($apolice->coverages, 'name'));
    }
}

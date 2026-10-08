<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\PolicyCertificateImporter;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Carga de certificados Icatu lidos dos PDFs: acha o cliente do consultor, cria/atualiza a apólice
 * e guarda o PDF em Documentos ligado a ela. Nada disso pode vazar para cliente de outro consultor.
 */
class PolicyCertificateImportTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $cliente;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.document_vault.disk'));

        $this->consultor = User::factory()->consultant()->create();
        $this->cliente = User::factory()->create(['name' => 'Lissandra Amend Simionato', 'cpf_cnpj' => '004.610.189-69']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        $this->titular = ProfileMember::factory()->create([
            'profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'name' => 'Lissandra Simionato',
        ]);
        ConsultantClient::factory()->create([
            'consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->pasta = sys_get_temp_dir().'/cerne-import-'.uniqid();
        mkdir($this->pasta);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta.'/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        @rmdir($this->pasta);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function certificado(array $sobre = []): array
    {
        $arquivo = $sobre['arquivo'] ?? 'cert-004746.PDF';
        file_put_contents($this->pasta.'/'.$arquivo, '%PDF-1.4 teste');

        return $sobre + [
            'arquivo' => $arquivo,
            'nome' => 'LISSANDRA AMEND SIMIONATO',
            'nascimento' => '06/10/1978',
            'cpf' => '004.610.189-69',
            'produto' => 'ESSENCIAL',
            'certificado' => '202409280299',
            'apolice' => '91.100.576',
            'emissao' => '15/11/2025',
            'premio_total' => '297.50',
            'periodicidade' => 'MENSAL',
            'forma_pagamento' => 'Cartão de crédito',
            'indice' => 'IPCA-2',
            'vigencia_apolice' => '10/07/2024',
            'coberturas' => [
                ['name' => 'Morte Qualquer Causa', 'titular' => '105319.64', 'conjuge' => null, 'filhos' => null],
                ['name' => 'Invalidez Permanente Total ou Parcial por Acidente', 'titular' => '315958.91', 'conjuge' => null, 'filhos' => null],
            ],
            'beneficiarios' => [['name' => 'FELIPE SIMIONATO BUENO', 'relationship' => 'Filho(a)', 'percentage' => 100.0]],
        ];
    }

    private function rodar(array $certificados, bool $apply = true): array
    {
        return app(PolicyCertificateImporter::class)->run($this->consultor, $certificados, $this->pasta, $apply);
    }

    private function semContexto(): void
    {
        app(ProfileContext::class)->clear();
        $this->app['auth']->forgetGuards();
    }

    public function test_simulacao_nao_grava_nada(): void
    {
        $linhas = $this->rodar([$this->certificado()], apply: false);

        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame('criar', $linhas[0]['acao']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame(0, Document::withoutProfileScope()->count());
        self::assertSame([], Storage::disk(config('cerne.document_vault.disk'))->allFiles());
    }

    public function test_aplica_cria_a_apolice_com_os_dados_do_certificado_e_anexa_o_pdf(): void
    {
        $this->rodar([$this->certificado()]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertSame($this->perfil->id, $apolice->profile_id);
        self::assertSame($this->titular->id, $apolice->member_id);
        self::assertSame(InsuranceType::Vida, $apolice->insurance_type);
        self::assertSame('Icatu Seguros', $apolice->insurer_name);
        self::assertSame('91.100.576', $apolice->policy_number);
        self::assertSame('105319.64', $apolice->coverage_amount, 'o capital da apólice é o de morte');
        self::assertSame('297.50', $apolice->monthly_premium);
        self::assertNull($apolice->annual_premium);
        self::assertSame('2024-07-10', $apolice->start_date->toDateString());
        self::assertNull($apolice->expiry_date);
        self::assertSame($this->consultor->id, $apolice->created_by_user_id);
        self::assertCount(2, $apolice->coverages);
        self::assertSame('FELIPE SIMIONATO BUENO', $apolice->beneficiaries[0]['name']);
        self::assertStringContainsString('202409280299', $apolice->notes);

        $documento = Document::withoutProfileScope()->sole();
        self::assertSame($apolice->id, $documento->insurance_policy_id);
        self::assertSame('insurance_policy', $documento->category->value);
        self::assertSame($this->perfil->id, $documento->profile_id);
        self::assertSame($this->titular->id, $documento->member_id);
        self::assertSame('application/pdf', $documento->mime_type);
        Storage::disk(config('cerne.document_vault.disk'))->assertExists($documento->storage_path);
    }

    public function test_rodar_de_novo_atualiza_sem_duplicar_apolice_nem_documento(): void
    {
        $this->rodar([$this->certificado()]);
        $linhas = $this->rodar([$this->certificado(['premio_total' => '310.00'])]);

        self::assertSame('atualizar', $linhas[0]['acao']);
        self::assertSame('já existe', $linhas[0]['documento']);
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame(1, Document::withoutProfileScope()->count());
        self::assertSame('310.00', InsurancePolicy::withoutProfileScope()->sole()->monthly_premium);
    }

    public function test_atualiza_a_apolice_ja_cadastrada_pelo_numero_e_mostra_o_que_mudou(): void
    {
        app(ProfileContext::class)->set($this->perfil, $this->titular);
        $antiga = InsurancePolicy::create([
            'member_id' => $this->titular->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'ICATU',
            'policy_number' => '91100576', 'coverage_amount' => '50000.00', 'monthly_premium' => '200.00',
            'payment_frequency' => 'monthly', 'start_date' => '2024-07-01', 'is_active' => true,
        ]);
        $this->semContexto();

        $linhas = $this->rodar([$this->certificado()], apply: false);

        self::assertSame('atualizar', $linhas[0]['acao']);
        self::assertSame(['de' => '50000.00', 'para' => '105319.64'], $linhas[0]['mudancas']['coverage_amount']);
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());

        $this->rodar([$this->certificado()]);

        $antiga->refresh();
        self::assertSame('105319.64', $antiga->coverage_amount);
        self::assertSame('ICATU', $antiga->insurer_name, 'o nome da seguradora já cadastrado não é trocado');
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_apolice_anual_guarda_o_premio_anual_e_o_equivalente_mensal(): void
    {
        $this->rodar([$this->certificado(['periodicidade' => 'ANUAL', 'premio_total' => '1200.00'])]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertSame('annual', $apolice->payment_frequency->value);
        self::assertSame('1200.00', $apolice->annual_premium);
        self::assertSame('100.00', $apolice->monthly_premium);
    }

    public function test_mesma_apolice_em_dois_arquivos_so_conta_uma_vez(): void
    {
        $linhas = $this->rodar([$this->certificado(), $this->certificado(['arquivo' => 'cert-004746 (1).PDF'])]);

        self::assertSame(['ok', 'duplicada'], array_column($linhas, 'status'));
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame(1, Document::withoutProfileScope()->count());
    }

    public function test_pessoa_que_nao_e_cliente_do_consultor_fica_sem_cliente_e_nada_e_criado(): void
    {
        $linhas = $this->rodar([$this->certificado(['nome' => 'FULANO DE TAL', 'cpf' => '111.111.111-11', 'nascimento' => '01/01/1990'])]);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_cliente_de_outro_consultor_nunca_e_alvo(): void
    {
        $outro = User::factory()->consultant()->create();

        $linhas = app(PolicyCertificateImporter::class)->run($outro, [$this->certificado()], $this->pasta, true);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_vinculo_inativo_nao_conta(): void
    {
        ConsultantClient::query()->update(['status' => ConsultantClientStatus::Inactive]);

        self::assertSame('sem_cliente', $this->rodar([$this->certificado()])[0]['status']);
    }

    public function test_cpf_igual_acha_a_pessoa_mesmo_com_o_nome_diferente(): void
    {
        $linhas = $this->rodar([$this->certificado(['nome' => 'LISSANDRA A S', 'nascimento' => '06/10/1978'])]);

        self::assertSame('ok', $linhas[0]['status']);
    }

    public function test_cpf_diferente_barra_mesmo_com_nome_igual(): void
    {
        $linhas = $this->rodar([$this->certificado(['cpf' => '999.999.999-99'])]);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_conjuge_sem_cpf_casa_por_nome_e_nascimento_no_mesmo_perfil(): void
    {
        $conjuge = ProfileMember::factory()->create([
            'profile_id' => $this->perfil->id, 'role' => MemberRole::Secondary, 'name' => 'Rodrigo Burgos', 'birthdate' => '1980-02-03',
        ]);

        $linhas = $this->rodar([$this->certificado(['nome' => 'RODRIGO EUSEBIO DE CASTRO BURGOS', 'cpf' => '222.222.222-22', 'nascimento' => '03/02/1980', 'apolice' => '91.099.922'])]);

        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame($conjuge->id, InsurancePolicy::withoutProfileScope()->sole()->member_id, 'a apólice é do cônjuge, não do titular');
    }

    public function test_nascimento_diferente_barra_a_pessoa(): void
    {
        $this->titular->update(['birthdate' => '1970-01-01']);

        $linhas = $this->rodar([$this->certificado(['cpf' => null])]);

        self::assertSame('sem_cliente', $linhas[0]['status']);
    }

    public function test_nome_so_parecido_vira_revisar_e_nao_e_aplicado(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);

        $linhas = $this->rodar([$this->certificado(['cpf' => null, 'nascimento' => null, 'nome' => 'LISSANDRA ALVES PEREIRA'])]);

        self::assertSame('sem_cliente', $linhas[0]['status'], 'sobrenome diferente não casa');

        // "Lissandra Amend" está contida em "Lissandra Amend Simionato", mas o último nome não fecha.
        $this->titular->update(['name' => 'Lissandra Amend']);
        $linhas = $this->rodar([$this->certificado(['cpf' => null, 'nascimento' => null])]);

        self::assertSame('revisar', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_dois_clientes_com_o_mesmo_nome_vira_revisar(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $outroCliente = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroCliente->id]);
        ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroCliente->id, 'name' => 'Lissandra Simionato']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $outroCliente->id, 'status' => ConsultantClientStatus::Active]);

        $linhas = $this->rodar([$this->certificado(['cpf' => null, 'nascimento' => null])]);

        self::assertSame('revisar', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_apolice_ja_cadastrada_para_outro_cliente_nao_e_tocada(): void
    {
        $outroCliente = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroCliente->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroCliente->id]);
        app(ProfileContext::class)->set($outroPerfil, $outroMembro);
        InsurancePolicy::create([
            'member_id' => $outroMembro->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'Icatu Seguros',
            'policy_number' => '91.100.576', 'monthly_premium' => '10.00', 'payment_frequency' => 'monthly',
            'start_date' => '2024-01-01', 'is_active' => true,
        ]);
        $this->semContexto();

        $linhas = $this->rodar([$this->certificado()]);

        self::assertSame('revisar', $linhas[0]['status']);
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_duas_apolices_novas_nao_disputam_a_mesma_apolice_sem_numero(): void
    {
        app(ProfileContext::class)->set($this->perfil, $this->titular);
        $semNumero = InsurancePolicy::create([
            'member_id' => $this->titular->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'Icatu',
            'monthly_premium' => '0.00', 'payment_frequency' => 'monthly', 'start_date' => '2026-09-10', 'is_active' => true,
        ]);
        $this->semContexto();

        $duas = [
            $this->certificado(['arquivo' => 'a.PDF', 'apolice' => '91.646.967']),
            $this->certificado(['arquivo' => 'b.PDF', 'apolice' => '91.646.720', 'certificado' => '2']),
        ];

        $simulacao = $this->rodar($duas, apply: false);
        self::assertSame(['atualizar', 'criar'], array_column($simulacao, 'acao'), 'a simulação já mostra o que a gravação vai fazer');

        $this->rodar($duas);

        self::assertSame(2, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame('91.646.967', $semNumero->refresh()->policy_number);
    }

    public function test_accept_libera_o_casamento_por_nome_parcial_so_da_apolice_conferida(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $this->titular->update(['name' => 'Lissandra Amend']);
        $parcial = ['cpf' => null, 'nascimento' => null];

        self::assertSame('revisar', $this->rodar([$this->certificado($parcial)])[0]['status']);

        $linhas = app(PolicyCertificateImporter::class)->run($this->consultor, [$this->certificado($parcial)], $this->pasta, true, ['91.999.999']);
        self::assertSame('revisar', $linhas[0]['status'], 'liberar outra apólice não libera esta');

        $linhas = app(PolicyCertificateImporter::class)->run($this->consultor, [$this->certificado($parcial)], $this->pasta, true, ['91100576']);
        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_accept_nao_libera_quando_ha_mais_de_um_cliente_possivel(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $outroCliente = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroCliente->id]);
        ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroCliente->id, 'name' => 'Lissandra Simionato']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $outroCliente->id, 'status' => ConsultantClientStatus::Active]);

        $linhas = app(PolicyCertificateImporter::class)->run($this->consultor, [$this->certificado(['cpf' => null, 'nascimento' => null])], $this->pasta, true, ['91.100.576']);

        self::assertSame('revisar', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_email_informado_acha_o_cliente_mesmo_com_o_nome_bem_diferente(): void
    {
        $this->cliente->update(['email' => 'cliente@exemplo.com']);

        $linhas = $this->rodar([$this->certificado(['nome' => 'NOME COMPLETO NADA A VER', 'cpf' => '000.000.000-00', 'nascimento' => '01/01/1950', 'email' => 'Cliente@Exemplo.com'])]);

        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame($this->titular->id, InsurancePolicy::withoutProfileScope()->sole()->member_id);
    }

    public function test_email_que_nao_e_de_nenhum_cliente_do_consultor_nao_casa_nem_pelo_nome(): void
    {
        $linhas = $this->rodar([$this->certificado(['email' => 'outra.pessoa@exemplo.com'])]);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_nome_cortado_em_40_letras_no_certificado_casa_com_o_nome_do_cadastro(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $this->titular->update(['name' => 'Glauce Oliveira']);
        $cortado = 'GLAUCE ALESSANDRA SILVA BENTO DE OLIVEIR';

        self::assertSame(40, strlen($cortado));
        self::assertSame('ok', $this->rodar([$this->certificado(['cpf' => null, 'nascimento' => null, 'nome' => $cortado])])[0]['status']);
    }

    public function test_nome_curto_com_prefixo_igual_nao_e_tratado_como_cortado(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $this->titular->update(['name' => 'Glauce Oliveira']);

        // Nome completo (abaixo de 40 letras) que só começa parecido: não pode virar "Oliveira".
        $linhas = $this->rodar([$this->certificado(['cpf' => null, 'nascimento' => null, 'nome' => 'GLAUCE ALESSANDRA OLIVEIR'])]);

        self::assertNotSame('ok', $linhas[0]['status']);
    }

    public function test_a_carga_grava_a_versao_desde_a_data_do_certificado(): void
    {
        $this->rodar([$this->certificado(['informacoes_vigentes' => '10/08/2025'])]);
        $this->rodar([$this->certificado(['informacoes_vigentes' => '10/08/2026', 'premio_total' => '327.25'])]);
        $this->rodar([$this->certificado(['informacoes_vigentes' => '10/08/2026', 'premio_total' => '327.25'])]);

        $versoes = \App\Models\InsurancePolicyRevision::withoutGlobalScopes()->orderBy('effective_on')->get();
        self::assertSame(['2025-08-10', '2026-08-10'], $versoes->map(fn ($v) => $v->effective_on->toDateString())->all(), 'rodar de novo não duplica');
        self::assertSame(['297.50', '327.25'], $versoes->pluck('monthly_premium')->all());
        self::assertSame(\App\Enums\PolicyRevisionSource::Import, $versoes->last()->source);
        self::assertNotNull($versoes->last()->document_id);
    }

    public function test_pdf_que_nao_esta_na_pasta_vira_erro_e_nada_e_gravado(): void
    {
        $cert = $this->certificado();
        unlink($this->pasta.'/'.$cert['arquivo']);

        $linhas = $this->rodar([$cert]);

        self::assertSame('erro', $linhas[0]['status']);
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_o_contexto_e_limpo_ao_terminar(): void
    {
        $this->rodar([$this->certificado()]);

        self::assertFalse(app(ProfileContext::class)->hasProfile());
    }

    public function test_a_tela_de_seguros_mostra_o_link_para_o_pdf_da_apolice(): void
    {
        $this->rodar([$this->certificado()]);
        $this->semContexto();

        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->titular);

        $documento = Document::query()->sole();

        Livewire::test(InsuranceIndex::class)
            ->assertSee('Ver apólice (PDF)')
            ->assertSeeHtml('href="'.route('documents.vault.file', $documento).'"');

        $this->get(route('documents.vault.file', $documento))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_apolice_sem_documento_nao_mostra_o_link(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->titular);
        InsurancePolicy::create([
            'member_id' => $this->titular->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'Icatu Seguros',
            'monthly_premium' => '10.00', 'payment_frequency' => 'monthly', 'start_date' => '2024-01-01', 'is_active' => true,
        ]);

        Livewire::test(InsuranceIndex::class)->assertDontSee('Ver apólice (PDF)');
    }
}

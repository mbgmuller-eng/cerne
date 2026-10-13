<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
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
use Tests\TestCase;

/**
 * Carga das apólices da Azos (o mesmo importador dos certificados da Icatu, com as chaves opcionais):
 * preenche a apólice-casca que a carga em planilha deixou, padroniza as coberturas, guarda vencimento,
 * prêmio mensal ou anual e o PDF, e nunca confunde uma seguradora com a outra.
 */
class AzosPolicyImportTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $titularUser;

    private User $conjugeUser;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    private ProfileMember $conjuge;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.document_vault.disk'));

        $this->consultor = User::factory()->consultant()->create();
        $this->titularUser = User::factory()->create(['name' => 'Hemanoel Lima', 'email' => 'hprocessos@gmail.com']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->titularUser->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->titularUser->id, 'name' => 'Hemanoel Lima', 'role' => MemberRole::Primary]);
        $this->conjugeUser = User::factory()->create(['name' => 'Ana Cabral', 'email' => 'ana.carolina_16@hotmail.com']);
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->conjugeUser->id, 'name' => 'Ana Cabral']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->titularUser->id, 'status' => ConsultantClientStatus::Active]);

        $this->pasta = sys_get_temp_dir().'/cerne-azos-'.uniqid();
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

    /** O que o leitor das apólices da Azos entrega. */
    private function apolice(array $sobre = []): array
    {
        $arquivo = $sobre['arquivo'] ?? 'azos_08.pdf';
        file_put_contents($this->pasta.'/'.$arquivo, '%PDF-1.4 teste');

        return $sobre + [
            'arquivo' => $arquivo,
            'nome' => 'Ana Carolina Pereira Cabral',
            'cpf' => '052.828.169-03',
            'email_pdf' => 'ana.carolina_16@hotmail.com',
            'nascimento' => '29/10/1989',
            'apolice' => '056902024000213900000375000000',
            'produto' => 'Excelsior Azos, Pessoas Individual',
            'emissao' => '16/04/2024',
            'premio_total' => '27.41',
            'periodicidade' => 'MENSAL',
            'forma_pagamento' => 'Cartão de Crédito',
            'vigencia_apolice' => '16/04/2024',
            'informacoes_vigentes' => '16/04/2024',
            'vencimento' => '16/04/2029',
            'seguradora' => 'Azos',
            'seguradora_curta' => 'Azos',
            'padronizar_coberturas' => true,
            'capital_principal' => null,
            'notas' => 'Apólice Azos nº 056902024000213900000375000000. Vigência de 16/04/2024 a 16/04/2029.',
            'coberturas' => [
                ['name' => 'Diária de Internação Hospitalar', 'titular' => '271.00', 'franquia' => '72 horas'],
                ['name' => 'Cirurgias', 'titular' => '54531.00', 'franquia' => null],
                ['name' => 'Assistência Funeral Individual', 'titular' => '16359.00', 'franquia' => null],
            ],
            'beneficiarios' => [],
        ];
    }

    private function casca(ProfileMember $membro, string $seguradora = 'AZOS'): InsurancePolicy
    {
        app(ProfileContext::class)->set($this->perfil, $membro);
        $apolice = InsurancePolicy::create([
            'member_id' => $membro->id, 'insurance_type' => InsuranceType::Vida, 'insurer_name' => $seguradora,
            'coverage_amount' => null, 'monthly_premium' => '0.00', 'payment_frequency' => 'monthly',
            'start_date' => '2026-09-10', 'is_active' => true,
        ]);
        app(ProfileContext::class)->clear();
        $this->app['auth']->forgetGuards();

        return $apolice;
    }

    private function rodar(array $apolices, bool $apply = true): array
    {
        return app(PolicyCertificateImporter::class)->run($this->consultor, $apolices, $this->pasta, $apply);
    }

    public function test_preenche_a_apolice_casca_da_azos_da_conjuge_casando_pelo_email_do_pdf(): void
    {
        $casca = $this->casca($this->conjuge);

        $linhas = $this->rodar([$this->apolice()]);

        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame('atualizar', $linhas[0]['acao']);
        self::assertSame('Ana Cabral', $linhas[0]['membro']);

        $apolice = $casca->fresh();
        self::assertSame('056902024000213900000375000000', $apolice->policy_number);
        self::assertSame($this->conjuge->id, $apolice->member_id);
        self::assertSame('27.41', $apolice->monthly_premium);
        self::assertSame('2024-04-16', $apolice->start_date->toDateString());
        self::assertSame('2029-04-16', $apolice->expiry_date->toDateString());
        self::assertSame(InsurancePolicy::withoutProfileScope()->count(), 1, 'não criou outra apólice');
    }

    public function test_coberturas_seguem_o_padrao_do_cerne_e_sem_morte_nao_ha_capital_principal(): void
    {
        $this->casca($this->conjuge);

        $this->rodar([$this->apolice()]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertNull($apolice->coverage_amount, 'sem "morte qualquer causa" não se inventa capital principal');
        self::assertSame(
            [
                ['name' => 'Internação hospitalar', 'value' => '271.00', 'deductible' => '72 horas'],
                ['name' => 'Serviço funeral · Individual', 'value' => null, 'deductible' => null],
                ['name' => 'Cirurgias', 'value' => '54531.00', 'deductible' => null],
            ],
            $apolice->coverages,
        );
    }

    public function test_com_morte_o_capital_principal_e_o_dela(): void
    {
        $this->casca($this->titular);

        $this->rodar([$this->apolice([
            'nome' => 'Hemanoel Lima', 'email_pdf' => 'hprocessos@gmail.com', 'capital_principal' => '400000.00',
            'coberturas' => [['name' => 'Morte', 'titular' => '400000.00'], ['name' => 'Doenças Graves 30', 'titular' => '135000.00']],
        ])]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertSame('400000.00', $apolice->coverage_amount);
        self::assertSame('Morte qualquer causa', $apolice->coverages[0]['name']);
        self::assertSame('Doenças graves', $apolice->coverages[1]['name']);
    }

    public function test_email_do_pdf_que_nao_e_de_ninguem_cai_no_nome(): void
    {
        $this->casca($this->conjuge);

        $linhas = $this->rodar([$this->apolice(['email_pdf' => 'outro@exemplo.com'])]);

        self::assertSame('ok', $linhas[0]['status'], 'o e-mail do PDF diferente do cadastro não conta contra a pessoa');
        self::assertSame('Ana Cabral', $linhas[0]['membro']);
    }

    public function test_email_do_pdf_de_outra_pessoa_da_carteira_manda_na_escolha(): void
    {
        $this->casca($this->titular);
        $this->casca($this->conjuge);

        // O nome no PDF lembra a Ana, mas o e-mail é o do titular.
        $linhas = $this->rodar([$this->apolice(['email_pdf' => 'hprocessos@gmail.com'])]);

        self::assertSame('Hemanoel Lima', $linhas[0]['membro']);
    }

    public function test_so_preenche_a_casca_da_azos_e_nao_a_da_icatu(): void
    {
        $icatu = $this->casca($this->conjuge, 'ICATU');
        $azos = $this->casca($this->conjuge, 'AZOS');

        $this->rodar([$this->apolice()]);

        self::assertNull($icatu->fresh()->policy_number, 'a casca da Icatu continua vazia');
        self::assertNotNull($azos->fresh()->policy_number);
        self::assertSame(2, InsurancePolicy::withoutProfileScope()->count());
    }

    public function test_sem_casca_cria_a_apolice_com_o_nome_da_seguradora(): void
    {
        $this->rodar([$this->apolice()]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertSame('Azos', $apolice->insurer_name);
        self::assertSame(InsuranceType::Vida, $apolice->insurance_type);
    }

    public function test_pdf_vai_para_documentos_com_o_nome_da_azos_e_ligado_a_apolice(): void
    {
        $casca = $this->casca($this->conjuge);

        $this->rodar([$this->apolice()]);

        $documento = Document::withoutProfileScope()->sole();
        self::assertSame($casca->id, $documento->insurance_policy_id);
        self::assertSame('Apólice Azos 056902024000213900000375000000', $documento->title);
        self::assertSame($this->conjuge->id, $documento->member_id);
        Storage::disk(config('cerne.document_vault.disk'))->assertExists($documento->storage_path);
    }

    public function test_prêmio_anual_guarda_o_anual_e_o_mensal_equivalente(): void
    {
        $this->casca($this->conjuge);

        $this->rodar([$this->apolice(['premio_total' => '2492.80', 'periodicidade' => 'ANUAL'])]);

        $apolice = InsurancePolicy::withoutProfileScope()->sole();
        self::assertSame('2492.80', $apolice->annual_premium);
        self::assertSame('207.73', $apolice->monthly_premium);
        self::assertSame('annual', $apolice->payment_frequency->value);
    }

    public function test_observacao_e_a_do_certificado_e_a_versao_vale_desde_o_inicio_da_vigencia(): void
    {
        $casca = $this->casca($this->conjuge);

        $this->rodar([$this->apolice()]);

        self::assertStringContainsString('Vigência de 16/04/2024 a 16/04/2029', $casca->fresh()->notes);
        self::assertStringNotContainsString('vitalício', $casca->fresh()->notes, 'o texto da Icatu não vaza para a Azos');
        // Fora de um perfil ativo o escopo de tenancy esconde as versões: consulta sem ele.
        // A casca (sem custo, capital nem proteções) não tem situação anterior a guardar: a linha do tempo começa
        // na versão do PDF, vigente desde o início da apólice, sem um "Valores anteriores: R$ 0,00" antes dela.
        $versoes = \App\Models\InsurancePolicyRevision::withoutProfileScope()->where('insurance_policy_id', $casca->id)->get();
        self::assertSame(['2024-04-16'], $versoes->map(fn ($v) => $v->effective_on->toDateString())->all());
        self::assertSame('import', $versoes->first()->source->value);
    }

    public function test_rodar_de_novo_atualiza_sem_duplicar_apolice_nem_pdf(): void
    {
        $this->casca($this->conjuge);

        $this->rodar([$this->apolice()]);
        $linhas = $this->rodar([$this->apolice(['premio_total' => '30.00'])]);

        self::assertSame('atualizar', $linhas[0]['acao']);
        self::assertSame('já existe', $linhas[0]['documento']);
        self::assertSame(1, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame(1, Document::withoutProfileScope()->count());
        self::assertSame('30.00', InsurancePolicy::withoutProfileScope()->sole()->monthly_premium);
    }

    public function test_simulacao_mostra_o_vencimento_e_nao_grava(): void
    {
        $this->casca($this->conjuge);

        $linhas = $this->rodar([$this->apolice()], apply: false);

        self::assertArrayHasKey('expiry_date', $linhas[0]['mudancas']);
        self::assertSame('2029-04-16', $linhas[0]['mudancas']['expiry_date']['para']);
        self::assertSame(0, Document::withoutProfileScope()->count());
        self::assertNull(InsurancePolicy::withoutProfileScope()->sole()->policy_number);
    }

    public function test_apolice_repetida_no_mesmo_lote_so_vale_uma_vez(): void
    {
        $this->casca($this->conjuge);

        $linhas = $this->rodar([$this->apolice(), $this->apolice(['arquivo' => 'azos_99.pdf'])]);

        self::assertSame(['ok', 'duplicada'], array_column($linhas, 'status'));
    }

    public function test_nao_toca_em_cliente_de_outro_consultor(): void
    {
        $this->casca($this->conjuge);
        $outro = User::factory()->consultant()->create();

        $linhas = app(PolicyCertificateImporter::class)->run($outro, [$this->apolice()], $this->pasta, true);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertNull(InsurancePolicy::withoutProfileScope()->sole()->policy_number);
    }
}

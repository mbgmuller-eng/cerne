<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\DocumentType;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Consultant\ImportantDates;
use App\Livewire\Insurance\InsuranceImport;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\InsurancePolicyAnniversaryUpcoming;
use App\Services\Extraction\DocumentSchemas;
use App\Services\ImportantDatesService;
use App\Services\PolicyCertificateImporter;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A data de nascimento que consta no PDF da apólice vira o aniversário da pessoa no cadastro (é o que "Datas
 * importantes" lista), sem nunca trocar uma data já cadastrada. E a apólice-casca da planilha, cuja data de início
 * é a do dia da carga, não gera aniversário nem aviso.
 */
class BirthdateFromPolicyTest extends TestCase
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
        Storage::fake(config('cerne.documents.disk'));

        $this->consultor = User::factory()->consultant()->create();
        $this->titularUser = User::factory()->create(['name' => 'Hemanoel Lima', 'email' => 'hprocessos@gmail.com']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->titularUser->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->titularUser->id, 'name' => 'Hemanoel Lima', 'role' => MemberRole::Primary, 'birthdate' => null]);
        $this->conjugeUser = User::factory()->create(['name' => 'Ana Cabral', 'email' => 'ana.carolina_16@hotmail.com']);
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->conjugeUser->id, 'name' => 'Ana Cabral', 'birthdate' => null]);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->titularUser->id, 'status' => ConsultantClientStatus::Active]);

        $this->pasta = sys_get_temp_dir().'/cerne-nasc-'.uniqid();
        mkdir($this->pasta);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->pasta.'/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        @rmdir($this->pasta);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function certificado(array $sobre = []): array
    {
        $arquivo = $sobre['arquivo'] ?? 'azos_08.pdf';
        file_put_contents($this->pasta.'/'.$arquivo, '%PDF-1.4 teste');

        return $sobre + [
            'arquivo' => $arquivo,
            'nome' => 'Ana Carolina Pereira Cabral',
            'email_pdf' => 'ana.carolina_16@hotmail.com',
            'nascimento' => '29/10/1989',
            'apolice' => '056902024000213900000375000000',
            'produto' => 'Excelsior Azos',
            'emissao' => '16/04/2024',
            'premio_total' => '27.41',
            'periodicidade' => 'MENSAL',
            'vigencia_apolice' => '16/04/2024',
            'seguradora' => 'Azos',
            'seguradora_curta' => 'Azos',
            'notas' => 'Apólice Azos.',
            'coberturas' => [['name' => 'Cirurgias', 'titular' => '54531.00']],
            'beneficiarios' => [],
        ];
    }

    private function rodar(array $certificados, bool $apply = true, bool $soAniversarios = false): array
    {
        return app(PolicyCertificateImporter::class)->run($this->consultor, $certificados, $this->pasta, $apply, [], $soAniversarios);
    }

    // ---- carga em lote

    public function test_a_carga_preenche_o_aniversario_que_falta_no_cadastro(): void
    {
        $linhas = $this->rodar([$this->certificado()]);

        self::assertSame('preencher: 29/10/1989', $linhas[0]['aniversario']);
        self::assertSame('1989-10-29', $this->conjuge->fresh()->birthdate->toDateString());
        self::assertNull($this->titular->fresh()->birthdate, 'só a pessoa do PDF');
        self::assertNull($this->conjugeUser->fresh()->birthdate, 'o login não é alterado: o que vale é o cadastro do perfil');
    }

    public function test_a_simulacao_mostra_o_aniversario_e_nao_grava(): void
    {
        $linhas = $this->rodar([$this->certificado()], apply: false);

        self::assertSame('preencher: 29/10/1989', $linhas[0]['aniversario']);
        self::assertNull($this->conjuge->fresh()->birthdate);
    }

    public function test_data_igual_confere_e_data_diferente_avisa_sem_trocar(): void
    {
        $this->conjuge->update(['birthdate' => '1989-10-29']);
        self::assertSame('confere', $this->rodar([$this->certificado()])[0]['aniversario']);

        // Casou pelo e-mail do PDF, mas a data do cadastro é outra: avisa e deixa como está.
        $this->conjuge->update(['birthdate' => '1989-10-30']);
        $linha = $this->rodar([$this->certificado()])[0];

        self::assertSame('DIVERGE: cadastro 30/10/1989, PDF 29/10/1989', $linha['aniversario']);
        self::assertSame('1989-10-30', $this->conjuge->fresh()->birthdate->toDateString(), 'nunca sobrescreve');
    }

    public function test_pdf_sem_data_ou_com_data_invalida_nao_grava_nada(): void
    {
        self::assertSame('-', $this->rodar([$this->certificado(['nascimento' => null])])[0]['aniversario']);
        self::assertSame('-', $this->rodar([$this->certificado(['nascimento' => '31/02/1990'])])[0]['aniversario']);
        self::assertNull($this->conjuge->fresh()->birthdate);
    }

    public function test_pdf_que_da_erro_nao_deixa_o_aniversario_gravado(): void
    {
        $linhas = app(PolicyCertificateImporter::class)->run($this->consultor, [$this->certificado()], $this->pasta.'/nao-existe', true);

        self::assertSame('erro', $linhas[0]['status']);
        self::assertNull($this->conjuge->fresh()->birthdate);
    }

    // ---- modo "só aniversários"

    public function test_so_aniversarios_preenche_a_data_sem_tocar_em_apolice_nem_pdf(): void
    {
        $linhas = app(PolicyCertificateImporter::class)->run($this->consultor, [$this->certificado()], null, true, [], true);

        self::assertSame('ok', $linhas[0]['status']);
        self::assertSame('aniversário', $linhas[0]['acao']);
        self::assertSame('1989-10-29', $this->conjuge->fresh()->birthdate->toDateString());
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
        self::assertSame(0, Document::withoutProfileScope()->count());
    }

    public function test_so_aniversarios_em_simulacao_nao_grava(): void
    {
        $this->rodar([$this->certificado()], apply: false, soAniversarios: true);

        self::assertNull($this->conjuge->fresh()->birthdate);
    }

    public function test_so_aniversarios_nao_preenche_quem_nao_e_da_carteira(): void
    {
        $linhas = $this->rodar([$this->certificado(['email_pdf' => 'ninguem@exemplo.com', 'nome' => 'Fulana Desconhecida de Tal', 'nascimento' => '01/01/1980'])], soAniversarios: true);

        self::assertSame('sem_cliente', $linhas[0]['status']);
        self::assertNull($this->conjuge->fresh()->birthdate);
        self::assertNull($this->titular->fresh()->birthdate);
    }

    public function test_o_comando_aceita_a_opcao_so_aniversarios(): void
    {
        $json = $this->pasta.'/carga.json';
        file_put_contents($json, json_encode([$this->certificado()]));

        $this->artisan('cerne:import-policies', ['json' => $json, '--consultant' => $this->consultor->email, '--birthdates-only' => true, '--apply' => true])
            ->expectsOutputToContain('Gravado.')
            ->assertSuccessful();

        self::assertSame('1989-10-29', $this->conjuge->fresh()->birthdate->toDateString());
        self::assertSame(0, InsurancePolicy::withoutProfileScope()->count());
    }

    // ---- depois de preencher, aparece em Datas importantes

    public function test_o_aniversario_preenchido_aparece_na_tela_de_datas_importantes(): void
    {
        Carbon::setTestNow('2026-10-25');
        $this->rodar([$this->certificado()], soAniversarios: true);

        $this->actingAs($this->consultor);
        $lista = Livewire::test(ImportantDates::class)->set('periodo', 'mes')->get('birthdays');

        self::assertSame([$this->conjuge->id], $lista->pluck('member.id')->all());
        self::assertSame(37, $lista->first()['turning_age']);
    }

    // ---- importação por IA (Seguros)

    private function entrarComoTitular(): void
    {
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'name' => 'Marcelo Müller', 'birthdate' => null]);
        $this->actingAs($cliente);
        app(ProfileContext::class)->set($perfil, $membro);
        $this->clienteIa = [$cliente, $perfil, $membro];
    }

    /** @var array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private array $clienteIa;

    /** @return array<string, mixed> */
    private function itemDaIa(array $sobre = []): array
    {
        return $sobre + [
            'tipo' => 'vida', 'seguradora' => 'Azos', 'numero_apolice' => '056902024000213900000999000000',
            'segurado' => 'MARCELO BUARQUE MULLER', 'segurado_nascimento' => '1990-11-23', 'objeto_segurado' => '',
            'valor_segurado' => null, 'coberturas' => [['nome' => 'Morte', 'valor' => '100000.00', 'franquia' => '']],
            'premio' => '50.00', 'periodicidade' => 'monthly', 'premio_total_anual' => null,
            'inicio_vigencia' => '2024-05-28', 'fim_vigencia' => '2029-05-28', 'vigente_desde' => null,
            'beneficiarios' => [], 'observacoes_item' => '', 'campos_incertos' => [],
        ];
    }

    private function leitura(array $itens): DocumentUpload
    {
        [$cliente, $perfil] = $this->clienteIa;
        $caminho = config('cerne.documents.path').'/'.$perfil->id.'/'.uniqid().'.pdf';
        Storage::disk(config('cerne.documents.disk'))->put($caminho, '%PDF-1.4 apolice');

        return DocumentUpload::create([
            'uploaded_by_user_id' => $cliente->id, 'document_type' => DocumentType::InsurancePolicy, 'original_filename' => 'apolice.pdf',
            'storage_path' => $caminho, 'size_bytes' => 16, 'processing_status' => ProcessingStatus::Completed,
            'records_extracted' => count($itens), 'extraction_summary' => ['itens' => $itens, 'observacoes' => null],
        ]);
    }

    public function test_o_esquema_da_leitura_pede_a_data_de_nascimento_como_texto(): void
    {
        $esquema = DocumentSchemas::for(DocumentType::InsurancePolicy);
        $campo = $esquema['properties']['itens']['items']['properties']['segurado_nascimento'] ?? null;

        self::assertNotNull($campo, 'a leitura precisa devolver o nascimento do segurado');
        self::assertSame('string', $campo['type'], 'texto, não "texto ou nulo": a API limita campos que aceitam nulo');
        self::assertContains('segurado_nascimento', $esquema['properties']['itens']['items']['required']);
    }

    public function test_a_revisao_mostra_a_data_lida_e_confirmar_preenche_o_cadastro_vazio(): void
    {
        $this->entrarComoTitular();
        $leitura = $this->leitura([$this->itemDaIa()]);

        $tela = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id);

        self::assertSame('1990-11-23', $tela->get('rows')[0]['nascimento']);
        self::assertTrue($tela->get('rows')[0]['salvar_nascimento']);
        $tela->assertSee('Data de nascimento do segurado');

        $tela->call('confirmar')->assertHasNoErrors();

        self::assertSame('1990-11-23', $this->clienteIa[2]->fresh()->birthdate->toDateString());
        self::assertStringContainsString('1 aniversário preenchido no cadastro', $tela->get('mensagem'));
    }

    public function test_data_ja_cadastrada_nunca_e_trocada_pela_da_leitura(): void
    {
        $this->entrarComoTitular();
        $this->clienteIa[2]->update(['birthdate' => '1990-11-24']);
        $leitura = $this->leitura([$this->itemDaIa()]);

        $tela = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->call('confirmar');

        self::assertSame('1990-11-24', $this->clienteIa[2]->fresh()->birthdate->toDateString());
        self::assertStringNotContainsString('aniversário', $tela->get('mensagem'));
    }

    public function test_desmarcar_guardar_aniversario_respeita_a_escolha(): void
    {
        $this->entrarComoTitular();
        $leitura = $this->leitura([$this->itemDaIa()]);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->set('rows.0.salvar_nascimento', false)->call('confirmar');

        self::assertNull($this->clienteIa[2]->fresh()->birthdate);
    }

    public function test_pdf_sem_data_de_nascimento_nao_mostra_o_bloco_nem_grava(): void
    {
        $this->entrarComoTitular();
        $leitura = $this->leitura([$this->itemDaIa(['segurado_nascimento' => ''])]);

        $tela = Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id);

        $tela->assertDontSee('Data de nascimento do segurado')->call('confirmar');
        self::assertNull($this->clienteIa[2]->fresh()->birthdate);
    }

    public function test_seguro_familiar_sem_membro_nao_preenche_aniversario_de_ninguem(): void
    {
        $this->entrarComoTitular();
        $leitura = $this->leitura([$this->itemDaIa()]);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)->set('rows.0.member_id', '')->call('confirmar');

        self::assertNull($this->clienteIa[2]->fresh()->birthdate);
    }

    public function test_data_de_nascimento_no_futuro_e_recusada_na_revisao(): void
    {
        $this->entrarComoTitular();
        $leitura = $this->leitura([$this->itemDaIa()]);

        Livewire::test(InsuranceImport::class)->call('revisar', $leitura->id)
            ->set('rows.0.nascimento', now()->addYear()->toDateString())->call('confirmar')
            ->assertHasErrors('rows.0.nascimento');
    }

    // ---- apólice-casca não gera aniversário nem aviso

    private function perfilComPolicias(): array
    {
        return [$this->perfil, $this->conjuge];
    }

    public function test_casca_da_planilha_nao_gera_aniversario_de_apolice_na_tela(): void
    {
        Carbon::setTestNow('2026-09-05');
        $casca = InsurancePolicy::factory()->for($this->perfil, 'profile')->for($this->conjuge, 'member')->create([
            'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'AZOS', 'policy_number' => null, 'monthly_premium' => '0.00',
            'annual_premium' => null, 'coverage_amount' => null, 'coverages' => null, 'start_date' => '2025-09-10',
        ]);
        $real = InsurancePolicy::factory()->for($this->perfil, 'profile')->for($this->titular, 'member')->create([
            'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'AZOS', 'policy_number' => '0569', 'start_date' => '2024-09-12',
        ]);

        self::assertTrue($casca->isPlaceholder());
        self::assertFalse($real->isPlaceholder());

        $lista = app(ImportantDatesService::class)->upcomingPolicyAnniversaries($this->consultor, Carbon::now(), Carbon::now()->addDays(30));

        self::assertSame([$real->id], $lista->pluck('policy.id')->all());
    }

    public function test_casca_da_planilha_nao_dispara_aviso_de_aniversario(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-09-05');
        InsurancePolicy::factory()->for($this->perfil, 'profile')->for($this->conjuge, 'member')->create([
            'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'AZOS', 'policy_number' => null, 'monthly_premium' => '0.00',
            'annual_premium' => null, 'coverage_amount' => null, 'coverages' => null, 'start_date' => '2025-09-10',
        ]);
        $real = InsurancePolicy::factory()->for($this->perfil, 'profile')->for($this->titular, 'member')->create([
            'insurance_type' => InsuranceType::Vida, 'insurer_name' => 'AZOS', 'policy_number' => '0569', 'start_date' => '2024-09-10',
        ]);

        // days_before_important_date: a data-alvo cai em 10/09, o aniversário das duas apólices.
        app(ImportantDatesService::class)->notifyUpcomingPolicyAnniversaries(Carbon::parse('2026-09-10')->subDays((int) config('cerne.notifications.days_before_important_date')));

        Notification::assertSentTo($this->consultor, InsurancePolicyAnniversaryUpcoming::class, fn ($n) => $n->toArray($this->consultor)['insurance_policy_id'] === $real->id);
        Notification::assertSentToTimes($this->consultor, InsurancePolicyAnniversaryUpcoming::class, 1);
    }

    public function test_apolice_so_com_premio_ou_so_com_numero_nao_e_casca(): void
    {
        $base = ['insurance_type' => InsuranceType::Vida, 'insurer_name' => 'AZOS', 'policy_number' => null, 'monthly_premium' => '0.00', 'annual_premium' => null, 'coverage_amount' => null, 'coverages' => null];

        $soPremio = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['monthly_premium' => '90.00'] + $base);
        $soNumero = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['policy_number' => '123'] + $base);
        $soProtecoes = InsurancePolicy::factory()->for($this->perfil, 'profile')->create(['coverages' => [['name' => 'Morte', 'value' => '1.00']]] + $base);

        foreach ([$soPremio, $soNumero, $soProtecoes] as $apolice) {
            self::assertFalse($apolice->fresh()->isPlaceholder());
        }
        self::assertSame(3, InsurancePolicy::withoutProfileScope()->withRealData()->count(), 'o filtro de SQL concorda com o do modelo');
    }
}

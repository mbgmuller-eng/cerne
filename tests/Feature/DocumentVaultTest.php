<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Documents\DocumentVaultIndex;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Área de Documentos: dono/cônjuge sempre veem tudo do perfil; consultor e
 * corretor só o que a categoria libera (ver DocumentVisibilityScope) — ao
 * contrário da Ficha de Saúde, aqui o profissional não é bloqueado da tela
 * inteira, só vê uma lista menor.
 */
class DocumentVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $usuarioTitular;
    private FinancialProfile $perfil;
    private ProfileMember $titular;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.document_vault.disk'));

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuarioTitular->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);
    }

    // ---------------------------------------------------------------- upload/remoção

    public function test_upload_pela_tela_grava_no_disco(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(DocumentVaultIndex::class)
            ->call('newDocument')
            ->set('category', 'driver_license')
            ->set('title', 'CNH — Marcelo')
            ->set('memberId', $this->titular->id)
            ->set('arquivo', UploadedFile::fake()->create('cnh.pdf', 200, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $documento = Document::query()->firstOrFail();
        self::assertSame('CNH — Marcelo', $documento->title);
        self::assertSame($this->titular->id, $documento->created_by_member_id);
        Storage::disk(config('cerne.document_vault.disk'))->assertExists($documento->storage_path);
    }

    public function test_apolice_exige_vinculo_com_uma_apolice_existente(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(DocumentVaultIndex::class)
            ->call('newDocument')
            ->set('category', 'insurance_policy')
            ->set('title', 'Apólice — Auto')
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 200, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['insurancePolicyId']);
    }

    public function test_editar_corrige_titulo_sem_pedir_arquivo_novo(): void
    {
        $this->entrarComo($this->titular);
        $service = app(DocumentService::class);
        $documento = $service->upload(UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'), [
            'category' => 'driver_license', 'title' => 'CNH errada', 'member_id' => $this->titular->id,
        ], $this->titular);
        $caminhoOriginal = $documento->storage_path;

        Livewire::test(DocumentVaultIndex::class)
            ->call('editDocument', $documento->id)
            ->assertSet('title', 'CNH errada')
            ->set('title', 'CNH — Marcelo')
            ->call('save')
            ->assertHasNoErrors();

        $documento->refresh();
        self::assertSame('CNH — Marcelo', $documento->title);
        self::assertSame($caminhoOriginal, $documento->storage_path);
        self::assertSame(1, Document::query()->count());
    }

    public function test_editar_trocando_para_apolice_exige_vinculo(): void
    {
        $this->entrarComo($this->titular);
        $documento = app(DocumentService::class)->upload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), [
            'category' => 'other', 'title' => 'Documento',
        ], $this->titular);

        Livewire::test(DocumentVaultIndex::class)
            ->call('editDocument', $documento->id)
            ->set('category', 'insurance_policy')
            ->call('save')
            ->assertHasErrors(['insurancePolicyId']);
    }

    public function test_consultor_nao_pode_editar_documento(): void
    {
        $documento = $this->criarDocumento();

        [$consultor, $perfil] = $this->criarConsultorVinculado();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(DocumentVaultIndex::class)->call('editDocument', $documento->id)->assertStatus(403);
    }

    public function test_remover_apaga_o_arquivo_do_disco(): void
    {
        $this->entrarComo($this->titular);
        $service = app(DocumentService::class);
        $documento = $service->upload(UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'), [
            'category' => 'driver_license', 'title' => 'CNH', 'member_id' => $this->titular->id,
        ], $this->titular);

        Livewire::test(DocumentVaultIndex::class)->call('delete', $documento->id);

        self::assertSame(0, Document::query()->count());
        Storage::disk(config('cerne.document_vault.disk'))->assertMissing($documento->storage_path);
    }

    public function test_consultor_nao_pode_subir_nem_remover_documento(): void
    {
        [$consultor, $perfil] = $this->criarConsultorVinculado();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(DocumentVaultIndex::class)->call('newDocument')->assertStatus(403);
    }

    // ---------------------------------------------------------------- visibilidade

    public function test_conjuge_ve_documento_que_o_titular_subiu(): void
    {
        $this->entrarComo($this->titular);
        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'), [
            'category' => 'driver_license', 'title' => 'CNH', 'member_id' => $this->titular->id,
        ], $this->titular);

        $conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id]);
        app(ProfileContext::class)->set($this->perfil, $conjuge);

        self::assertSame(1, Document::query()->count());
    }

    public function test_consultor_nao_ve_documento_pessoal_sem_flag(): void
    {
        // CNH, passaporte, certificado, exame: travado, sem exceção — mesma
        // regra dura de CoupleHealthScope, mesmo com "visible_to_professional"
        // marcado por engano no banco.
        $this->entrarComo($this->titular);
        Document::withoutGlobalScopes()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->titular->id, 'category' => 'health_exam',
            'title' => 'Hemograma', 'original_filename' => 'exame.pdf', 'storage_path' => 'x', 'mime_type' => 'application/pdf',
            'size_bytes' => 10, 'visible_to_professional' => true, 'created_by_member_id' => $this->titular->id,
        ]);

        [$consultor, $perfil] = $this->criarConsultorVinculado();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertSame(0, Document::query()->count());
    }

    public function test_consultor_ve_documento_de_apolice_vinculada(): void
    {
        [$consultor, $perfil] = $this->criarConsultorVinculado();
        $apolice = InsurancePolicy::factory()->create(['profile_id' => $perfil->id]);

        $this->actingAs($this->usuarioTitular);
        app(ProfileContext::class)->set($perfil, ProfileMember::query()->where('profile_id', $perfil->id)->firstOrFail());
        $service = app(DocumentService::class);
        $documento = $service->upload(UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'), [
            'category' => 'insurance_policy', 'title' => 'Apólice', 'insurance_policy_id' => $apolice->id,
        ], ProfileMember::query()->where('profile_id', $perfil->id)->firstOrFail());

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertSame([$documento->id], Document::query()->pluck('id')->all());
    }

    public function test_documento_de_apolice_some_do_consultor_se_a_apolice_for_apagada(): void
    {
        [$consultor, $perfil] = $this->criarConsultorVinculado();
        $apolice = InsurancePolicy::factory()->create(['profile_id' => $perfil->id]);
        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->firstOrFail();

        $this->actingAs($this->usuarioTitular);
        app(ProfileContext::class)->set($perfil, $membro);
        app(DocumentService::class)->upload(UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'), [
            'category' => 'insurance_policy', 'title' => 'Apólice', 'insurance_policy_id' => $apolice->id,
        ], $membro);
        $apolice->delete();

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertSame(0, Document::query()->count());
    }

    public function test_categoria_outro_so_aparece_pro_consultor_com_a_flag_marcada(): void
    {
        $this->entrarComo($this->titular);
        $service = app(DocumentService::class);
        $visivel = $service->upload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), [
            'category' => 'other', 'title' => 'Visível', 'visible_to_professional' => true,
        ], $this->titular);
        $service->upload(UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), [
            'category' => 'other', 'title' => 'Oculto', 'visible_to_professional' => false,
        ], $this->titular);

        [$consultor, $perfil] = $this->criarConsultorVinculadoPara($this->usuarioTitular);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertSame([$visivel->id], Document::query()->pluck('id')->all());
    }

    public function test_corretor_so_ve_apolice_do_proprio_vinculo(): void
    {
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $apoliceDoCorretor = InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id, 'broker_id' => $corretor->id]);
        $outroCorretor = User::factory()->broker()->create();
        $apoliceDeOutro = InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id, 'broker_id' => $outroCorretor->id]);

        $this->entrarComo($this->titular);
        $service = app(DocumentService::class);
        $docDoCorretor = $service->upload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), [
            'category' => 'insurance_policy', 'title' => 'A', 'insurance_policy_id' => $apoliceDoCorretor->id,
        ], $this->titular);
        $service->upload(UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), [
            'category' => 'insurance_policy', 'title' => 'B', 'insurance_policy_id' => $apoliceDeOutro->id,
        ], $this->titular);

        $this->actingAs($corretor);
        app(ProfileContext::class)->set($this->perfil, member: null, asConsultant: true);

        self::assertSame([$docDoCorretor->id], Document::query()->pluck('id')->all());
    }

    // ---------------------------------------------------------------- rota do arquivo

    public function test_rota_do_arquivo_exige_login(): void
    {
        $documento = $this->criarDocumento();

        // criarDocumento() já loga como titular (actingAs persiste entre
        // chamadas $this->get() no mesmo teste) — precisa deslogar de
        // propósito pra essa asserção valer alguma coisa de verdade.
        Auth::logout();
        app(ProfileContext::class)->clear();

        $this->get(route('documents.vault.file', $documento->id))->assertRedirect(route('login'));
    }

    public function test_rota_do_arquivo_e_escopada(): void
    {
        $documento = $this->criarDocumento();

        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id]);
        $this->actingAs($outroUsuario);
        app(ProfileContext::class)->set($outroPerfil, $outroMembro);

        $this->get(route('documents.vault.file', $documento->id))->assertNotFound();
    }

    public function test_rota_do_arquivo_funciona_pro_dono(): void
    {
        $documento = $this->criarDocumento();
        $this->entrarComo($this->titular);

        // attachment, não inline: abrir embutido prendia quem tem o Cerne
        // instalado como PWA no iPhone numa visualização sem "voltar" nenhum.
        $this->get(route('documents.vault.file', $documento->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="cnh.pdf"');
    }

    private function criarDocumento(): Document
    {
        $this->entrarComo($this->titular);

        return app(DocumentService::class)->upload(UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'), [
            'category' => 'driver_license', 'title' => 'CNH', 'member_id' => $this->titular->id,
        ], $this->titular);
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function criarConsultorVinculado(): array
    {
        return $this->criarConsultorVinculadoPara($this->usuarioTitular);
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function criarConsultorVinculadoPara(User $cliente): array
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        return [$consultor, $this->perfil];
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $this->actingAs($this->usuarioTitular);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }
}

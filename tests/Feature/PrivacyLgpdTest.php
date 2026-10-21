<?php

namespace Tests\Feature;

use App\Casts\Sensitive;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Jobs\ExportUserDataJob;
use App\Livewire\Admin\AdminUsers;
use App\Livewire\Profile\PrivacyCenter;
use App\Mail\DataExportReadyMail;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\HealthAllergy;
use App\Models\ImpersonationLog;
use App\Models\ProfileMember;
use App\Models\SecurityIncident;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use App\Services\Privacy\AccountDeletionService;
use App\Services\Privacy\DataRetentionService;
use App\Services\Privacy\UserDataExporter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * LGPD: registro do "Entrar como", aceite dos Termos e consentimento de saúde, cifragem de campo sensível, cópia e exclusão dos dados,
 * retenção, registro de incidente e a Política de Privacidade.
 */
class PrivacyLgpdTest extends TestCase
{
    use RefreshDatabase;

    // --- Entrar como ------------------------------------------------------

    public function test_entrar_como_exige_motivo_e_registra_quem_quando_e_por_que(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $cliente = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)->set('motivoAcesso', 'curto')->call('entrarComo', $cliente->id)->assertHasErrors('motivoAcesso');
        self::assertSame(0, ImpersonationLog::query()->count());
        $this->assertAuthenticatedAs($admin);

        Livewire::test(AdminUsers::class)->set('motivoAcesso', 'Cliente pediu ajuda com a importação')->call('entrarComo', $cliente->id);

        $registro = ImpersonationLog::query()->sole();
        self::assertSame($admin->id, $registro->admin_id);
        self::assertSame($cliente->id, $registro->target_user_id);
        self::assertSame('Cliente pediu ajuda com a importação', $registro->reason);
        self::assertTrue($registro->isOpen());

        $this->post(route('admin.impersonate.stop'));

        self::assertNotNull($registro->fresh()->ended_at);
    }

    // --- Aceite dos Termos e consentimento de saúde --------------------------

    public function test_quem_nao_aceitou_a_versao_atual_dos_termos_e_levado_ao_aceite(): void
    {
        $usuario = User::factory()->withoutLegalConsent()->create();

        $this->actingAs($usuario)->get(route('my-account'))->assertRedirect(route('legal.accept'));

        $this->post(route('legal.accept.store'))->assertSessionHasErrors('accept');
        self::assertFalse($usuario->fresh()->hasAcceptedCurrentTerms());

        $this->post(route('legal.accept.store'), ['accept' => '1'])->assertRedirect();

        $usuario = $usuario->fresh();
        self::assertTrue($usuario->hasAcceptedCurrentTerms());
        self::assertSame(config('cerne.legal.terms_version'), $usuario->terms_version);
    }

    public function test_versao_antiga_dos_termos_pede_novo_aceite(): void
    {
        $usuario = User::factory()->create(['terms_version' => '2020-01-01']);

        $this->actingAs($usuario)->get(route('my-account'))->assertRedirect(route('legal.accept'));
    }

    public function test_saude_exige_o_consentimento_especifico_e_ele_pode_ser_revogado(): void
    {
        $usuario = User::factory()->create(['health_consent_at' => null, 'health_consent_version' => null]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);

        $this->get(route('health.card.index'))->assertRedirect(route('health.consent'));

        $this->post(route('health.consent.store'))->assertSessionHasErrors('consent');
        $this->post(route('health.consent.store'), ['consent' => '1']);
        self::assertTrue($usuario->fresh()->hasCurrentHealthConsent());

        $this->get(route('health.card.index'))->assertOk();

        $this->post(route('health.consent.revoke'));
        self::assertFalse($usuario->fresh()->hasCurrentHealthConsent());
        $this->get(route('health.card.index'))->assertRedirect(route('health.consent'));
    }

    // --- Cifragem de campo sensível -----------------------------------------

    public function test_cpf_fica_cifrado_no_banco_e_volta_legivel(): void
    {
        $usuario = User::factory()->create(['cpf_cnpj' => '12345678901']);

        $bruto = DB::table('users')->where('id', $usuario->id)->value('cpf_cnpj');

        self::assertNotSame('12345678901', $bruto);
        self::assertTrue(Sensitive::isEncrypted($bruto));
        self::assertSame('12345678901', $usuario->fresh()->cpf_cnpj);
    }

    public function test_texto_puro_antigo_ainda_le_e_o_comando_cifra_sem_repetir(): void
    {
        $usuario = User::factory()->create();
        DB::table('users')->where('id', $usuario->id)->update(['cpf_cnpj' => '98765432100']);

        self::assertSame('98765432100', $usuario->fresh()->cpf_cnpj);

        $this->artisan('cerne:encrypt-sensitive', ['--dry-run' => true])->assertSuccessful();
        self::assertSame('98765432100', DB::table('users')->where('id', $usuario->id)->value('cpf_cnpj'));

        $this->artisan('cerne:encrypt-sensitive')->assertSuccessful();
        $bruto = DB::table('users')->where('id', $usuario->id)->value('cpf_cnpj');
        self::assertTrue(Sensitive::isEncrypted($bruto));
        self::assertNotSame('98765432100', $bruto);
        self::assertSame('98765432100', $usuario->fresh()->cpf_cnpj);

        self::assertSame(0, \App\Console\Commands\EncryptSensitiveData::plaintextRows());
    }

    // --- Cópia dos dados ---------------------------------------------------

    public function test_exportacao_traz_o_que_a_pessoa_ve_e_nao_o_privado_do_conjuge(): void
    {
        Storage::fake('local');
        [$perfil, $titular, $membroTitular, , $membroConjuge] = $this->casal();

        $meu = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $membroTitular->id, 'is_private' => true]);
        $visivel = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $membroConjuge->id, 'is_private' => false]);
        $oculto = ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $membroConjuge->id, 'is_private' => true]);

        $pedido = app(UserDataExporter::class)->export($titular);

        self::assertSame(DataExport::READY, $pedido->status);
        self::assertTrue($pedido->isAvailable());
        self::assertFalse(app(ProfileContext::class)->hasProfile(), 'o contexto de perfil tem que ser solto no fim');

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($pedido->path));

        self::assertNotFalse($zip->locateName('conta.json'));
        self::assertNotFalse($zip->locateName('LEIA-ME.txt'));
        self::assertStringNotContainsString('"password"', $zip->getFromName('conta.json'));

        $despesas = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), 'expenserecord.json')) {
                $despesas = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        self::assertStringContainsString($meu->id, $despesas);
        self::assertStringContainsString($visivel->id, $despesas);
        self::assertStringNotContainsString($oculto->id, $despesas, 'o lançamento privado do cônjuge não pode sair na cópia do titular');
    }

    public function test_pedido_de_copia_entra_na_fila_e_so_um_por_dia(): void
    {
        Queue::fake();
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        Livewire::test(PrivacyCenter::class)->call('requestExport')->assertHasNoErrors();
        Queue::assertPushed(ExportUserDataJob::class, 1);

        Livewire::test(PrivacyCenter::class)->call('requestExport')->assertHasErrors('export');
        Queue::assertPushed(ExportUserDataJob::class, 1);
    }

    public function test_job_monta_a_copia_e_avisa_por_e_mail(): void
    {
        Storage::fake('local');
        Mail::fake();
        $usuario = User::factory()->create();
        $pedido = DataExport::query()->create(['user_id' => $usuario->id, 'status' => DataExport::PENDING]);

        (new ExportUserDataJob($pedido->id))->handle(app(UserDataExporter::class));

        self::assertSame(DataExport::READY, $pedido->fresh()->status);
        Mail::assertSent(DataExportReadyMail::class, fn ($m) => $m->hasTo($usuario->email));
    }

    public function test_download_so_para_o_dono_e_com_link_assinado(): void
    {
        Storage::fake('local');
        $dono = User::factory()->create();
        $outro = User::factory()->create();
        Storage::disk('local')->put('exports/x.zip', 'zip');
        $pedido = DataExport::query()->create([
            'user_id' => $dono->id, 'status' => DataExport::READY, 'path' => 'exports/x.zip', 'size_bytes' => 3,
            'ready_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $link = URL::temporarySignedRoute('privacy.export.download', $pedido->expires_at, ['export' => $pedido->id]);

        $this->actingAs($outro)->get($link)->assertForbidden();
        $this->actingAs($dono)->get(route('privacy.export.download', $pedido->id))->assertForbidden();
        $this->actingAs($dono)->get($link)->assertOk();
        self::assertNotNull($pedido->fresh()->downloaded_at);
    }

    // --- Exclusão da conta ---------------------------------------------------

    public function test_exclusao_apaga_conta_dados_e_arquivos_e_deixa_so_um_registro_anonimo(): void
    {
        Storage::fake('local');
        [$perfil, $titular, $membro] = $this->casal();

        Storage::disk('local')->put('documentos-pessoais/cnh.pdf', 'x');
        Document::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'category' => 'other', 'title' => 'CNH', 'original_filename' => 'cnh.pdf',
            'storage_path' => 'documentos-pessoais/cnh.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ]);
        Storage::disk('local')->put('documentos/extrato.pdf', 'x');
        DocumentUpload::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'document_type' => 'bank_statement', 'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/extrato.pdf', 'size_bytes' => 1, 'processing_status' => ProcessingStatus::Pending,
        ]);
        ImpersonationLog::query()->create([
            'admin_id' => User::factory()->create(['is_platform_admin' => true])->id, 'admin_email' => 'a@x.com',
            'target_user_id' => $titular->id, 'target_name' => $titular->name, 'target_email' => $titular->email, 'target_role' => 'client',
            'reason' => 'Suporte de importação', 'started_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $titular->id,
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $arquivos = app(AccountDeletionService::class)->delete($titular, 'titular');

        self::assertSame(2, $arquivos);
        self::assertNull(User::query()->find($titular->id));
        self::assertNull(FinancialProfile::query()->find($perfil->id));
        Storage::disk('local')->assertMissing('documentos-pessoais/cnh.pdf');
        Storage::disk('local')->assertMissing('documentos/extrato.pdf');
        self::assertSame(0, DB::table('notifications')->where('notifiable_id', $titular->id)->count());

        $log = ImpersonationLog::query()->sole();
        self::assertNull($log->target_name);
        self::assertNull($log->target_email);

        $registro = DB::table('account_deletions')->sole();
        self::assertSame('titular', $registro->requested_by);
        self::assertSame(2, (int) $registro->files_removed);
        self::assertStringNotContainsString($titular->email, json_encode($registro));
    }

    public function test_exclusao_do_conjuge_so_tira_o_login_dele(): void
    {
        [$perfil, $titular, , $conjuge] = $this->casal();

        app(AccountDeletionService::class)->delete($conjuge);

        self::assertNull(User::query()->find($conjuge->id));
        self::assertNotNull(FinancialProfile::query()->find($perfil->id));
        self::assertNotNull(User::query()->find($titular->id));
    }

    public function test_se_a_cobranca_nao_cancela_a_conta_nao_e_apagada(): void
    {
        $usuario = User::factory()->create();
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(), 'asaas_subscription_id' => 'sub_123',
        ]);
        $this->mock(AsaasClient::class)->shouldReceive('cancelSubscription')->andThrow(new RuntimeException('Asaas fora do ar'));

        try {
            app(AccountDeletionService::class)->delete($usuario);
            self::fail('A exclusão devia ter parado.');
        } catch (RuntimeException) {
        }

        self::assertNotNull(User::query()->find($usuario->id));
    }

    public function test_assinatura_e_cancelada_na_asaas_antes_da_exclusao(): void
    {
        $usuario = User::factory()->create();
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(), 'asaas_subscription_id' => 'sub_999',
        ]);
        $this->mock(AsaasClient::class)->shouldReceive('cancelSubscription')->once()->with('sub_999');

        app(AccountDeletionService::class)->delete($usuario);

        self::assertNull(User::query()->find($usuario->id));
    }

    public function test_central_de_privacidade_pede_senha_email_e_agenda_com_carencia(): void
    {
        $usuario = User::factory()->create(['email' => 'pessoa@exemplo.com']);
        $this->actingAs($usuario);

        Livewire::test(PrivacyCenter::class)
            ->set('deletePassword', 'errada')->set('deleteEmail', 'pessoa@exemplo.com')->call('startDeletion')
            ->assertHasErrors('deletePassword');
        self::assertNull($usuario->fresh()->deletion_due_at);

        Livewire::test(PrivacyCenter::class)
            ->set('deletePassword', 'password')->set('deleteEmail', 'outra@exemplo.com')->call('startDeletion')
            ->assertHasErrors('deleteEmail');

        Livewire::test(PrivacyCenter::class)
            ->set('deletePassword', 'password')->set('deleteEmail', 'PESSOA@exemplo.com')->call('startDeletion')
            ->assertHasNoErrors();

        $usuario = $usuario->fresh();
        self::assertNotNull($usuario->deletion_due_at);
        self::assertTrue($usuario->deletion_due_at->between(now()->addDays(6), now()->addDays(8)));

        Livewire::test(PrivacyCenter::class)->call('cancelDeletion');
        self::assertNull($usuario->fresh()->deletion_due_at);
    }

    public function test_titular_de_perfil_compartilhado_precisa_confirmar_que_o_conjuge_perde_o_perfil(): void
    {
        [, $titular] = $this->casal();
        $this->actingAs($titular);

        Livewire::test(PrivacyCenter::class)
            ->set('deletePassword', 'password')->set('deleteEmail', $titular->email)->call('startDeletion')
            ->assertHasErrors('understandsShared');
        self::assertNull($titular->fresh()->deletion_due_at);

        Livewire::test(PrivacyCenter::class)
            ->set('deletePassword', 'password')->set('deleteEmail', $titular->email)->set('understandsShared', true)->call('startDeletion')
            ->assertHasNoErrors();
        self::assertNotNull($titular->fresh()->deletion_due_at);
    }

    public function test_revogar_o_consentimento_de_saude_pela_central(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        Livewire::test(PrivacyCenter::class)->call('revokeHealthConsent');

        self::assertFalse($usuario->fresh()->hasCurrentHealthConsent());
    }

    public function test_rotina_exclui_so_as_contas_com_carencia_vencida(): void
    {
        $vencida = User::factory()->create(['deletion_requested_at' => now()->subDays(8), 'deletion_due_at' => now()->subMinute()]);
        $naCarencia = User::factory()->create(['deletion_requested_at' => now()->subDay(), 'deletion_due_at' => now()->addDays(6)]);
        $semPedido = User::factory()->create();
        $admin = User::factory()->create(['is_platform_admin' => true, 'deletion_due_at' => now()->subDay()]);

        self::assertSame(1, app(DataRetentionService::class)->deleteDueAccounts());

        self::assertNull(User::query()->find($vencida->id));
        self::assertNotNull(User::query()->find($naCarencia->id));
        self::assertNotNull(User::query()->find($semPedido->id));
        self::assertNotNull(User::query()->find($admin->id), 'administrador nunca é apagado pela rotina');
        self::assertSame(0, app(DataRetentionService::class)->deleteDueAccounts(), 'rodar de novo não faz nada');
    }

    // --- Retenção ----------------------------------------------------------

    public function test_retencao_apaga_copia_vencida_e_pdf_antigo_e_preserva_o_resto(): void
    {
        Storage::fake('local');
        [$perfil, $titular] = $this->casal();

        Storage::disk('local')->put('exports/velho.zip', 'z');
        Storage::disk('local')->put('exports/novo.zip', 'z');
        $velho = DataExport::query()->create(['user_id' => $titular->id, 'status' => DataExport::READY, 'path' => 'exports/velho.zip', 'expires_at' => now()->subHour()]);
        $novo = DataExport::query()->create(['user_id' => $titular->id, 'status' => DataExport::READY, 'path' => 'exports/novo.zip', 'expires_at' => now()->addDay()]);

        Storage::disk('local')->put('documentos/antigo.pdf', 'x');
        Storage::disk('local')->put('documentos/recente.pdf', 'x');
        Storage::disk('local')->put('documentos/na-fila.pdf', 'x');
        $antigo = $this->upload($perfil, 'documentos/antigo.pdf', ProcessingStatus::Committed, now()->subDays(100));
        $recente = $this->upload($perfil, 'documentos/recente.pdf', ProcessingStatus::Committed, now()->subDays(5));
        $naFila = $this->upload($perfil, 'documentos/na-fila.pdf', ProcessingStatus::Completed, now()->subDays(100));

        $resultado = app(DataRetentionService::class)->purge();

        self::assertSame(['exports' => 1, 'source_files' => 1], $resultado);
        Storage::disk('local')->assertMissing('exports/velho.zip');
        Storage::disk('local')->assertExists('exports/novo.zip');
        self::assertNull(DataExport::query()->find($velho->id));
        self::assertNotNull(DataExport::query()->find($novo->id));
        Storage::disk('local')->assertMissing('documentos/antigo.pdf');
        Storage::disk('local')->assertExists('documentos/recente.pdf');
        Storage::disk('local')->assertExists('documentos/na-fila.pdf');
        self::assertSame('', DocumentUpload::withoutGlobalScopes()->find($antigo->id)->storage_path);
        self::assertSame('documentos/na-fila.pdf', DocumentUpload::withoutGlobalScopes()->find($naFila->id)->storage_path, 'o que ainda espera revisão não perde o arquivo');

        self::assertSame(['exports' => 0, 'source_files' => 0], app(DataRetentionService::class)->purge());
    }

    // --- Incidente ---------------------------------------------------------

    public function test_prazo_de_comunicacao_do_incidente_e_de_tres_dias_uteis(): void
    {
        // Sexta-feira: 3 dias úteis depois é a quarta.
        $incidente = new SecurityIncident(['detected_at' => CarbonImmutable::parse('2026-10-16 10:00'), 'risk' => 'high', 'status' => 'open']);

        self::assertSame('2026-10-21', $incidente->notificationDeadline()->toDateString());
        self::assertTrue($incidente->needsAnpdNotice());

        $incidente->anpd_notified_at = now();
        self::assertFalse($incidente->needsAnpdNotice());

        $baixo = new SecurityIncident(['detected_at' => now(), 'risk' => 'low', 'status' => 'open']);
        self::assertFalse($baixo->needsAnpdNotice());
    }

    // --- Política de Privacidade ---------------------------------------------

    public function test_politica_mostra_o_encarregado_so_quando_configurado(): void
    {
        config(['cerne.legal.dpo_email' => null, 'cerne.legal.dpo_name' => null]);
        $this->get(route('legal.privacy'))->assertOk()->assertSee('Política de Privacidade')->assertDontSee('encarregado pelo tratamento');

        config(['cerne.legal.dpo_email' => 'dpo@cerne.app', 'cerne.legal.dpo_name' => 'Maria Souza']);
        $this->get(route('legal.privacy'))->assertOk()->assertSee('dpo@cerne.app')->assertSee('Maria Souza');
    }

    // ----------------------------------------------------------------------

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember, 3: User, 4: ProfileMember} */
    private function casal(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        $membroTitular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        return [$perfil, $titular, $membroTitular, $conjuge, $membroConjuge];
    }

    private function upload(FinancialProfile $perfil, string $caminho, ProcessingStatus $status, \DateTimeInterface $quando): DocumentUpload
    {
        $upload = DocumentUpload::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'document_type' => 'bank_statement', 'original_filename' => basename($caminho),
            'storage_path' => $caminho, 'size_bytes' => 1, 'processing_status' => $status,
        ]);
        DB::table('document_uploads')->where('id', $upload->id)->update(['updated_at' => $quando]);

        return $upload;
    }
}

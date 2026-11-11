<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Accounts\InvoiceShow;
use App\Livewire\Admin\AdminUsers;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\ConsultantClient;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\HealthCard;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthCardService;
use App\Services\Privacy\UserDataExporter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Revisão de segurança (2026-11): vazamentos pela cópia de dados do usuário e pela reserva, QR de emergência depois do consentimento revogado,
 * listas do profissional depois do fim do vínculo, administrador (aba velha e personificação), perfil preso à aba e endereço de push.
 */
class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: FinancialProfile, 3: ProfileMember, 4: ProfileMember} */
    private function casal(): array
    {
        $ana = User::factory()->create(['name' => 'Ana']);
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $ana->id]);
        $membroAna = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $ana->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno']);

        return [$ana, $bruno, $perfil, $membroAna, $membroBruno];
    }

    private function entrar(User $usuario, FinancialProfile $perfil, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
    }

    // ------------------------------------------------------------------ cópia de dados

    public function test_a_copia_dos_dados_do_conjuge_nao_leva_a_trilha_de_auditoria_nem_o_gasto_essencial_digitado_pelo_outro(): void
    {
        Storage::fake('local');
        [$ana, $bruno, $perfil, $membroAna, $membroBruno] = $this->casal();

        $this->entrar($ana, $perfil, $membroAna);
        ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $membroAna->id, 'is_private' => true, 'description' => 'Presente secreto da Ana', 'created_by_user_id' => $ana->id]);
        InvestorProfile::create(['profile_id' => $perfil->id, 'member_id' => $membroAna->id, 'investor_type' => \App\Enums\InvestorType::Moderate, 'employment_type' => \App\Enums\EmploymentType::PublicServant, 'essential_source' => 'manual', 'essential_monthly_manual' => '7777.00']);

        $pedido = app(UserDataExporter::class)->export($bruno);

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($pedido->path));
        $tudo = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $tudo .= (string) $zip->getFromIndex($i);
        }
        $nomes = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->implode('|');
        $zip->close();

        $this->assertStringNotContainsString('Presente secreto da Ana', $tudo);
        $this->assertStringNotContainsString('7777', $tudo);
        $this->assertStringNotContainsString('audit', strtolower($nomes), 'a trilha de auditoria não entra na cópia');
    }

    // ------------------------------------------------------------------ reserva

    public function test_a_media_mostrada_na_reserva_nao_soma_o_essencial_oculto_do_conjuge(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:00:00'));
        [$ana, $bruno, $perfil, $membroAna, $membroBruno] = $this->casal();

        $this->entrar($ana, $perfil, $membroAna);
        ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => $membroAna->id, 'is_private' => true, 'necessity' => Necessity::Essential, 'amount' => '900.00', 'expense_date' => '2026-09-10']);
        ExpenseRecord::factory()->for($perfil, 'profile')->create(['member_id' => null, 'is_private' => false, 'necessity' => Necessity::Essential, 'amount' => '100.00', 'expense_date' => '2026-09-12']);

        $this->entrar($bruno, $perfil, $membroBruno);
        $investidor = InvestorProfile::create(['profile_id' => $perfil->id, 'member_id' => $membroBruno->id, 'investor_type' => \App\Enums\InvestorType::Moderate, 'employment_type' => \App\Enums\EmploymentType::PublicServant]);

        // O Bruno só enxerga os R$ 100 da família: a média mostrada não pode embutir os R$ 900 ocultos da Ana.
        $this->assertSame('100.00', $investidor->essentialMonthlyAverageVisibleToMe());

        CarbonImmutable::setTestNow();
    }

    // ------------------------------------------------------------------ QR de emergência

    public function test_qr_de_emergencia_fecha_quando_a_pessoa_revoga_o_consentimento_e_sai_sem_cache_nem_indexacao(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => 'Marcelo']);
        $this->entrar($usuario, $perfil, $titular);
        $card = app(HealthCardService::class)->ensureEmergencyToken($titular);
        $token = $card->fresh()->emergency_token;

        Auth::logout();
        app(ProfileContext::class)->clear();

        $resposta = $this->get(route('health.emergency.show', $token))->assertOk();
        $this->assertStringContainsString('noindex', (string) $resposta->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));

        $usuario->revokeHealthConsent();

        $this->get(route('health.emergency.show', $token))->assertNotFound();
        $this->assertNull(HealthCard::withoutGlobalScopes()->where('member_id', $titular->id)->value('emergency_token'), 'o token antigo deixa de existir');
    }

    // ------------------------------------------------------------------ listas do profissional depois do fim do vínculo

    public function test_cliente_que_saiu_da_carteira_nao_aparece_nas_listas_vivas_do_consultor(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $vinculo = ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active]);

        $this->assertTrue(ConsultantClient::activeProfileIds($consultor)->contains($perfil->id));

        $vinculo->update(['status' => ConsultantClientStatus::Inactive]);

        $this->assertFalse(ConsultantClient::activeProfileIds($consultor)->contains($perfil->id), 'vínculo encerrado corta a leitura');
    }

    // ------------------------------------------------------------------ administrador

    public function test_aba_de_admin_aberta_nao_age_depois_de_perder_o_papel_nem_durante_a_personificacao(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $alvo = User::factory()->create();
        $this->actingAs($admin);

        $tela1 = Livewire::test(AdminUsers::class);
        $tela2 = Livewire::test(AdminUsers::class);

        // Durante uma personificação a sessão pertence à conta personificada: a aba do admin não executa nada.
        session(['impersonator_id' => $admin->id]);
        $tela1->call('pedirEntrada', $alvo->id)->assertForbidden();
        session()->forget('impersonator_id');

        // Perdeu o papel de administrador: a aba velha não age mais.
        $admin->forceFill(['is_platform_admin' => false])->save();
        $this->actingAs($admin->fresh());
        $tela2->call('pedirEntrada', $alvo->id)->assertForbidden();
    }

    public function test_personificacao_vencida_volta_sozinha_para_o_administrador(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $alvo = User::factory()->create();
        $this->actingAs($alvo)->withSession([
            'impersonator_id' => $admin->id,
            'impersonation_expires_at' => now()->subMinute()->timestamp,
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('admin.users'));
        $this->assertSame($admin->id, Auth::id());
    }

    // ------------------------------------------------------------------ perfil preso à aba

    public function test_aba_antiga_nao_grava_no_perfil_que_o_consultor_abriu_depois(): void
    {
        $consultor = User::factory()->consultant()->create();
        $perfilA = FinancialProfile::factory()->create();
        $perfilB = FinancialProfile::factory()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfilA, null, true);

        $aba = Livewire::test(CashFlowIndex::class);

        // Em outra aba o consultor abriu o cliente B: a sessão agora aponta para B.
        app(ProfileContext::class)->set($perfilB, null, true);

        $aba->call('toggleExpenseForm')->assertStatus(409);
    }

    // ------------------------------------------------------------------ push

    public function test_push_so_aceita_endereco_https_de_servico_de_push_conhecido(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $chaves = ['keys' => ['p256dh' => 'x', 'auth' => 'y']];

        $this->post(route('push.subscribe'), $chaves + ['endpoint' => 'http://127.0.0.1:8080/x'])->assertSessionHasErrors('endpoint');
        $this->post(route('push.subscribe'), $chaves + ['endpoint' => 'https://169.254.169.254/latest/meta-data'])->assertSessionHasErrors('endpoint');
        $this->post(route('push.subscribe'), $chaves + ['endpoint' => 'https://exemplo-interno.local/push'])->assertSessionHasErrors('endpoint');
        $this->postJson(route('push.subscribe'), $chaves + ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertOk();
    }

    // ------------------------------------------------------------------ corretor e fatura

    public function test_corretor_nao_abre_a_fatura_do_cartao(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        $perfil = FinancialProfile::factory()->create();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->create();
        $fatura = CreditCardInvoice::create([
            'profile_id' => $perfil->id, 'credit_card_id' => $cartao->id, 'year' => 2026, 'month' => 10, 'closing_date' => '2026-10-20', 'due_date' => '2026-10-27',
            'total_amount' => '100.00', 'status' => \App\Enums\InvoiceStatus::Open,
        ]);

        Livewire::test(InvoiceShow::class, ['invoice' => $fatura])->assertForbidden();
    }
}

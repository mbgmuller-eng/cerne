<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentKind;
use App\Livewire\Care\CareIndex;
use App\Mail\CaregiverInviteMail;
use App\Models\BankAccount;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\HealthAllergy;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Services\CaregiverService;
use App\Services\HealthAppointmentService;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quem me acompanha: convidar alguém para ver a MINHA saúde, sem assinatura, num perfil comum (casal ou individual). Quem acompanha vê só a minha
 * saúde e o que eu liberar: nada de finanças e nada do cônjuge que divide o perfil comigo.
 */
class FollowMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);
    }

    public function test_convido_alguem_sem_assinatura_e_a_pessoa_nasce_so_com_acesso_a_minha_saude(): void
    {
        Mail::fake();
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();

        $this->actingAs($marcelo);
        $link = app(CaregiverService::class)->inviteFollower($membroMarcelo, $marcelo, 'Filho Paulo', 'paulo@exemplo.com', CaregiverAccess::Edit);
        Mail::assertQueued(CaregiverInviteMail::class, fn ($m) => $m->selfFollow && $m->hasTo('paulo@exemplo.com'));

        $token = basename($link);
        $this->get(route('caregiver-invite.accept', $token))->assertOk()->assertSee('acompanhar a saúde');

        $this->post(route('caregiver-invite.store', $token), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234', 'terms' => '1'])
            ->assertRedirect(route('care.index'));

        $paulo = User::query()->where('email', 'paulo@exemplo.com')->sole();
        $vinculo = ProfileCaregiver::query()->sole();
        $this->assertSame($membroMarcelo->id, $vinculo->member_id);
        $this->assertSame($paulo->id, $vinculo->user_id);
        $this->assertFalse(FinancialProfile::query()->where('owner_user_id', $paulo->id)->exists());
    }

    public function test_quem_me_acompanha_ve_a_minha_saude_e_nao_a_do_conjuge_nem_as_financas(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);

        $contexto = app(ProfileContext::class);
        $contexto->set($perfil, $membroMarcelo);
        $this->actingAs($marcelo);
        app(HealthCardService::class)->addAllergy($membroMarcelo, 'Dipirona', $membroMarcelo);
        app(HealthCardService::class)->addAllergy($membroHelena, 'Penicilina', $membroMarcelo);
        BankAccount::factory()->create(['profile_id' => $perfil->id]);
        $contexto->clear();

        $this->actingAs($paulo)->post(route('care.open', $perfil->id))->assertRedirect(route('health.card.index'));
        $this->get(route('health.card.index'))->assertOk()
            ->assertSee('Dipirona')->assertSee('Marcelo')
            ->assertDontSee('Penicilina')->assertDontSee('Helena');

        $this->assertSame(['Dipirona'], HealthAllergy::query()->pluck('description')->all());
        $this->assertSame(0, BankAccount::query()->count());
        $this->get(route('cashflow.index'))->assertRedirect(route('care.index'));
    }

    public function test_o_nivel_vale_por_pessoa_e_o_cuidador_de_um_nao_grava_na_outra(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Edit);
        // O mesmo Paulo acompanha a Helena só para ler.
        ProfileCaregiver::query()->create(['profile_id' => $perfil->id, 'member_id' => $membroHelena->id, 'user_id' => $paulo->id, 'invited_email' => $paulo->email, 'invited_name' => 'Paulo', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);

        $this->actingAs($paulo);
        $contexto = app(ProfileContext::class);
        $acessos = ProfileCaregiver::query()->active()->where('user_id', $paulo->id)->get()->all();
        $contexto->set($perfil, null, false, $acessos[0], $acessos);

        $this->assertTrue($contexto->canWriteAsCaregiver($membroMarcelo->id));
        $this->assertFalse($contexto->canWriteAsCaregiver($membroHelena->id));

        app(HealthCardService::class)->addAllergy($membroMarcelo, 'Poeira', null);

        $this->expectException(AuthorizationException::class);
        app(HealthCardService::class)->addAllergy($membroHelena, 'Gato', null);
    }

    public function test_cada_um_do_casal_convida_so_para_a_propria_saude(): void
    {
        Mail::fake();
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();

        // Marcelo não convida para a saúde da Helena.
        $this->expectExceptionMessage('sua própria saúde');
        app(CaregiverService::class)->inviteFollower($membroHelena, $marcelo, 'Alguém', 'alguem@exemplo.com', CaregiverAccess::Read);
    }

    public function test_o_titular_nao_revoga_quem_acompanha_o_conjuge(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();
        $filha = $this->seguidor($perfil, $membroHelena, CaregiverAccess::Read);
        $vinculo = ProfileCaregiver::query()->where('user_id', $filha->id)->sole();

        // Marcelo é o dono do perfil, mas o acesso é à saúde da Helena: quem manda é ela.
        $this->actingAs($marcelo);
        try {
            Livewire::test(CareIndex::class)->call('revoke', $vinculo->id);
            $this->fail('O titular não revoga o acesso à saúde do cônjuge.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        $this->assertSame(CaregiverStatus::Active, $vinculo->fresh()->status);

        $this->actingAs($helena);
        Livewire::test(CareIndex::class)->call('revoke', $vinculo->id);
        $this->assertSame(CaregiverStatus::Revoked, $vinculo->fresh()->status);
    }

    public function test_nao_convida_quem_ja_divide_o_perfil(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();

        $this->expectExceptionMessage('já divide o perfil');
        app(CaregiverService::class)->inviteFollower($membroMarcelo, $marcelo, 'Helena', $helena->email, CaregiverAccess::Read);
    }

    public function test_o_painel_mostra_quem_me_acompanha_e_convida_sem_assinatura(): void
    {
        Mail::fake();
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();

        $this->actingAs($marcelo);
        Livewire::test(CareIndex::class)
            ->assertSee('Quem me acompanha')
            ->set('followName', 'Filho Paulo')->set('followEmail', 'paulo@exemplo.com')->set('followAccess', 'read')
            ->call('inviteFollower', $membroMarcelo->id)
            ->assertHasNoErrors()
            ->assertSee('Filho Paulo');

        Mail::assertQueued(CaregiverInviteMail::class);
        $this->assertSame(1, ProfileCaregiver::query()->where('member_id', $membroMarcelo->id)->count());
    }

    public function test_o_lembrete_da_minha_consulta_vai_a_quem_me_acompanha_e_o_da_helena_nao(): void
    {
        Notification::fake();
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);

        $contexto = app(ProfileContext::class);
        $contexto->set($perfil, $membroMarcelo);
        $this->actingAs($marcelo);
        $amanha = CarbonImmutable::now()->addDay()->setTime(10, 0);
        app(HealthAppointmentService::class)->create($membroMarcelo, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Cardio', 'scheduled_at' => $amanha], $membroMarcelo);
        $contexto->clear();

        app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);

        Notification::assertSentTo($paulo, HealthAppointmentUpcoming::class);
        Notification::assertSentTo($marcelo, HealthAppointmentUpcoming::class);

        // Consulta da Helena: o Paulo não é avisado.
        Notification::fake();
        $contexto->set($perfil, $membroHelena);
        $this->actingAs($helena);
        app(HealthAppointmentService::class)->create($membroHelena, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Derma', 'scheduled_at' => $amanha->addHour()], $membroHelena);
        $contexto->clear();

        app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);
        Notification::assertNotSentTo($paulo, HealthAppointmentUpcoming::class, fn ($n) => $n->title === 'Derma');
    }

    public function test_documento_liberado_aparece_so_se_for_meu_ou_da_familia(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);

        $contexto = app(ProfileContext::class);
        $contexto->set($perfil, $membroMarcelo);
        $this->actingAs($marcelo);
        $meu = $this->documento('Meu exame', $membroMarcelo->id, true);
        $familia = $this->documento('Plano de saúde', null, true);
        $this->documento('Exame da Helena', $membroHelena->id, true);
        $this->documento('Meu RG (não liberado)', $membroMarcelo->id, false);
        $contexto->clear();

        $this->actingAs($paulo);
        $contexto->set($perfil, null, false, ProfileCaregiver::query()->active()->where('user_id', $paulo->id)->first());

        $this->assertEqualsCanonicalizing([$meu->id, $familia->id], Document::query()->pluck('id')->all());
    }

    public function test_sem_o_consentimento_de_saude_de_quem_e_acompanhado_a_area_fecha(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);

        $this->actingAs($paulo)->post(route('care.open', $perfil->id));
        $this->get(route('health.card.index'))->assertOk();

        // O consentimento que vale é o de quem é acompanhado, não o do cuidador.
        $marcelo->revokeHealthConsent();
        $this->get(route('health.card.index'))->assertRedirect(route('care.index'));
    }

    public function test_revogar_corta_o_acesso_e_o_dono_ou_membro_nao_entra_por_esta_rota(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);
        $vinculo = ProfileCaregiver::query()->where('user_id', $paulo->id)->sole();

        $this->actingAs($paulo)->post(route('care.open', $perfil->id))->assertRedirect();
        app(CaregiverService::class)->revoke($vinculo);
        $this->get(route('health.card.index'))->assertNotFound();

        // A rota de "entrar em quem acompanho" não serve para o dono entrar no próprio perfil.
        $this->actingAs($marcelo)->post(route('care.open', $perfil->id))->assertNotFound();
    }

    public function test_o_link_do_aviso_abre_a_pessoa_para_o_cuidador_e_a_tela_de_sempre_para_o_dono(): void
    {
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();
        $paulo = $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read);

        $esperado = route('care.goto', ['profile' => $perfil->id, 'destino' => 'agenda']);

        $this->assertSame($esperado, \App\Support\CareLinks::to(null, 'agenda', 'health.appointments.index', $perfil->id, $paulo));
        $this->assertSame(route('health.appointments.index'), \App\Support\CareLinks::to(null, 'agenda', 'health.appointments.index', $perfil->id, $marcelo));
    }

    public function test_a_copia_dos_meus_dados_lista_quem_me_acompanha(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$marcelo, $helena, $perfil, $membroMarcelo] = $this->casal();
        $this->seguidor($perfil, $membroMarcelo, CaregiverAccess::Read, 'Filho Paulo');

        $pedido = app(\App\Services\Privacy\UserDataExporter::class)->export($marcelo);

        $zip = new \ZipArchive;
        $zip->open(\Illuminate\Support\Facades\Storage::disk('local')->path($pedido->path));
        $conta = (string) $zip->getFromName('conta.json');
        $zip->close();

        $this->assertStringContainsString('quem_me_acompanha', $conta);
        $this->assertStringContainsString('Filho Paulo', $conta);
    }

    // ----------------------------------------------------------------------

    /** @return array{0: User, 1: User, 2: FinancialProfile, 3: ProfileMember, 4: ProfileMember} Marcelo (titular, sem assinatura), Helena, perfil e as duas cadeiras */
    private function casal(): array
    {
        $marcelo = User::factory()->create(['name' => 'Marcelo']);
        $helena = User::factory()->create(['name' => 'Helena']);
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $marcelo->id]);
        $membroMarcelo = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $marcelo->id, 'name' => 'Marcelo']);
        $membroHelena = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $helena->id, 'name' => 'Helena']);

        return [$marcelo, $helena, $perfil, $membroMarcelo, $membroHelena];
    }

    private function seguidor(FinancialProfile $perfil, ProfileMember $membro, CaregiverAccess $nivel, string $nome = 'Paulo'): User
    {
        $user = User::factory()->create(['name' => $nome]);
        ProfileCaregiver::query()->create([
            'profile_id' => $perfil->id, 'member_id' => $membro->id, 'user_id' => $user->id, 'invited_email' => $user->email, 'invited_name' => $nome,
            'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        return $user;
    }

    private function documento(string $titulo, ?string $membroId, bool $liberado): Document
    {
        return Document::create([
            'member_id' => $membroId, 'category' => 'other', 'title' => $titulo, 'original_filename' => 'a.pdf', 'storage_path' => 'documentos-pessoais/'.$titulo.'.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'shared_with_caregivers' => $liberado,
        ]);
    }
}

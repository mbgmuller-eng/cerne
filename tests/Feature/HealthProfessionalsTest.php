<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\HealthAppointmentKind;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Exams\HealthExamBuilder;
use App\Livewire\Health\Exams\HealthExamResultForm;
use App\Livewire\Health\Exams\HealthExamShow;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Livewire\Health\HealthCardIndex;
use App\Livewire\Health\HealthProfessionalIndex;
use App\Livewire\Health\HealthProgramPanel;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Models\HealthFollowup;
use App\Models\HealthMedication;
use App\Models\HealthProfessional;
use App\Models\HealthProfessionalLink;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Services\HealthExamService;
use App\Services\HealthFollowupService;
use App\Services\HealthProfessionalService;
use App\Services\Privacy\AccountDeletionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cerne Saúde: médicos e profissionais guardados. Cada um é ligado às pessoas que atende, e é essa ligação que decide quem o vê. Os registros
 * guardam o nome como foi escrito e ganham a ligação com a ficha.
 */
class HealthProfessionalsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $joao;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroJoao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00'));
        config(['cerne.care.enabled' => true]);

        $this->ana = User::factory()->create(['name' => 'Ana Teste']);
        $this->joao = User::factory()->create(['name' => 'João Teste']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'name' => 'Ana Teste', 'role' => MemberRole::Primary]);
        $this->membroJoao = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->joao->id, 'name' => 'João Teste', 'role' => MemberRole::Secondary]);

        Subscription::create([
            'user_id' => $this->ana->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
    }

    public function test_cadastro_exige_nome_e_quem_atende_e_os_fixos_vem_primeiro(): void
    {
        $servico = app(HealthProfessionalService::class);
        $this->guardar('Bruna Exemplo');
        $this->guardar('Zeca Exemplo', fixo: true);
        $this->guardar('Álvaro Exemplo');

        $this->assertSame(['Zeca Exemplo', 'Álvaro Exemplo', 'Bruna Exemplo'], $servico->visible()->pluck('name')->all());

        foreach ([[' ', [$this->membroAna->id]], ['Nome', []], ['Nome', [(string) \Illuminate\Support\Str::uuid()]]] as [$nome, $pessoas]) {
            try {
                $servico->save(null, ['name' => $nome], $pessoas, null);
                $this->fail('deveria recusar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_resolver_reaproveita_pelo_nome_liga_a_outra_pessoa_e_so_cria_quando_pedem(): void
    {
        $servico = app(HealthProfessionalService::class);
        $paula = $this->guardar('Dra. Paula Exemplo');

        $this->assertSame($paula->id, $servico->resolve($this->membroAna->id, null, 'dra paula exemplo', false, [], null));
        $this->assertSame($paula->id, $servico->resolve($this->membroAna->id, $paula->id, 'Dra. Paula Exemplo', true, [], null));
        $this->assertNull($servico->resolve($this->membroAna->id, null, 'Dr. Novo', false, [], null), 'sem pedir, não guarda');
        $this->assertNull($servico->resolve($this->membroAna->id, $paula->id, '', true, [], null));
        $this->assertSame(1, HealthProfessional::query()->count());

        // Escolhido na lista, mas a pessoa trocou o nome depois: vale o que está escrito.
        $outro = $servico->resolve($this->membroAna->id, $paula->id, 'Dr. Novo', true, ['specialty' => 'Cardiologia'], null);
        $this->assertNotSame($paula->id, $outro);
        $this->assertSame('Cardiologia', HealthProfessional::query()->findOrFail($outro)->specialty);

        // Mesmo nome para o cônjuge: liga a mesma ficha, não duplica.
        $this->assertSame($paula->id, $servico->resolve($this->membroJoao->id, null, 'Dra. Paula Exemplo', true, [], null));
        $this->assertSame(2, HealthProfessionalLink::query()->where('professional_id', $paula->id)->count());
        $this->assertSame(2, HealthProfessional::query()->count());
    }

    public function test_nomes_ja_digitados_sao_sugeridos_e_guardar_junta_o_historico(): void
    {
        $this->consulta('Dr. Antigo Exemplo');
        app(HealthExamService::class)->createOrder($this->membroAna, ['title' => 'Exames', 'requested_by' => 'dr antigo exemplo', 'items' => [['name' => 'TSH']]], null, null, null);
        $this->consulta('Dr. Do João', $this->membroJoao);
        $servico = app(HealthProfessionalService::class);

        $sugestoes = collect($servico->typedNames());
        // As duas grafias do mesmo nome viram uma sugestão só.
        $this->assertSame(['Dr. Antigo Exemplo', 'Dr. Do João'], $sugestoes->pluck('name')->sort()->values()->all());
        $this->assertSame('1 consulta, 1 pedido de exame', $sugestoes->firstWhere('member_id', $this->membroAna->id)['where']);
        $this->assertSame(0, HealthProfessional::query()->count(), 'nada é guardado sozinho');

        Livewire::test(HealthProfessionalIndex::class)
            ->assertSee('Nomes que você já digitou')
            ->call('keepTyped', $sugestoes->firstWhere('member_id', $this->membroAna->id)['key'])
            ->assertSee('Histórico');

        $ficha = HealthProfessional::query()->sole();
        $this->assertSame($ficha->id, HealthAppointment::query()->where('member_id', $this->membroAna->id)->sole()->professional_id);
        $this->assertSame($ficha->id, HealthExamRequest::query()->sole()->professional_id);
        $this->assertNull(HealthAppointment::query()->where('member_id', $this->membroJoao->id)->sole()->professional_id);
        $this->assertCount(2, $servico->history($ficha));
        $this->assertSame(['Dr. Do João'], collect($servico->typedNames())->pluck('name')->all());
    }

    public function test_excluir_a_ficha_nao_muda_os_registros(): void
    {
        $paula = $this->guardar('Dra. Paula Exemplo');
        $consulta = $this->consulta('Dra. Paula Exemplo');
        app(HealthProfessionalService::class)->save($paula, ['name' => 'Dra. Paula Exemplo', 'address' => 'Rua Nova, 1'], [$this->membroAna->id], null);
        $this->assertSame($paula->id, $consulta->fresh()->professional_id);
        $this->assertNull($consulta->fresh()->address, 'mudar a ficha não reescreve a consulta');

        app(HealthProfessionalService::class)->remove($paula);

        $this->assertSame(0, HealthProfessional::withoutGlobalScopes()->count());
        $this->assertSame('Dra. Paula Exemplo', $consulta->fresh()->professional_name);
        $this->assertNull($consulta->fresh()->professional_id);
    }

    // --- Quem vê ----------------------------------------------------------------------------------

    public function test_o_casal_ve_os_dos_dois_e_o_consultor_nao_ve_nada(): void
    {
        $this->guardar('Da Ana');
        $this->guardar('Do João', [$this->membroJoao]);

        $contexto = app(ProfileContext::class);
        $this->actingAs($this->joao);
        $contexto->set($this->perfil, $this->membroJoao);
        $this->assertSame(2, HealthProfessional::query()->count());

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);
        $this->actingAs($consultor);
        $contexto->set($this->perfil, null, true);
        $this->assertSame(0, HealthProfessional::query()->count());
        $this->assertSame(0, HealthProfessionalLink::query()->count());
        Livewire::test(HealthProfessionalIndex::class)->assertForbidden();

        $contexto->clear();
        $this->assertSame(0, HealthProfessional::query()->count(), 'sem perfil ativo, fecha');
    }

    public function test_quem_acompanha_so_a_ana_nao_ve_os_do_joao_e_editar_nao_mexe_no_vinculo_escondido(): void
    {
        $compartilhado = $this->guardar('Compartilhado', [$this->membroAna, $this->membroJoao]);
        $this->guardar('Só do João', [$this->membroJoao]);
        [$filha, $acesso] = $this->acompanhante(CaregiverAccess::Edit, $this->membroAna);

        $this->actingAs($filha);
        app(ProfileContext::class)->set($this->perfil, null, false, $acesso);
        $servico = app(HealthProfessionalService::class);

        $this->assertSame(['Compartilhado'], $servico->visible()->pluck('name')->all());
        $this->assertSame([$this->membroAna->id], $servico->visible()->first()->links->pluck('member_id')->all());
        Livewire::test(HealthProfessionalIndex::class)->assertSee('Compartilhado')->assertDontSee('Só do João')->assertDontSee('João');

        // Edita o contato: o vínculo com o João continua.
        $servico->save($servico->visible()->first(), ['name' => 'Compartilhado', 'phone' => '(41) 3000-0000'], [$this->membroAna->id], null);
        $this->assertSame(2, HealthProfessionalLink::withoutGlobalScopes()->where('professional_id', $compartilhado->id)->count());

        // Não registrou o vínculo: a regra de sempre (só apaga o que registrou) a impede de tirar da lista.
        Livewire::test(HealthProfessionalIndex::class)->call('remove', $compartilhado->id)->assertHasErrors('ficha');
        $this->assertSame(2, HealthProfessionalLink::withoutGlobalScopes()->where('professional_id', $compartilhado->id)->count());

        // O que ela mesma registrou, tira.
        $dela = $servico->save(null, ['name' => 'Cadastrado pela filha'], [$this->membroAna->id], null);
        $servico->remove($dela);
        $this->assertNull(HealthProfessional::withoutGlobalScopes()->find($dela->id));

        // Pessoa que ela não acompanha não pode ser marcada.
        $this->expectException(InvalidArgumentException::class);
        $servico->save(null, ['name' => 'Tentativa'], [$this->membroJoao->id], null);
    }

    public function test_quem_so_le_nao_grava_e_a_tela_abre_na_pessoa_acompanhada(): void
    {
        app(ProfileContext::class)->clear();
        $pessoa = app(CareProfileService::class)->create($this->ana, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $contexto = app(ProfileContext::class);
        $contexto->set($pessoa, null);
        $ficha = app(HealthProfessionalService::class)->save(null, ['name' => 'Geriatra Exemplo'], [$membro->id], null);

        $leitor = User::factory()->create();
        $acesso = ProfileCaregiver::query()->create([
            'profile_id' => $pessoa->id, 'user_id' => $leitor->id, 'invited_email' => $leitor->email, 'invited_name' => $leitor->name,
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);
        $this->actingAs($leitor);
        // Relido do banco: os níveis de acesso têm padrões que o objeto recém-criado ainda não conhece.
        $contexto->set($pessoa, null, false, $acesso->fresh());

        foreach ([
            fn () => app(HealthProfessionalService::class)->save(null, ['name' => 'Novo'], [$membro->id], null),
            fn () => app(HealthProfessionalService::class)->toggleFavorite($ficha),
            fn () => app(HealthProfessionalService::class)->remove($ficha),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('quem só lê não grava');
            } catch (HttpException|AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, HealthProfessional::withoutGlobalScopes()->count());
        $this->assertFalse((bool) HealthProfessional::withoutGlobalScopes()->sole()->is_favorite);

        $contexto->clear();
        $this->actingAs($leitor)->post(route('care.open', $pessoa->id))->assertRedirect();
        $this->get(route('health.professionals.index'))->assertOk()->assertSee('Geriatra Exemplo');
    }

    // --- Nos formulários -----------------------------------------------------------------------------

    public function test_na_consulta_escolher_preenche_e_nome_novo_e_guardado(): void
    {
        $caio = app(HealthProfessionalService::class)->save(null, ['name' => 'Dr. Caio Exemplo', 'specialty' => 'Cardiologia', 'place' => 'Instituto', 'address' => 'Av. Central, 500', 'phone' => '(41) 3000-1111'], [$this->membroAna->id], null);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')->set('memberId', $this->membroAna->id)
            ->call('pickProfessional', $caio->id)
            ->assertSet('professionalName', 'Dr. Caio Exemplo')->assertSet('specialty', 'Cardiologia')->assertSet('location', 'Instituto')->assertSet('phone', '(41) 3000-1111')
            ->assertDontSee('Guardar este profissional')
            ->set('scheduledDate', '2026-12-10')->set('scheduledTime', '09:00')->call('save')->assertHasNoErrors();

        $this->assertSame($caio->id, HealthAppointment::query()->sole()->professional_id);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')->set('memberId', $this->membroAna->id)
            ->set('professionalName', 'Dra. Nova Exemplo')->set('specialty', 'Dermatologia')->assertSee('Guardar este profissional')
            ->set('scheduledDate', '2026-12-11')->set('scheduledTime', '10:00')->call('save')->assertHasNoErrors();

        $nova = HealthProfessional::query()->get()->firstWhere('name', 'Dra. Nova Exemplo');
        $this->assertSame('Dermatologia', $nova->specialty);

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')->set('memberId', $this->membroAna->id)
            ->set('professionalName', 'Não guardar')->set('keepProfessional', false)
            ->set('scheduledDate', '2026-12-12')->set('scheduledTime', '10:00')->call('save')->assertHasNoErrors();
        $this->assertSame(2, HealthProfessional::query()->count());

        // Pela ficha: o formulário já abre preenchido.
        Livewire::withQueryParams(['profissional' => $caio->id])->test(HealthAppointmentIndex::class)
            ->assertSet('showForm', true)->assertSet('professionalName', 'Dr. Caio Exemplo');

        // Profissional que não atende a pessoa do formulário não pode ser escolhido para ela.
        Livewire::test(HealthAppointmentIndex::class)->call('newAppointment')->set('memberId', $this->membroJoao->id)->call('pickProfessional', $caio->id)->assertNotFound();
    }

    public function test_pedido_de_exame_laudo_programa_e_remedio_usam_o_mesmo_cadastro(): void
    {
        Livewire::test(HealthExamBuilder::class)
            ->call('toggle', 'TSH')->call('chooseCategory', 'cardio')->call('toggle', 'Holter 24 horas')
            ->set('requestedBy', 'Dr. Pedido Exemplo')->assertSee('Guardar este profissional')
            ->call('save')->assertHasNoErrors();

        $ficha = HealthProfessional::query()->sole();
        $this->assertSame([$ficha->id, $ficha->id], HealthExamRequest::query()->pluck('professional_id')->all(), 'os dois pedidos ficam ligados à mesma ficha');
        Livewire::withQueryParams(['profissional' => $ficha->id])->test(HealthExamBuilder::class)->assertSet('requestedBy', 'Dr. Pedido Exemplo');

        $pedido = HealthExamRequest::query()->first();
        Livewire::test(HealthExamShow::class, ['pedido' => $pedido->id])
            ->call('open', 'edit')->set('requestedBy', 'Outro Médico Exemplo')->set('keepProfessional', false)->call('saveEdit')->assertHasNoErrors();
        $this->assertNull($pedido->fresh()->professional_id);

        // No laudo, quem assina só é guardado se a pessoa marcar.
        Livewire::test(HealthExamResultForm::class)
            ->assertSet('keepProfessional', false)
            ->set('kind', 'report')->set('performedOn', '2026-11-20')->set('examName', 'Eco')->set('conclusion', 'Normal.')->set('doctor', 'Radiologista Exemplo')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(HealthExamReport::query()->sole()->professional_id);
        $this->assertSame(1, HealthProfessional::query()->count());

        $acompanhamento = app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Ombro', 'status' => 'treating', 'started_on' => '2026-11-01'], null);
        Livewire::test(HealthProgramPanel::class, ['followupId' => $acompanhamento->id])
            ->call('open', 'program')->set('guidedBy', 'Fisio Exemplo')->call('saveProgram')->assertHasNoErrors();
        $this->assertNotNull(HealthFollowup::query()->findOrFail($acompanhamento->id)->professional_id);

        Livewire::test(HealthCardIndex::class)
            ->call('newMedication', $this->membroAna->id)->set('medName', 'Remédio')->set('medPrescriber', 'Dr. Pedido Exemplo')
            ->call('saveMedication')->assertHasNoErrors();
        $this->assertSame($ficha->id, HealthMedication::query()->sole()->professional_id);

        $this->assertSame(
            ['Pedido de exames', 'Receitou'],
            collect(app(HealthProfessionalService::class)->history($ficha))->pluck('label')->sort()->values()->all(),
        );
    }

    public function test_tela_de_profissionais_cadastra_com_o_ao_salvar_e_excluir_a_conta_leva_tudo(): void
    {
        Livewire::test(HealthProfessionalIndex::class)
            ->call('newProfessional')->assertSet('personIds', [$this->membroAna->id])->assertSee('O profissional aparece nas consultas')
            ->set('name', 'Dra. Tela Exemplo')->set('personIds', [])->call('save')->assertHasErrors('personIds')
            ->set('personIds', [$this->membroAna->id, $this->membroJoao->id])->set('favorite', true)->call('save')->assertHasNoErrors()
            ->assertSee('Atende Ana Teste')->assertSee('Atende João Teste')
            ->set('search', 'nada disso')->assertSee('Nada encontrado');

        app(ProfileContext::class)->clear();
        app(AccountDeletionService::class)->delete($this->ana);

        $this->assertSame(0, DB::table('health_professionals')->count());
        $this->assertSame(0, DB::table('health_professional_links')->count());
    }

    // ---------------------------------------------------------------------------------------------------

    /** @param  list<ProfileMember>  $pessoas */
    private function guardar(string $nome, array $pessoas = [], bool $fixo = false): HealthProfessional
    {
        return app(HealthProfessionalService::class)->save(null, ['name' => $nome, 'is_favorite' => $fixo], array_map(fn (ProfileMember $m) => $m->id, $pessoas ?: [$this->membroAna]), null);
    }

    private function consulta(string $profissional, ?ProfileMember $membro = null): HealthAppointment
    {
        return app(HealthAppointmentService::class)->create($membro ?? $this->membroAna, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Consulta', 'professional_name' => $profissional, 'scheduled_at' => '2026-12-01 10:00'], null);
    }

    /** @return array{0: User, 1: ProfileCaregiver} */
    private function acompanhante(CaregiverAccess $nivel, ProfileMember $de): array
    {
        $usuario = User::factory()->create();
        $acesso = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $de->id, 'user_id' => $usuario->id, 'invited_email' => $usuario->email, 'invited_name' => $usuario->name,
            'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(), 'can_view_health' => true,
        ]);

        return [$usuario, $acesso];
    }
}

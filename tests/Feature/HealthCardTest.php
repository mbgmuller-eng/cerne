<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\HealthMedicationChangeType;
use App\Livewire\Health\HealthCardIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthAllergy;
use App\Models\HealthCondition;
use App\Models\HealthMedication;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ficha de Saúde (fase A): tipo sanguíneo, alergia, doença e remédio —
 * ao contrário da Academia, os DOIS do casal enxergam (CoupleHealthScope),
 * nunca o consultor/corretor.
 */
class HealthCardTest extends TestCase
{
    use RefreshDatabase;

    private User $usuarioTitular;
    private User $usuarioConjuge;
    private FinancialProfile $perfil;
    private ProfileMember $titular;
    private ProfileMember $conjuge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioTitular->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);

        $this->usuarioConjuge = User::factory()->create();
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioConjuge->id, 'name' => 'Helen']);
    }

    // ---------------------------------------------------------------- escopo

    public function test_conjuge_ve_a_ficha_do_outro(): void
    {
        $this->entrarComo($this->titular);
        app(HealthCardService::class)->addAllergy($this->titular, 'Dipirona', $this->titular);

        $this->entrarComo($this->conjuge);
        self::assertSame(['Dipirona'], HealthAllergy::query()->pluck('description')->all());
    }

    public function test_consultor_vinculado_nao_ve_nada(): void
    {
        $this->entrarComo($this->titular);
        app(HealthCardService::class)->addAllergy($this->titular, 'Dipirona', $this->titular);

        app(ProfileContext::class)->set($this->perfil, member: null, asConsultant: true);

        self::assertSame(0, HealthAllergy::query()->count());
    }

    public function test_sem_perfil_ativo_nao_ha_nada(): void
    {
        $this->entrarComo($this->titular);
        app(HealthCardService::class)->addAllergy($this->titular, 'Dipirona', $this->titular);

        app(ProfileContext::class)->clear();

        self::assertSame(0, HealthAllergy::query()->count());
    }

    public function test_outro_perfil_nao_enxerga(): void
    {
        $this->entrarComo($this->titular);
        app(HealthCardService::class)->addAllergy($this->titular, 'Dipirona', $this->titular);

        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id]);
        app(ProfileContext::class)->set($outroPerfil, $outroMembro);

        self::assertSame(0, HealthAllergy::query()->count());
    }

    public function test_criar_sem_member_id_explicito_e_recusado(): void
    {
        $this->entrarComo($this->titular);

        $this->expectException(LogicException::class);
        HealthAllergy::create(['description' => 'Dipirona']);
    }

    // ---------------------------------------------------------------- serviço

    public function test_marcelo_registra_alergia_da_helen(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);

        $alergia = $service->addAllergy($this->conjuge, 'Penicilina', $this->titular);

        self::assertSame($this->conjuge->id, $alergia->member_id);
        self::assertSame($this->titular->id, $alergia->created_by_member_id);
    }

    public function test_definir_tipo_sanguineo(): void
    {
        $this->entrarComo($this->titular);
        $card = app(HealthCardService::class)->setBloodType($this->titular, 'O+', $this->titular);

        self::assertSame('O+', $card->fresh()->blood_type);
        self::assertSame($this->titular->id, $card->fresh()->updated_by_member_id);
    }

    public function test_adicionar_remedio_registra_inicio_no_historico(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);

        $remedio = $service->addMedication($this->titular, ['name' => 'Losartana', 'dose' => '50mg'], $this->titular);

        self::assertSame(1, $remedio->changes()->count());
        self::assertSame(HealthMedicationChangeType::Started, $remedio->changes->first()->change_type);
    }

    public function test_trocar_dose_registra_historico_com_valor_antigo_e_novo(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);
        $remedio = $service->addMedication($this->titular, ['name' => 'Losartana', 'dose' => '50mg'], $this->titular);

        $service->updateMedication($remedio, ['dose' => '100mg'], $this->conjuge);

        $mudanca = $remedio->changes()->first();
        self::assertSame(HealthMedicationChangeType::DoseChanged, $mudanca->change_type);
        self::assertSame('50mg', $mudanca->old_value);
        self::assertSame('100mg', $mudanca->new_value);
        self::assertSame($this->conjuge->id, $mudanca->changed_by_member_id);
    }

    public function test_suspender_e_retomar_registram_historico(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);
        $remedio = $service->addMedication($this->titular, ['name' => 'Losartana'], $this->titular);

        $service->updateMedication($remedio, ['is_active' => false], $this->titular);
        self::assertFalse($remedio->fresh()->is_active);
        self::assertSame(HealthMedicationChangeType::Suspended, $remedio->changes()->first()->change_type);

        $service->updateMedication($remedio, ['is_active' => true], $this->titular);
        self::assertTrue($remedio->fresh()->is_active);
        self::assertSame(HealthMedicationChangeType::Resumed, $remedio->changes()->first()->change_type);
    }

    public function test_editar_sem_mudar_dose_nem_status_nao_cria_historico(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);
        $remedio = $service->addMedication($this->titular, ['name' => 'Losartana', 'dose' => '50mg'], $this->titular);

        $service->updateMedication($remedio, ['reason' => 'Pressão alta'], $this->titular);

        self::assertSame(1, $remedio->changes()->count()); // só o "iniciado"
        self::assertSame('Pressão alta', $remedio->fresh()->reason);
    }

    public function test_nota_manual_entra_no_historico_sem_mudar_o_remedio(): void
    {
        $this->entrarComo($this->titular);
        $service = app(HealthCardService::class);
        $remedio = $service->addMedication($this->titular, ['name' => 'Losartana', 'dose' => '50mg'], $this->titular);

        $service->addMedicationNote($remedio, 'Tolerou bem a dose nova.', $this->conjuge);

        $nota = $remedio->changes()->first();
        self::assertSame(HealthMedicationChangeType::Note, $nota->change_type);
        self::assertSame('Tolerou bem a dose nova.', $nota->note);
        self::assertSame('50mg', $remedio->fresh()->dose); // não mexeu na dose
    }

    // ---------------------------------------------------------------- tela

    public function test_tela_mostra_os_dois_membros(): void
    {
        $this->entrarComo($this->titular);

        $this->get(route('health.card.index'))
            ->assertOk()
            ->assertSee('Marcelo')
            ->assertSee('Helen');
    }

    public function test_helen_edita_a_ficha_do_marcelo_pela_tela(): void
    {
        $this->entrarComo($this->conjuge);

        Livewire::test(HealthCardIndex::class)
            ->set("newAllergyText.{$this->titular->id}", 'Dipirona')
            ->call('addAllergy', $this->titular->id)
            ->assertHasNoErrors();

        $alergia = HealthAllergy::query()->firstOrFail();
        self::assertSame($this->titular->id, $alergia->member_id);
        self::assertSame($this->conjuge->id, $alergia->created_by_member_id);
    }

    public function test_definir_tipo_sanguineo_pela_tela(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthCardIndex::class)
            ->set("bloodTypeDrafts.{$this->titular->id}", 'A+')
            ->assertHasNoErrors();

        self::assertSame('A+', app(HealthCardService::class)->cardFor($this->titular)->fresh()->blood_type);
    }

    public function test_regenerar_token_atualiza_o_link_de_compartilhar_na_tela(): void
    {
        // Confirma que o HTML renderizado nunca fica com o token antigo —
        // pré-requisito do wire:key no bloco do QR Code (ver blade): sem
        // ele, o morph do navegador preserva o x-data do Alpine entre
        // atualizações (comportamento padrão do Livewire+Alpine) e o link
        // fica apontando pro token ANTIGO depois de "Gerar novo código",
        // quieto, sem erro nenhum — isso o teste de servidor não alcança,
        // só o comportamento documentado do wire:key garante.
        $this->entrarComo($this->titular);
        $tokenAntigo = app(HealthCardService::class)->ensureEmergencyToken($this->titular)->emergency_token;

        $componente = Livewire::test(HealthCardIndex::class);
        self::assertStringContainsString($tokenAntigo, $componente->html());

        $componente->call('regenerateEmergencyToken', $this->titular->id);
        $tokenNovo = app(HealthCardService::class)->cardFor($this->titular)->fresh()->emergency_token;

        self::assertNotSame($tokenAntigo, $tokenNovo);
        self::assertStringContainsString($tokenNovo, $componente->html());
        self::assertStringNotContainsString($tokenAntigo, $componente->html());
    }

    public function test_criar_e_editar_remedio_pela_tela(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthCardIndex::class)
            ->call('newMedication', $this->titular->id)
            ->set('medName', 'Losartana')
            ->set('medDose', '50mg')
            ->call('saveMedication')
            ->assertHasNoErrors();

        $remedio = HealthMedication::query()->firstOrFail();
        self::assertSame('Losartana', $remedio->name);

        Livewire::test(HealthCardIndex::class)
            ->call('editMedication', $remedio->id)
            ->assertSet('medName', 'Losartana')
            ->set('medDose', '100mg')
            ->call('saveMedication')
            ->assertHasNoErrors();

        self::assertSame('100mg', $remedio->fresh()->dose);
        self::assertSame(2, $remedio->changes()->count());
    }

    public function test_nome_obrigatorio_no_remedio(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthCardIndex::class)
            ->call('newMedication', $this->titular->id)
            ->set('medName', '')
            ->call('saveMedication')
            ->assertHasErrors(['medName']);
    }

    public function test_membro_de_outro_perfil_da_404(): void
    {
        $this->entrarComo($this->titular);
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(HealthCardIndex::class)->call('newMedication', $outroMembro->id);
    }

    public function test_consultor_leva_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.card.index'))
            ->assertForbidden();
    }

    public function test_corretor_e_redirecionado(): void
    {
        $corretor = User::factory()->broker()->create();

        $this->actingAs($corretor)
            ->get(route('health.card.index'))
            ->assertRedirect(route('consultant.portfolio.insurance'));
    }

    public function test_menu_saude_mostra_ficha_pro_cliente_e_esconde_do_consultor(): void
    {
        $this->entrarComo($this->titular);
        $this->get(route('dashboard'))->assertOk()->assertSee('Ficha de Saúde');

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ficha de Saúde');
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $usuario = $membro->id === $this->titular->id ? $this->usuarioTitular : $this->usuarioConjuge;
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }
}

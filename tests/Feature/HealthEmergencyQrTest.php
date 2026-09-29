<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthCard;
use App\Models\HealthMedication;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * QR Code de emergência: token opaco em vez de login, porque quem escaneia
 * (socorrista, familiar sem conta) não tem como logar — ver
 * HealthCardService::emergencyPayload(). A página pública só pode mostrar
 * o mínimo pra emergência, nunca o histórico completo de remédio.
 */
class HealthEmergencyQrTest extends TestCase
{
    use RefreshDatabase;

    private User $usuarioTitular;
    private FinancialProfile $perfil;
    private ProfileMember $titular;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuarioTitular->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);
    }

    public function test_pagina_de_emergencia_nao_exige_login(): void
    {
        $service = app(HealthCardService::class);
        $this->ativarContextoSemLogar($this->titular);
        $service->setBloodType($this->titular, 'O+', $this->titular);
        $service->addAllergy($this->titular, 'Dipirona', $this->titular);
        $card = $service->ensureEmergencyToken($this->titular);

        // Auth::logout() + Contexto limpo: simula de verdade um visitante
        // que nunca teve conta no Cerne — actingAs() persistiria até a
        // próxima chamada, e sozinho não provaria nada sobre a rota pública.
        Auth::logout();
        app(ProfileContext::class)->clear();

        $this->get(route('health.emergency.show', $card->fresh()->emergency_token))
            ->assertOk()
            ->assertSee('Marcelo')
            ->assertSee('O+')
            ->assertSee('Dipirona');
    }

    public function test_pagina_de_emergencia_nao_mostra_historico_de_remedio(): void
    {
        $service = app(HealthCardService::class);
        $this->ativarContextoSemLogar($this->titular);
        $service->addMedication($this->titular, ['name' => 'Losartana', 'dose' => '50mg'], $this->titular);
        $suspenso = $service->addMedication($this->titular, ['name' => 'Amoxicilina', 'dose' => '500mg'], $this->titular);
        $service->updateMedication($suspenso, ['is_active' => false], $this->titular);
        $card = $service->ensureEmergencyToken($this->titular);

        Auth::logout();
        app(ProfileContext::class)->clear();

        $resposta = $this->get(route('health.emergency.show', $card->fresh()->emergency_token))->assertOk();

        $resposta->assertSee('Losartana');
        $resposta->assertDontSee('Amoxicilina'); // suspenso: não é uso atual
        $resposta->assertDontSee('iniciado', escape: false); // rótulo do histórico de HealthMedicationChangeType
    }

    public function test_token_invalido_da_404(): void
    {
        $this->get(route('health.emergency.show', 'token-que-nao-existe'))->assertNotFound();
    }

    public function test_regenerar_token_invalida_o_antigo(): void
    {
        $service = app(HealthCardService::class);
        $this->ativarContextoSemLogar($this->titular);
        $cardAntigo = $service->ensureEmergencyToken($this->titular);
        $tokenAntigo = $cardAntigo->fresh()->emergency_token;

        $novoCard = $service->regenerateEmergencyToken($this->titular, $this->titular);

        self::assertNotSame($tokenAntigo, $novoCard->emergency_token);

        Auth::logout();
        app(ProfileContext::class)->clear();

        $this->get(route('health.emergency.show', $tokenAntigo))->assertNotFound();
        $this->get(route('health.emergency.show', $novoCard->emergency_token))->assertOk();
    }

    public function test_imagem_do_qrcode_exige_login_e_e_da_propria_pessoa(): void
    {
        $this->get(route('health.qrcode.show', $this->titular->id))->assertRedirect(route('login'));

        $this->entrarComo($this->titular);
        $this->get(route('health.qrcode.show', $this->titular->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        self::assertNotNull(HealthCard::query()->where('member_id', $this->titular->id)->first()->emergency_token);
    }

    public function test_consultor_nao_gera_qrcode(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.qrcode.show', $this->titular->id))
            ->assertForbidden();
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $this->actingAs($this->usuarioTitular);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    /** Monta o contexto de perfil sem autenticar ninguém — pra preparar dado como quem já tem sessão, sem contaminar a asserção seguinte de "visitante nunca logou". */
    private function ativarContextoSemLogar(ProfileMember $membro): void
    {
        app(ProfileContext::class)->set($this->perfil, $membro);
    }
}

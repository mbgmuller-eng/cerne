<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Revisão retroativa: cliente com apólices já cadastradas, ao autorizar um
 * corretor novo, escolhe quais delas liberar — diferente do consultor, que
 * já vê tudo assim que o vínculo fica ativo (ver ConsultantLinkController).
 */
class BrokerLinkPolicyReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_autorizacao_lista_as_apolices_do_cliente_quando_e_corretor(): void
    {
        [$corretor, $cliente, $perfil, $membro, $vinculo] = $this->criarPedidoDeCorretor();
        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Icatu Seguros']);

        $link = URL::temporarySignedRoute('link.show', now()->addDays(7), ['consultantClient' => $vinculo->id]);

        $this->actingAs($cliente)->get($link)->assertOk()->assertSee('Icatu Seguros');
    }

    public function test_autorizar_marcando_apolices_compartilha_so_as_escolhidas(): void
    {
        [$corretor, $cliente, $perfil, $membro, $vinculo] = $this->criarPedidoDeCorretor();
        $compartilhada = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Compartilhada']);
        $naoCompartilhada = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Nao compartilhada']);

        $this->actingAs($cliente)
            ->post(route('link.accept', $vinculo), [
                'apolices_compartilhadas' => [$compartilhada->id],
            ])
            ->assertRedirect(route('dashboard'));

        self::assertSame($corretor->id, $compartilhada->fresh()->broker_id);
        self::assertNull($naoCompartilhada->fresh()->broker_id);
        self::assertSame(ConsultantClientStatus::Active, $vinculo->fresh()->status);
    }

    public function test_autorizar_sem_marcar_nenhuma_nao_compartilha_nada(): void
    {
        [$corretor, $cliente, $perfil, $membro, $vinculo] = $this->criarPedidoDeCorretor();
        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $this->actingAs($cliente)
            ->post(route('link.accept', $vinculo))
            ->assertRedirect(route('dashboard'));

        self::assertNull($apolice->fresh()->broker_id);
        self::assertSame(ConsultantClientStatus::Active, $vinculo->fresh()->status);
    }

    public function test_id_de_apolice_de_outro_perfil_e_ignorado(): void
    {
        [$corretor, $cliente, , , $vinculo] = $this->criarPedidoDeCorretor();

        $outroTitular = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroTitular->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroTitular->id]);
        $apoliceAlheia = InsurancePolicy::factory()->for($outroPerfil, 'profile')->for($outroMembro, 'member')->create();

        $this->actingAs($cliente)
            ->post(route('link.accept', $vinculo), [
                'apolices_compartilhadas' => [$apoliceAlheia->id],
            ])
            ->assertRedirect(route('dashboard'));

        self::assertNull($apoliceAlheia->fresh()->broker_id);
    }

    public function test_apolice_privada_do_conjuge_nao_aparece_na_revisao(): void
    {
        [$corretor, $titular, $perfil] = $this->criarPedidoDeCorretor(criarSozinho: true);

        $membroTitular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        $oculta = InsurancePolicy::factory()->for($perfil, 'profile')->for($membroConjuge, 'member')
            ->create(['insurer_name' => 'Oculta do titular', 'is_private' => true]);

        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Pending,
        ]);

        $link = URL::temporarySignedRoute('link.show', now()->addDays(7), ['consultantClient' => $vinculo->id]);

        $this->actingAs($titular)->get($link)->assertOk()->assertDontSee('Oculta do titular');
    }

    public function test_vinculo_de_consultor_nao_mostra_lista_de_apolices(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['insurer_name' => 'Nao deve aparecer aqui']);

        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Pending,
        ]);

        $link = URL::temporarySignedRoute('link.show', now()->addDays(7), ['consultantClient' => $vinculo->id]);

        $this->actingAs($cliente)->get($link)->assertOk()->assertDontSee('Nao deve aparecer aqui');

        $this->actingAs($cliente)->post(route('link.accept', $vinculo))->assertRedirect(route('dashboard'));
        self::assertTrue($consultor->can('view', $perfil));
    }

    /** @return array{0: User, 1: User, 2: FinancialProfile, 3: ProfileMember, 4: ConsultantClient} */
    private function criarPedidoDeCorretor(bool $criarSozinho = false): array
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);

        if ($criarSozinho) {
            return [$corretor, $cliente, $perfil];
        }

        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Pending,
        ]);

        return [$corretor, $cliente, $perfil, $membro, $vinculo];
    }
}

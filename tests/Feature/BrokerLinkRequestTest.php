<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Mail\ClientInviteMail;
use App\Mail\ConsultantLinkRequestMail;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O corretor não tem a tela de carteira do consultor (PortfolioOverview) —
 * vincula cliente pela mesma tela que já usa pra ver seguros
 * (PortfolioInsurance), reaproveitando ConsultantLinkService::
 * inviteOrRequest() (ver ConsultantLinkTest, que cobre o mesmo
 * comportamento do lado do consultor).
 */
class BrokerLinkRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_corretor_convida_email_sem_conta_gera_convite_de_cadastro(): void
    {
        Mail::fake();
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::test(PortfolioInsurance::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Cliente Novo')
            ->set('inviteEmail', 'cliente.novo@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors();

        $convite = ConsultantInvite::query()->sole();
        self::assertSame($corretor->id, $convite->consultant_id);
        self::assertSame('cliente.novo@exemplo.com', $convite->client_email);
        Mail::assertQueued(ClientInviteMail::class);
    }

    public function test_corretor_pede_vinculo_a_cliente_existente(): void
    {
        Mail::fake();
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create(['email' => 'ja-tem-conta@exemplo.com']);
        $this->actingAs($corretor);

        Livewire::test(PortfolioInsurance::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'ja-tem-conta@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors();

        $vinculo = ConsultantClient::query()->where('client_id', $cliente->id)->sole();
        self::assertSame($corretor->id, $vinculo->consultant_id);
        self::assertSame(ConsultantClientStatus::Pending, $vinculo->status);
        Mail::assertQueued(ConsultantLinkRequestMail::class);
    }

    public function test_corretor_pedindo_vinculo_a_cliente_ja_ativo_falha_a_validacao(): void
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create(['email' => 'ativo@exemplo.com']);
        FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->actingAs($corretor);

        Livewire::test(PortfolioInsurance::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'ativo@exemplo.com')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        self::assertSame(1, ConsultantClient::query()->count());
    }

    public function test_consultor_nao_ve_o_botao_de_vincular_cliente_em_seguros_da_carteira(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(PortfolioInsurance::class)->assertDontSee('Vincular cliente');
    }
}

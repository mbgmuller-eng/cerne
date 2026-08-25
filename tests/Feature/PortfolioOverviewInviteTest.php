<?php

namespace Tests\Feature;

use App\Enums\InviteStatus;
use App\Livewire\Consultant\PortfolioOverview;
use App\Mail\ClientInviteMail;
use App\Models\ConsultantInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Convidar cliente virou parte do Painel da carteira — a gestão de
 * vínculos vivia numa tela própria (ClientDashboard, /clientes) até o
 * consultor apontar que eram duas telas pra mesma tarefa. Este teste
 * cobre o que migrou pra cá.
 */
class PortfolioOverviewInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultor_convida_cliente_e_recebe_o_link(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Fernanda Lima')
            ->set('inviteEmail', 'fernanda@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('inviteName', '')
            ->assertSet('inviteEmail', '');

        $convite = ConsultantInvite::query()->where('client_email', 'fernanda@exemplo.com')->sole();
        self::assertSame($consultor->id, $convite->consultant_id);
        self::assertSame(InviteStatus::Pending, $convite->status);

        Mail::assertQueued(ClientInviteMail::class);
    }

    public function test_convite_sem_nome_ou_email_falha_a_validacao(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteEmail', 'nao-e-um-email')
            ->call('invite')
            ->assertHasErrors(['inviteName', 'inviteEmail']);

        self::assertSame(0, ConsultantInvite::query()->count());
    }

    public function test_convites_pendentes_de_outro_consultor_nao_aparecem(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create();

        ConsultantInvite::issue($outroConsultor, 'Não é meu cliente', 'outro@exemplo.com');
        ConsultantInvite::issue($consultor, 'É meu cliente', 'meu@exemplo.com');

        $this->actingAs($consultor);
        $pendentes = Livewire::test(PortfolioOverview::class)->instance()->pendingInvites;

        self::assertCount(1, $pendentes);
        self::assertSame('meu@exemplo.com', $pendentes->first()->client_email);
    }

    public function test_quem_nao_e_consultor_recebe_403(): void
    {
        $cliente = User::factory()->create();
        $this->actingAs($cliente);

        Livewire::test(PortfolioOverview::class)->assertStatus(403);
    }
}

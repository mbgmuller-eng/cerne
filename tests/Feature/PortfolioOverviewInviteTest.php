<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Livewire\Consultant\PortfolioOverview;
use App\Mail\ClientInviteMail;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
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

    public function test_reenviar_convite_expira_o_antigo_e_emite_um_token_novo(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        ['invite' => $original] = ConsultantInvite::issue($consultor, 'Fernanda Lima', 'fernanda@exemplo.com');
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('reenviarConvite', $original->id)
            ->assertHasNoErrors();

        self::assertSame(InviteStatus::Expired, $original->fresh()->status);

        $novo = ConsultantInvite::query()->where('client_email', 'fernanda@exemplo.com')
            ->where('status', InviteStatus::Pending)->sole();
        self::assertNotSame($original->id, $novo->id);
        self::assertNotSame($original->token, $novo->token);

        // ConsultantInvite::issue() só grava a linha (sem enviar) — quem
        // enfileira o e-mail é o service, então só o reenvio conta aqui.
        Mail::assertQueued(ClientInviteMail::class, 1);
    }

    public function test_reenviar_convite_de_outro_consultor_falha(): void
    {
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create();
        ['invite' => $convite] = ConsultantInvite::issue($outroConsultor, 'Não é meu cliente', 'outro@exemplo.com');

        $this->actingAs($consultor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(PortfolioOverview::class)->call('reenviarConvite', $convite->id);
    }

    public function test_reenviar_convite_ja_aceito_falha(): void
    {
        $consultor = User::factory()->consultant()->create();
        ['invite' => $convite] = ConsultantInvite::issue($consultor, 'Fernanda Lima', 'fernanda@exemplo.com');
        $convite->update(['status' => InviteStatus::Accepted]);

        $this->actingAs($consultor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(PortfolioOverview::class)->call('reenviarConvite', $convite->id);
    }

    /**
     * Caso do cliente importado em lote (planilha de seguros, ex.): já tem
     * usuário, perfil e vínculo ativo, mas nunca recebeu convite nenhum —
     * a senha que ele tem é aleatória e desconhecida. Este é o primeiro
     * convite de verdade, não um reenvio.
     */
    public function test_enviar_convite_de_acesso_pra_cliente_ja_existente_sem_convite_nenhum(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $rubens = User::factory()->create(['name' => 'Rubens Anjos', 'email' => 'rubens@exemplo.com']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $rubens->id,
            'status' => ConsultantClientStatus::Active,
        ]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $rubens->id]);

        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('enviarConviteDeAcesso', $perfil->id)
            ->assertHasNoErrors();

        $convite = ConsultantInvite::query()->where('client_email', 'rubens@exemplo.com')->sole();
        self::assertSame($consultor->id, $convite->consultant_id);
        self::assertSame(InviteStatus::Pending, $convite->status);
        Mail::assertQueued(ClientInviteMail::class);
    }

    public function test_enviar_convite_de_acesso_pra_cliente_de_outro_consultor_falha(): void
    {
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $outroConsultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);

        $this->actingAs($consultor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(PortfolioOverview::class)->call('enviarConviteDeAcesso', $perfil->id);
    }

    public function test_quem_nao_e_consultor_recebe_403(): void
    {
        $cliente = User::factory()->create();
        $this->actingAs($cliente);

        Livewire::test(PortfolioOverview::class)->assertStatus(403);
    }
}

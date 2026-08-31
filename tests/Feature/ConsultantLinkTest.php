<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioOverview;
use App\Mail\ConsultantLinkRequestMail;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Convidar um e-mail que já tem conta não pode criar uma segunda conta —
 * vira pedido de autorização de vínculo (ConsultantLinkService), que só a
 * própria pessoa, logada, confirma (ConsultantLinkController).
 */
class ConsultantLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_convidar_email_de_cliente_existente_gera_pedido_de_vinculo_em_vez_de_convite(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['email' => 'ja-cadastrado@exemplo.com']);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'ja-cadastrado@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors();

        self::assertSame(0, ConsultantInvite::query()->count());

        $vinculo = ConsultantClient::query()->where('client_id', $cliente->id)->sole();
        self::assertSame($consultor->id, $vinculo->consultant_id);
        self::assertSame(ConsultantClientStatus::Pending, $vinculo->status);

        Mail::assertQueued(ConsultantLinkRequestMail::class);
        self::assertSame(1, User::query()->where('email', 'ja-cadastrado@exemplo.com')->count());
    }

    public function test_convidar_email_de_consultor_ou_admin_falha_a_validacao(): void
    {
        $consultor = User::factory()->consultant()->create();
        User::factory()->consultant()->create(['email' => 'outro-consultor@exemplo.com']);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'outro-consultor@exemplo.com')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        self::assertSame(0, ConsultantClient::query()->count());
    }

    public function test_convidar_email_ja_cliente_ativo_falha_a_validacao(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['email' => 'ativo@exemplo.com']);
        FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'ativo@exemplo.com')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        self::assertSame(1, ConsultantClient::query()->count());
    }

    public function test_convidar_email_com_pedido_ja_pendente_nao_duplica(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['email' => 'pendente@exemplo.com']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Pending,
        ]);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'pendente@exemplo.com')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        self::assertSame(1, ConsultantClient::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_reconvidar_email_com_vinculo_inativo_reabre_a_mesma_linha(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['email' => 'reabrir@exemplo.com']);
        $original = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Inactive,
        ]);
        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Nome Qualquer')
            ->set('inviteEmail', 'reabrir@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors();

        self::assertSame(1, ConsultantClient::query()->count());
        $vinculo = ConsultantClient::query()->sole();
        self::assertSame($original->id, $vinculo->id);
        self::assertSame(ConsultantClientStatus::Pending, $vinculo->status);
    }

    public function test_cliente_correto_autoriza_o_vinculo_pelo_link_assinado(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Pending,
        ]);

        $link = URL::temporarySignedRoute('link.show', now()->addDays(7), ['consultantClient' => $vinculo->id]);

        // Sem login: a rota `auth` manda pro login e guarda a URL de volta.
        $this->get($link)->assertRedirect(route('login'));

        $this->actingAs($cliente)->get($link)->assertOk();

        $this->actingAs($cliente)
            ->post(route('link.accept', $vinculo))
            ->assertRedirect(route('dashboard'));

        $vinculo->refresh();
        self::assertSame(ConsultantClientStatus::Active, $vinculo->status);
        self::assertNotNull($vinculo->accepted_at);
        self::assertTrue($consultor->can('view', $perfil));
    }

    public function test_outra_pessoa_logada_nao_consegue_ver_nem_responder_o_pedido(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $outraPessoa = User::factory()->create();
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Pending,
        ]);

        $link = URL::temporarySignedRoute('link.show', now()->addDays(7), ['consultantClient' => $vinculo->id]);

        $this->actingAs($outraPessoa)->get($link)->assertForbidden();
        $this->actingAs($outraPessoa)->post(route('link.accept', $vinculo))->assertForbidden();

        self::assertSame(ConsultantClientStatus::Pending, $vinculo->fresh()->status);
    }

    public function test_cliente_recusa_o_vinculo_e_a_linha_some(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Pending,
        ]);

        $this->actingAs($cliente)
            ->post(route('link.decline', $vinculo))
            ->assertRedirect(route('dashboard'));

        self::assertSame(0, ConsultantClient::query()->count());
    }

    public function test_link_com_assinatura_adulterada_falha(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $vinculo = ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Pending,
        ]);

        $this->actingAs($cliente)
            ->get(route('link.show', $vinculo)) // sem assinatura nenhuma
            ->assertForbidden();
    }
}

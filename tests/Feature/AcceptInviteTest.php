<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\InviteStatus;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aceite de convite: o nome do perfil é sempre o que o consultor escreveu
 * ao convidar (client_name) — não é mais escolhido nem confirmado pelo
 * cliente na tela de aceite.
 */
class AcceptInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_perfil_nasce_com_o_nome_exato_que_o_consultor_escreveu(): void
    {
        $consultor = User::factory()->consultant()->create();
        ['invite' => $invite, 'token' => $token] = ConsultantInvite::issue($consultor, 'Marcelo e Helen', 'marcelo.helen@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $usuario = User::query()->where('email', 'marcelo.helen@exemplo.com')->sole();
        $perfil = FinancialProfile::query()->where('owner_user_id', $usuario->id)->sole();

        self::assertSame('Marcelo e Helen', $perfil->profile_name);
        self::assertSame('Marcelo e Helen', $usuario->name);

        self::assertTrue(ConsultantClient::query()
            ->where('consultant_id', $consultor->id)
            ->where('client_id', $usuario->id)
            ->where('status', ConsultantClientStatus::Active)
            ->exists());

        self::assertTrue(ProfileMember::query()
            ->where('profile_id', $perfil->id)
            ->where('user_id', $usuario->id)
            ->exists());

        self::assertSame(InviteStatus::Accepted, $invite->fresh()->status);
    }

    public function test_formulario_de_aceite_nao_aceita_mais_nome_de_perfil_customizado(): void
    {
        $consultor = User::factory()->consultant()->create();
        ['token' => $token] = ConsultantInvite::issue($consultor, 'Ana Ribeiro', 'ana.teste@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), [
            'profile_name' => 'Nome que eu tentei escolher',
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $perfil = FinancialProfile::query()->where('owner_user_id', User::where('email', 'ana.teste@exemplo.com')->value('id'))->sole();

        self::assertSame('Ana Ribeiro', $perfil->profile_name);
    }

    /**
     * Caso real: cliente importado em lote (planilha) já tem User com
     * senha aleatória desconhecida, sem convite nenhum. O convite emitido
     * DEPOIS só serve pra ela definir a senha de verdade — não pode
     * tentar criar outro usuário com o mesmo e-mail (bateria no índice
     * único e voltaria erro 500 pra quem só quer entrar na própria conta).
     */
    public function test_convite_para_email_que_ja_tem_conta_so_define_a_senha(): void
    {
        $consultor = User::factory()->consultant()->create();
        $existente = User::factory()->create(['name' => 'Mariana Marino', 'email' => 'mariana@exemplo.com']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $existente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $existente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        ['invite' => $invite, 'token' => $token] = ConsultantInvite::issue($consultor, 'Mariana Marino', 'mariana@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        self::assertSame(1, User::query()->where('email', 'mariana@exemplo.com')->count());
        self::assertTrue(Hash::check('Senha123', $existente->fresh()->password));
        self::assertTrue(auth()->check());
        self::assertSame($existente->id, auth()->id());

        // Perfil e vínculo que já existiam não duplicaram.
        self::assertSame(1, FinancialProfile::query()->where('owner_user_id', $existente->id)->count());
        self::assertSame(1, ConsultantClient::query()->where('client_id', $existente->id)->count());

        self::assertSame(InviteStatus::Accepted, $invite->fresh()->status);
    }

    public function test_convite_para_email_de_conta_que_nao_e_cliente_e_rejeitado(): void
    {
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create(['email' => 'outro.consultor@exemplo.com']);

        ['token' => $token] = ConsultantInvite::issue($consultor, 'Outro Consultor', 'outro.consultor@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertSessionHasErrors('password');

        self::assertFalse(auth()->check());
    }

    public function test_convite_expirado_nao_cria_conta(): void
    {
        $consultor = User::factory()->consultant()->create();
        ['token' => $token] = ConsultantInvite::issue($consultor, 'Fulano', 'fulano@exemplo.com');
        ConsultantInvite::query()->where('client_email', 'fulano@exemplo.com')->update(['expires_at' => now()->subDay()]);

        $this->post(route('invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertSessionHasErrors('password');

        self::assertSame(0, User::query()->where('email', 'fulano@exemplo.com')->count());
    }
}

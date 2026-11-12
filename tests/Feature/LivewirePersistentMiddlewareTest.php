<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * As regras de acesso das rotas também valem para as AÇÕES das telas (`/livewire/update`), não só para abrir a página
 * (revisão de segurança 2026-11). O teste é de ponta a ponta de propósito: abre a tela pela web, guarda o estado que o navegador
 * guardaria, TIRA o acesso (como se tivesse acontecido com a tela aberta) e só então dispara a ação. `Livewire::test()` não serve
 * aqui: ele desliga os middlewares.
 */
class LivewirePersistentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /** Estado (snapshot) do primeiro componente da página, como o navegador o teria. */
    private function snapshotDe(TestResponse $pagina): string
    {
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]*)"/', $pagina->getContent(), $achou), 'a página precisa ter um componente Livewire');

        return html_entity_decode($achou[1], ENT_QUOTES);
    }

    private function acao(string $snapshot): TestResponse
    {
        // Em produção cada requisição é um processo novo; aqui várias ações rodam no mesmo, e o Livewire guarda "já apliquei as regras
        // desta rota" entre elas. Zerar o estado deixa a segunda ação se comportar como uma requisição de verdade.
        app('livewire')->flushState();

        return $this->postJson(app('livewire')->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]], 'updates' => []]],
        ], ['X-Livewire' => 'true']);
    }

    public function test_acao_de_tela_aberta_e_barrada_quando_o_segundo_passo_deixa_de_existir(): void
    {
        config(['cerne.security.two_factor_required' => true]);
        $dois = app(TwoFactorService::class);
        $consultor = User::factory()->consultant()->create();
        $segredo = $dois->generateSecret();
        $dois->enable($consultor, $segredo, $dois->codeAt($segredo));

        $pagina = $this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk();
        $snapshot = $this->snapshotDe($pagina);

        // Com o segundo passo ligado a ação passa.
        $this->acao($snapshot)->assertOk();

        // O segundo passo some com a tela ainda aberta: a ação é recusada e a pessoa vai para a configuração.
        $dois->disable($consultor);
        $this->acao($snapshot)->assertRedirect(route('two-factor.setup'));
    }

    public function test_acao_de_tela_aberta_e_barrada_quando_a_versao_dos_termos_muda(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        $pagina = $this->actingAs($usuario)->get(route('subscription.index'));
        $pagina->assertOk();
        $snapshot = $this->snapshotDe($pagina);

        $this->acao($snapshot)->assertOk();

        config(['cerne.legal.terms_version' => '2099-01-01']);
        $this->acao($snapshot)->assertRedirect(route('legal.accept'));
    }

    public function test_acao_de_tela_de_saude_aberta_e_barrada_quando_o_consentimento_e_revogado(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        $pagina = $this->get(route('health.card.index'));
        $pagina->assertOk();
        $snapshot = $this->snapshotDe($pagina);

        $this->acao($snapshot)->assertOk();

        $usuario->revokeHealthConsent();
        $this->acao($snapshot)->assertRedirect(route('health.consent'));
    }
}

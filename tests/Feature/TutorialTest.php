<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\TutorialStatus;
use App\Livewire\Tutorial\PageHelp;
use App\Livewire\Tutorial\ResetTutorials;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\TutorialProgress;
use App\Models\User;
use App\Support\Tutorials\TutorialCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tutoriais das telas: o botão "?" do menu, o cartão de primeira visita (uma vez por pessoa e por tela) e o "mostrar de novo" em Minha conta.
 * A estrutura é testada com uma entrada de catálogo de teste; o conteúdo de verdade é conferido em TutorialCatalogTest.
 */
class TutorialTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tutorials.pages' => [
            'dashboard' => [
                'title' => 'Visão geral',
                'summary' => 'Veja o mês e o patrimônio em um só painel.',
                'steps' => ['Primeiro passo.', 'Segundo passo.', 'Terceiro passo.'],
                'tip' => 'Uma dica útil.',
            ],
        ]]);

        $this->ana = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->ana->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $this->ana->id, 'role' => MemberRole::Primary]);
        $this->bruno = User::factory()->create();
    }

    public function test_tela_com_tutorial_tem_o_botao_no_menu_e_o_cartao_no_topo(): void
    {
        $html = $this->actingAs($this->ana)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-help-button', $html);
        $this->assertStringContainsString('data-tutorial-card', $html);
        $this->assertStringContainsString('Primeira vez nesta tela?', $html);
        $this->assertStringContainsString('Veja o mês e o patrimônio em um só painel.', $html);
    }

    public function test_tela_sem_tutorial_nao_tem_botao_nem_cartao(): void
    {
        $html = $this->actingAs($this->ana)->get(route('cashflow.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-help-button', $html);
        $this->assertStringNotContainsString('data-tutorial-card', $html);
    }

    public function test_dispensar_o_cartao_grava_e_ele_nao_volta(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])
            ->assertSee('Primeira vez nesta tela?')
            ->call('dismissCard')
            ->assertDontSee('Primeira vez nesta tela?');

        $this->assertSame(TutorialStatus::Dismissed, TutorialProgress::query()->where('user_id', $this->ana->id)->sole()->status);

        // Nova visita à tela: o cartão não volta.
        $html = $this->actingAs($this->ana)->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('data-tutorial-card', $html);
        // O botão "?" continua lá.
        $this->assertStringContainsString('data-help-button', $html);
    }

    public function test_abrir_o_tutorial_mostra_os_passos_e_conta_como_visto(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])
            ->call('openTutorial')
            ->assertSet('open', true)
            ->assertSee('Como usar: Visão geral')
            ->assertSee('Primeiro passo.')
            ->assertSee('Terceiro passo.')
            ->assertSee('Bom saber:')
            ->assertDontSee('Primeira vez nesta tela?');

        $this->assertSame(TutorialStatus::Viewed, TutorialProgress::query()->sole()->status);
    }

    public function test_o_botao_do_menu_abre_pelo_evento_mesmo_sem_o_cartao(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])
            ->call('dismissCard')
            ->dispatch('tutorial-open')
            ->assertSet('open', true);

        // Abrir vale mais que dispensar: o registro passa de "dispensado" para "visto", sem duplicar a linha.
        $this->assertSame(1, TutorialProgress::query()->count());
        $this->assertSame(TutorialStatus::Viewed, TutorialProgress::query()->sole()->status);
    }

    public function test_dispensar_depois_de_ver_nao_desfaz_o_visto(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])
            ->call('openTutorial')
            ->call('dismissCard');

        $this->assertSame(TutorialStatus::Viewed, TutorialProgress::query()->sole()->status);
    }

    public function test_o_que_uma_pessoa_viu_nao_vale_para_outra(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])->call('dismissCard');

        Livewire::actingAs($this->bruno)->test(PageHelp::class, ['routeName' => 'dashboard'])->assertSee('Primeira vez nesta tela?');
        $this->assertSame(0, TutorialProgress::query()->where('user_id', $this->bruno->id)->count());
    }

    public function test_tela_sem_tutorial_nao_grava_nada(): void
    {
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'cashflow.index'])
            ->call('openTutorial')
            ->call('dismissCard')
            ->assertSet('open', false);

        $this->assertSame(0, TutorialProgress::query()->count());
    }

    public function test_o_nome_da_rota_nao_pode_ser_trocado_pelo_navegador(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])->set('routeName', 'qualquer.outra');
    }

    public function test_quem_esta_vendo_como_outra_pessoa_nao_grava_na_conta_dela(): void
    {
        $this->withSession(['impersonator_id' => (string) $this->bruno->id]);

        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])
            ->assertDontSee('Primeira vez nesta tela?')
            ->call('openTutorial')
            ->assertSet('open', true)
            ->call('dismissCard');

        $this->assertSame(0, TutorialProgress::query()->count());
    }

    public function test_mostrar_de_novo_apaga_so_o_progresso_de_quem_pediu(): void
    {
        TutorialProgress::query()->create(['user_id' => $this->ana->id, 'tutorial_key' => 'dashboard', 'status' => TutorialStatus::Dismissed]);
        TutorialProgress::query()->create(['user_id' => $this->ana->id, 'tutorial_key' => 'cashflow.index', 'status' => TutorialStatus::Viewed]);
        TutorialProgress::query()->create(['user_id' => $this->bruno->id, 'tutorial_key' => 'dashboard', 'status' => TutorialStatus::Dismissed]);

        Livewire::actingAs($this->ana)->test(ResetTutorials::class)->call('showAgain')->assertSet('done', true);

        $this->assertSame(0, TutorialProgress::query()->where('user_id', $this->ana->id)->count());
        $this->assertSame(1, TutorialProgress::query()->where('user_id', $this->bruno->id)->count(), 'o progresso do outro fica');

        // E o cartão volta a aparecer.
        Livewire::actingAs($this->ana)->test(PageHelp::class, ['routeName' => 'dashboard'])->assertSee('Primeira vez nesta tela?');
    }

    public function test_minha_conta_oferece_mostrar_os_avisos_de_novo(): void
    {
        $this->actingAs($this->ana)->get(route('my-account'))->assertOk()->assertSee('Mostrar os avisos de novo');
    }

    public function test_apagar_a_conta_apaga_o_progresso(): void
    {
        TutorialProgress::query()->create(['user_id' => $this->bruno->id, 'tutorial_key' => 'dashboard', 'status' => TutorialStatus::Viewed]);

        $this->bruno->delete();

        $this->assertSame(0, TutorialProgress::query()->count());
    }

    public function test_uma_pessoa_nao_tem_duas_linhas_para_a_mesma_tela(): void
    {
        TutorialProgress::query()->create(['user_id' => $this->ana->id, 'tutorial_key' => 'dashboard', 'status' => TutorialStatus::Viewed]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        TutorialProgress::query()->create(['user_id' => $this->ana->id, 'tutorial_key' => 'dashboard', 'status' => TutorialStatus::Dismissed]);
    }
}

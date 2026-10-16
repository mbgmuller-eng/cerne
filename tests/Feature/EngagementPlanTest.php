<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EmploymentType;
use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\InvestorType;
use App\Enums\MemberRole;
use App\Enums\ReserveType;
use App\Livewire\Consultant\EngagementTemplates;
use App\Livewire\Engagement\EngagementPlan;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\EngagementPlanItem;
use App\Models\EngagementPlanStage;
use App\Models\EngagementPlanTemplate;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\Goal;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\EngagementAccess;
use App\Services\EngagementPlanReport;
use App\Services\EngagementPlanService;
use App\Support\Engagement\EngagementPlanCriteria;
use App\Support\Engagement\EngagementPlanTemplateFormat;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Passo a passo do acompanhamento: o formato do modelo, os critérios automáticos, quem monta e quem só acompanha,
 * o roteiro por relação de consultoria e os modelos privados do consultor.
 */
class EngagementPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private User $consultor;

    private ConsultantClient $vinculo;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));

        $this->cliente = User::factory()->create(['name' => 'Helen Cliente']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id, 'profile_name' => 'Família Ferry']);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Helen Cliente']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Consultor']);
        $this->vinculo = ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function comoCliente(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    private function comoConsultor(?User $consultor = null): void
    {
        $this->actingAs($consultor ?? $this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    private function visitante()
    {
        return app(EngagementAccess::class)->viewer();
    }

    private function servico(): EngagementPlanService
    {
        return app(EngagementPlanService::class);
    }

    private function roteiroSimples(): void
    {
        $this->comoConsultor();
        $this->servico()->applyBody($this->visitante(), "# Diagnóstico\n- Contas cadastradas @accounts\n- Conversa inicial\n\n# Objetivos\n- Objetivos cadastrados @goals");
    }

    // ------------------------------------------------------------------ formato do modelo

    public function test_formato_le_etapas_itens_e_criterios(): void
    {
        $etapas = EngagementPlanTemplateFormat::parse("# Reserva\n- Meta definida\n- Reserva completa @reserve_peace\n\n# Seguros\n- Apólices @policies");

        self::assertSame([
            ['title' => 'Reserva', 'items' => [['title' => 'Meta definida', 'auto' => null], ['title' => 'Reserva completa', 'auto' => 'reserve_peace']]],
            ['title' => 'Seguros', 'items' => [['title' => 'Apólices', 'auto' => 'policies']]],
        ], $etapas);
        self::assertSame($etapas, EngagementPlanTemplateFormat::parse(EngagementPlanTemplateFormat::serialize($etapas)));
    }

    public function test_formato_recusa_o_que_nao_entende(): void
    {
        foreach ([
            "- item solto" => 'depois de uma etapa',
            "# Etapa\n- Item @nao_existe" => 'não existe',
            "# \n- Item" => 'precisa de um nome',
            "# Etapa\ntexto sem marca" => 'use "# "',
            "# Etapa\n- @accounts" => 'precisa de um texto',
            '' => 'ao menos uma etapa',
        ] as $texto => $trecho) {
            try {
                EngagementPlanTemplateFormat::parse($texto);
                self::fail("deveria recusar: $texto");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($trecho, $e->getMessage());
            }
        }
    }

    public function test_roteiro_padrao_do_cerne_e_valido_e_usa_so_criterios_existentes(): void
    {
        $etapas = EngagementPlanTemplateFormat::parse(EngagementPlanTemplateFormat::defaultBody());

        self::assertCount(6, $etapas);

        foreach ($etapas as $etapa) {
            foreach ($etapa['items'] as $item) {
                self::assertTrue($item['auto'] === null || EngagementPlanCriteria::exists($item['auto']), $item['title']);
            }
        }
    }

    // ------------------------------------------------------------------ critérios automáticos

    public function test_criterios_olham_os_dados_do_cliente_e_restauram_o_contexto(): void
    {
        $this->comoCliente();
        $contexto = app(ProfileContext::class);

        $antes = EngagementPlanCriteria::evaluate($this->perfil, ['accounts', 'goals', 'investor_profile', 'reserve_peace', 'life_insurance']);
        self::assertSame(['accounts' => false, 'goals' => false, 'investor_profile' => false, 'reserve_peace' => false, 'life_insurance' => false], $antes);
        self::assertSame($this->perfil->id, $contexto->profileId(), 'o contexto do visitante volta ao que era');
        self::assertFalse($contexto->isConsultant());

        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        Goal::create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'name' => 'Casa', 'priority' => 1, 'estimated_value' => '100000.00', 'funding_method' => FundingMethod::LumpSum, 'status' => GoalStatus::Active]);
        InvestorProfile::create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => EmploymentType::PublicServant]);
        FinancialReserve::create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'reserve_type' => ReserveType::Paz, 'target_amount' => '1000.00', 'current_amount' => '1000.00']);

        $depois = EngagementPlanCriteria::evaluate($this->perfil, ['accounts', 'goals', 'investor_profile', 'life_insurance']);
        self::assertSame(['accounts' => true, 'goals' => true, 'investor_profile' => true, 'life_insurance' => false], $depois);
    }

    public function test_criterio_funciona_sem_nenhum_contexto_ativo(): void
    {
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);

        app(ProfileContext::class)->clear();

        self::assertTrue(EngagementPlanCriteria::evaluate($this->perfil, ['accounts'])['accounts']);
        self::assertFalse(app(ProfileContext::class)->hasProfile(), 'e deixa o contexto vazio, como estava');
    }

    // ------------------------------------------------------------------ montar e acompanhar

    public function test_consultor_monta_o_roteiro_a_partir_de_texto_e_o_cliente_so_acompanha(): void
    {
        $this->roteiroSimples();

        self::assertSame(['Diagnóstico', 'Objetivos'], EngagementPlanStage::query()->orderBy('position')->pluck('title')->all());
        self::assertSame(3, EngagementPlanItem::query()->count());

        $this->comoCliente();
        self::assertSame(2, EngagementPlanStage::query()->count(), 'o cliente enxerga o roteiro');

        foreach ([
            fn () => $this->servico()->applyBody($this->visitante(), '# X'),
            fn () => $this->servico()->saveStage($this->visitante(), null, 'X', null),
            fn () => $this->servico()->deleteStage($this->visitante(), EngagementPlanStage::query()->first()),
            fn () => $this->servico()->setManualDone($this->visitante(), EngagementPlanItem::query()->whereNull('auto_check')->first(), true),
            fn () => $this->servico()->saveAsTemplate($this->visitante(), 'Meu'),
        ] as $acao) {
            try {
                $acao();
                self::fail('o cliente não monta o roteiro');
            } catch (AuthorizationException) {
            }
        }
    }

    public function test_item_automatico_segue_o_app_e_o_manual_e_do_consultor(): void
    {
        $this->roteiroSimples();
        $contas = EngagementPlanItem::query()->where('auto_check', 'accounts')->sole();
        $manual = EngagementPlanItem::query()->whereNull('auto_check')->sole();

        self::assertNull($contas->done_at);

        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        self::assertSame(1, $this->servico()->syncRelation($this->perfil, $this->consultor->id));
        self::assertSame('auto', $contas->fresh()->done_source);
        self::assertSame(0, $this->servico()->syncRelation($this->perfil, $this->consultor->id), 'sem mudança, nada é gravado de novo');

        BankAccount::query()->update(['is_active' => false]);
        self::assertSame(1, $this->servico()->syncRelation($this->perfil, $this->consultor->id));
        self::assertNull($contas->fresh()->done_at, 'o item volta quando o critério deixa de valer');

        try {
            $this->servico()->setManualDone($this->visitante(), $contas, true);
            self::fail('item automático não se marca à mão');
        } catch (AuthorizationException) {
        }

        $this->servico()->setManualDone($this->visitante(), $manual, true);
        self::assertSame('manual', $manual->fresh()->done_source);
        $this->servico()->setManualDone($this->visitante(), $manual, false);
        self::assertNull($manual->fresh()->done_at);
    }

    public function test_editar_etapas_e_itens_e_reordenar(): void
    {
        $this->roteiroSimples();
        $primeira = EngagementPlanStage::query()->where('title', 'Diagnóstico')->sole();
        $segunda = EngagementPlanStage::query()->where('title', 'Objetivos')->sole();

        $this->servico()->moveStage($this->visitante(), $segunda, -1);
        self::assertSame(['Objetivos', 'Diagnóstico'], EngagementPlanStage::query()->orderBy('position')->pluck('title')->all());

        $this->servico()->moveStage($this->visitante(), $segunda->fresh(), -1);
        self::assertSame(['Objetivos', 'Diagnóstico'], EngagementPlanStage::query()->orderBy('position')->pluck('title')->all(), 'na ponta, nada muda');

        $this->servico()->saveStage($this->visitante(), $primeira, 'Diagnóstico completo', 'Panorama inicial');
        $item = $this->servico()->saveItem($this->visitante(), null, $primeira->id, 'Mapear dívidas', null);
        self::assertSame(3, $item->position);

        $this->servico()->saveItem($this->visitante(), $item, $primeira->id, 'Mapear dívidas e financiamentos', 'goals');
        self::assertSame('goals', $item->fresh()->auto_check);

        $this->expectException(AuthorizationException::class);
        $this->servico()->saveItem($this->visitante(), null, $primeira->id, 'Critério inventado', 'inexistente');
    }

    public function test_trocar_o_criterio_zera_o_estado_e_apagar_a_etapa_leva_os_itens(): void
    {
        $this->roteiroSimples();
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        $this->servico()->syncRelation($this->perfil, $this->consultor->id);
        $item = EngagementPlanItem::query()->where('auto_check', 'accounts')->sole();
        self::assertNotNull($item->done_at);

        $this->servico()->saveItem($this->visitante(), $item, $item->stage_id, $item->title, null);
        self::assertNull($item->fresh()->done_at);

        $this->servico()->deleteStage($this->visitante(), EngagementPlanStage::query()->where('title', 'Diagnóstico')->sole());
        self::assertSame(1, EngagementPlanItem::query()->count());
    }

    // ------------------------------------------------------------------ por relação

    public function test_roteiro_e_por_relacao_historico_volta_com_o_mesmo_consultor_e_consultor_novo_comeca_vazio(): void
    {
        $this->roteiroSimples();
        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);

        $this->comoCliente();
        self::assertSame(0, EngagementPlanStage::query()->count());

        $novo = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $novo->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);
        $this->comoCliente();
        self::assertSame(0, EngagementPlanStage::query()->count(), 'consultor novo, roteiro vazio');

        $this->comoConsultor($novo);
        self::assertSame(0, EngagementPlanStage::query()->count());

        ConsultantClient::query()->where('consultant_id', $novo->id)->update(['status' => ConsultantClientStatus::Inactive]);
        $this->vinculo->update(['status' => ConsultantClientStatus::Active]);
        $this->comoCliente();
        self::assertSame(2, EngagementPlanStage::query()->count(), 'o mesmo consultor de volta recupera o roteiro');
    }

    public function test_rotina_diaria_so_reavalia_relacoes_ativas(): void
    {
        $this->roteiroSimples();
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);

        self::assertSame(1, $this->servico()->syncAll());
        self::assertSame(0, $this->servico()->syncAll(), 'idempotente');

        BankAccount::query()->update(['is_active' => false]);
        $this->vinculo->update(['status' => ConsultantClientStatus::Inactive]);
        self::assertSame(0, $this->servico()->syncAll(), 'vínculo encerrado: o histórico fica parado');
        self::assertNotNull(EngagementPlanItem::withoutGlobalScopes()->where('auto_check', 'accounts')->sole()->done_at);
    }

    // ------------------------------------------------------------------ modelos

    public function test_salvar_como_modelo_guarda_so_a_estrutura_e_so_o_dono_ve(): void
    {
        $this->roteiroSimples();
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        $this->servico()->syncRelation($this->perfil, $this->consultor->id);

        $modelo = $this->servico()->saveAsTemplate($this->visitante(), 'Casal');

        self::assertSame("# Diagnóstico\n- Contas cadastradas @accounts\n- Conversa inicial\n\n# Objetivos\n- Objetivos cadastrados @goals", $modelo->body);
        self::assertStringNotContainsString('Família Ferry', $modelo->body);

        $outro = User::factory()->consultant()->create();
        $this->actingAs($outro);
        self::assertSame(0, EngagementPlanTemplate::query()->count());
        $this->actingAs($this->cliente);
        self::assertSame(0, EngagementPlanTemplate::query()->count());
        $this->actingAs($this->consultor);
        self::assertSame(1, EngagementPlanTemplate::query()->count());
        self::assertSame(1, EngagementPlanTemplate::withoutGlobalScopes()->count());
    }

    public function test_aplicar_modelo_copia_e_mudar_o_modelo_depois_nao_altera_quem_ja_usa(): void
    {
        $this->comoConsultor();
        $modelo = EngagementPlanTemplate::create(['name' => 'Enxuto', 'body' => "# Única etapa\n- Item um"]);

        Livewire::test(EngagementPlan::class)->set('templateChoice', $modelo->id)->call('applyTemplate')->assertSet('templateError', null);
        self::assertSame(['Única etapa'], EngagementPlanStage::query()->pluck('title')->all());

        $modelo->update(['body' => "# Outra coisa\n- Outro item"]);
        self::assertSame(['Única etapa'], EngagementPlanStage::query()->pluck('title')->all());
    }

    // ------------------------------------------------------------------ telas

    public function test_componente_do_roteiro_cliente_ve_e_nao_edita(): void
    {
        $this->roteiroSimples();

        $this->comoCliente();
        Livewire::test(EngagementPlan::class)
            ->assertSee('Passo a passo')->assertSee('Diagnóstico')->assertSee('0 de 2 etapas concluídas')
            ->assertDontSee('Editar roteiro')
            ->call('toggleEditing')->assertForbidden();

        Livewire::test(EngagementPlan::class)->call('openStage')->assertForbidden();
        Livewire::test(EngagementPlan::class)->call('applyTemplate')->assertForbidden();
    }

    public function test_componente_do_roteiro_sem_etapas_explica_para_cada_lado(): void
    {
        $this->comoCliente();
        Livewire::test(EngagementPlan::class)->assertSee('Seu consultor ainda não montou o passo a passo');

        $this->comoConsultor();
        Livewire::test(EngagementPlan::class)->assertSee('Aplique um modelo ou monte as etapas do zero');
    }

    public function test_componente_do_roteiro_aplica_o_padrao_edita_e_marca(): void
    {
        $this->comoConsultor();

        $teste = Livewire::test(EngagementPlan::class)
            ->call('toggleEditing')->assertSet('editing', true)
            ->set('templateChoice', 'default')->call('applyTemplate');

        self::assertSame(6, EngagementPlanStage::query()->count());
        self::assertSame(16, EngagementPlanItem::query()->count());

        $etapa = EngagementPlanStage::query()->where('title', 'Diagnóstico')->sole();
        $manual = EngagementPlanItem::query()->where('stage_id', $etapa->id)->whereNull('auto_check')->sole();

        $teste->call('setDone', $manual->id, true);
        self::assertNotNull($manual->fresh()->done_at);

        $teste->call('openStage')->set('stageTitle', 'Etapa extra')->call('saveStage')->assertHasNoErrors();
        self::assertSame(7, EngagementPlanStage::query()->count());

        $teste->call('openItem', $etapa->id)->set('itemTitle', 'Item extra')->set('itemAuto', 'goals')->call('saveItem')->assertHasNoErrors();
        self::assertSame('goals', EngagementPlanItem::query()->where('title', 'Item extra')->sole()->auto_check);

        $teste->call('openStage')->set('stageTitle', '')->call('saveStage')->assertHasErrors(['stageTitle']);
    }

    public function test_pagina_de_modelos_cria_valida_edita_e_apaga(): void
    {
        $this->actingAs($this->cliente);
        Livewire::test(EngagementTemplates::class)->assertForbidden();

        $this->actingAs($this->consultor);

        Livewire::test(EngagementTemplates::class)
            ->assertSee('Você ainda não tem modelos próprios')
            ->call('openForm')->set('name', 'Quebrado')->set('body', "- sem etapa")->call('save')->assertHasErrors(['body']);

        self::assertSame(0, EngagementPlanTemplate::query()->count());

        Livewire::test(EngagementTemplates::class)
            ->call('copyDefault')->call('save')->assertHasNoErrors()
            ->assertSee('Meu roteiro')->assertSee('6 etapas');

        $modelo = EngagementPlanTemplate::query()->sole();

        Livewire::test(EngagementTemplates::class)
            ->call('openForm', $modelo->id)->set('name', 'Renomeado')->call('save')
            ->call('delete', $modelo->id);

        self::assertSame(0, EngagementPlanTemplate::query()->count());
    }

    public function test_pdf_do_plano_inclui_o_passo_a_passo(): void
    {
        $this->roteiroSimples();
        BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id]);
        $this->servico()->syncRelation($this->perfil, $this->consultor->id);

        $html = view('pdf.engagement-plan', app(EngagementPlanReport::class)->data($this->perfil->id, $this->consultor->id))->render();

        self::assertStringContainsString('Passo a passo', $html);
        self::assertStringContainsString('1. Diagnóstico</strong> · 1 de 2 itens', $html);
        self::assertStringContainsString('[x] Contas cadastradas', $html);
        self::assertStringContainsString('[ ] Conversa inicial', $html);
        self::assertTrue(app(EngagementPlanReport::class)->hasData($this->perfil->id, $this->consultor->id));
    }
}

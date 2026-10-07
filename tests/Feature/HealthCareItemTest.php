<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\HealthCareIntervalUnit;
use App\Livewire\Health\HealthCareItemIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthCareItemDue;
use App\Services\HealthCareItemService;
use App\Support\NotificationPresenter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Itens de saúde de troca periódica (aparelho auditivo, palmilha, óculos...):
 * item, frequência, última vez, próxima vez e lembrete (sugestão de quem testa o app).
 * Mesma visibilidade da Ficha de Saúde: o casal vê, o consultor não vê nada.
 */
class HealthCareItemTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoje;

    private User $usuarioTitular;

    private User $usuarioConjuge;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    private ProfileMember $conjuge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoje = CarbonImmutable::parse('2026-10-07');
        CarbonImmutable::setTestNow($this->hoje);

        $this->usuarioTitular = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioTitular->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioTitular->id, 'name' => 'Marcelo']);

        $this->usuarioConjuge = User::factory()->create();
        $this->conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioConjuge->id, 'name' => 'Helen']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function entrarComo(ProfileMember $membro): void
    {
        $this->actingAs($membro->id === $this->titular->id ? $this->usuarioTitular : $this->usuarioConjuge);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function criar(array $extra = []): HealthCareItem
    {
        $this->entrarComo($this->titular);

        return app(HealthCareItemService::class)->create($this->titular, $extra + [
            'category' => 'hearing_aid', 'name' => 'Filtro', 'device_name' => 'Aparelho auditivo',
            'interval_value' => 15, 'interval_unit' => 'day', 'last_done_on' => $this->hoje->toDateString(),
        ], $this->titular);
    }

    // ---- regra da próxima data

    public function test_proxima_data_e_a_ultima_vez_mais_a_frequencia(): void
    {
        $dias = $this->criar();
        self::assertSame('2026-10-22', $dias->next_due_on->toDateString(), 'filtro a cada 15 dias');

        $palmilha = $this->criar(['name' => 'Palmilha', 'interval_value' => 6, 'interval_unit' => 'month', 'last_done_on' => '2026-08-31']);
        self::assertSame('2027-02-28', $palmilha->next_due_on->toDateString(), 'mês sem estouro: 31/08 + 6 meses = 28/02');

        $semanas = $this->criar(['name' => 'Meia', 'interval_value' => 2, 'interval_unit' => 'week']);
        self::assertSame('2026-10-21', $semanas->next_due_on->toDateString());

        $ano = $this->criar(['name' => 'Óculos', 'interval_value' => 1, 'interval_unit' => 'year', 'last_done_on' => '2024-02-29']);
        self::assertSame('2025-02-28', $ano->next_due_on->toDateString(), 'ano sem estouro');
    }

    public function test_sem_data_da_ultima_vez_conta_a_partir_de_hoje(): void
    {
        $item = $this->criar(['last_done_on' => '']);

        self::assertSame('2026-10-07', $item->last_done_on->toDateString());
        self::assertSame('2026-10-22', $item->next_due_on->toDateString());
    }

    public function test_feito_hoje_recalcula_a_partir_de_hoje_e_nao_do_vencimento_antigo(): void
    {
        $item = $this->criar(['last_done_on' => '2026-09-01']); // venceu em 16/09, está atrasado
        self::assertSame('2026-09-16', $item->next_due_on->toDateString());

        app(HealthCareItemService::class)->markDone($item);

        $item->refresh();
        self::assertSame('2026-10-07', $item->last_done_on->toDateString());
        self::assertSame('2026-10-22', $item->next_due_on->toDateString());
    }

    public function test_editar_a_frequencia_recalcula_a_proxima_data(): void
    {
        $item = $this->criar();

        app(HealthCareItemService::class)->update($item, ['interval_value' => 30, 'interval_unit' => 'day']);

        self::assertSame('2026-11-06', $item->fresh()->next_due_on->toDateString());
    }

    public function test_pausar_nao_avisa_e_retomar_recomeca_a_contagem_de_hoje(): void
    {
        $item = $this->criar(['last_done_on' => '2026-09-01']);
        $service = app(HealthCareItemService::class);

        $service->setActive($item, false);
        self::assertFalse($item->fresh()->is_active);

        $service->setActive($item->fresh(), true);
        self::assertTrue($item->fresh()->is_active);
        self::assertSame('2026-10-22', $item->fresh()->next_due_on->toDateString());
    }

    public function test_texto_da_frequencia(): void
    {
        self::assertSame('a cada 15 dias', $this->criar()->frequencyLabel());
        self::assertSame('a cada mês', $this->criar(['interval_value' => 1, 'interval_unit' => 'month'])->frequencyLabel());
        self::assertSame('a cada 6 meses', $this->criar(['interval_value' => 6, 'interval_unit' => 'month'])->frequencyLabel());
        self::assertSame('mês', HealthCareIntervalUnit::Month->singular());
    }

    // ---- tela

    public function test_cadastra_pela_tela_com_a_proxima_data_ao_vivo(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthCareItemIndex::class)
            ->call('newItem')
            ->set('memberId', $this->conjuge->id)
            ->set('category', 'orthotic')
            ->set('name', 'Palmilha ortopédica')
            ->set('intervalValue', '6')
            ->set('intervalUnit', 'month')
            ->set('lastDoneOn', '2026-10-01')
            ->assertSee('Próxima vez prevista: 01/04/2027')
            ->call('save')
            ->assertHasNoErrors();

        $item = HealthCareItem::query()->sole();
        self::assertSame($this->conjuge->id, $item->member_id, 'o item é DE QUEM usa, não de quem cadastrou');
        self::assertSame($this->titular->id, $item->created_by_member_id);
        self::assertSame('2027-04-01', $item->next_due_on->toDateString());
    }

    public function test_validacao_da_tela(): void
    {
        $this->entrarComo($this->titular);

        Livewire::test(HealthCareItemIndex::class)
            ->call('newItem')
            ->set('name', '')
            ->set('intervalValue', '0')
            ->set('lastDoneOn', '2026-12-01')
            ->call('save')
            ->assertHasErrors(['name', 'intervalValue', 'lastDoneOn']);

        Livewire::test(HealthCareItemIndex::class)
            ->call('newItem')
            ->set('name', 'Filtro')
            ->set('intervalValue', '15')
            ->set('category', 'inexistente')
            ->call('save')
            ->assertHasErrors('category');

        self::assertSame(0, HealthCareItem::query()->count());
    }

    public function test_feito_hoje_pela_tela_e_editar_e_remover(): void
    {
        $item = $this->criar(['last_done_on' => '2026-09-01']);

        Livewire::test(HealthCareItemIndex::class)
            ->assertSee('atrasado há 21 dias')
            ->call('markDone', $item->id)
            ->assertDontSee('atrasado')
            ->assertSee('Próxima em 22/10/2026')
            ->call('editItem', $item->id)
            ->assertSet('name', 'Filtro')
            ->assertSet('intervalValue', '15')
            ->set('name', 'Filtro do aparelho')
            ->call('save')
            ->assertHasNoErrors()
            ->call('editItem', $item->id)
            ->call('delete', $item->id)
            ->assertSet('showForm', false);

        self::assertSame(0, HealthCareItem::query()->count());
    }

    public function test_lista_mostra_o_que_vence_hoje_e_amanha(): void
    {
        $this->criar(['name' => 'Vence hoje', 'last_done_on' => '2026-09-22']);
        $this->criar(['name' => 'Vence amanhã', 'last_done_on' => '2026-09-23']);

        Livewire::test(HealthCareItemIndex::class)->assertSee('(hoje)')->assertSee('(amanhã)');
    }

    public function test_o_conjuge_ve_e_marca_o_item_do_outro(): void
    {
        $item = $this->criar();

        $this->entrarComo($this->conjuge);
        Livewire::test(HealthCareItemIndex::class)->assertSee('Filtro')->call('markDone', $item->id);

        self::assertSame('2026-10-22', $item->fresh()->next_due_on->toDateString());
    }

    public function test_consultor_vinculado_leva_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.care.index'))
            ->assertForbidden();
    }

    public function test_consultor_nao_enxerga_os_itens_nem_pela_consulta(): void
    {
        $this->criar();

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active]);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, asConsultant: true);

        self::assertSame(0, HealthCareItem::query()->count());
    }

    public function test_o_menu_de_saude_tem_a_entrada_nova(): void
    {
        $this->entrarComo($this->titular);

        $this->get(route('health.care.index'))->assertOk()->assertSee('Cuidados e itens');
    }

    // ---- lembrete

    private function itemSemContexto(string $vence, array $extra = []): HealthCareItem
    {
        return HealthCareItem::withoutGlobalScopes()->create($extra + [
            'profile_id' => $this->perfil->id, 'member_id' => $this->titular->id, 'category' => 'hearing_aid', 'name' => 'Filtro',
            'device_name' => 'Aparelho auditivo', 'interval_value' => 15, 'interval_unit' => 'day', 'last_done_on' => '2026-09-01',
            'next_due_on' => $vence, 'is_active' => true, 'created_by_member_id' => $this->titular->id,
        ]);
    }

    public function test_avisa_no_dia_anterior_e_no_dia_os_dois_do_casal(): void
    {
        Notification::fake();
        $hoje = $this->itemSemContexto('2026-10-07', ['name' => 'Hoje']);
        $amanha = $this->itemSemContexto('2026-10-08', ['name' => 'Amanhã']);

        self::assertSame(4, app(HealthCareItemService::class)->notifyDue($this->hoje));

        foreach ([$this->usuarioTitular, $this->usuarioConjuge] as $usuario) {
            Notification::assertSentTo($usuario, HealthCareItemDue::class, fn ($n) => $n->itemId === $hoje->id && $n->daysLeft === 0);
            Notification::assertSentTo($usuario, HealthCareItemDue::class, fn ($n) => $n->itemId === $amanha->id && $n->daysLeft === 1);
        }
    }

    public function test_fora_da_janela_pausado_e_consultor_nao_recebem(): void
    {
        Notification::fake();
        $this->itemSemContexto('2026-10-10', ['name' => 'Longe']);
        $this->itemSemContexto('2026-10-07', ['name' => 'Pausado', 'is_active' => false]);
        $this->itemSemContexto('2026-10-06', ['name' => 'Atrasado de ontem']);

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioTitular->id, 'status' => ConsultantClientStatus::Active]);

        self::assertSame(0, app(HealthCareItemService::class)->notifyDue($this->hoje));
        Notification::assertNothingSent();
    }

    public function test_reexecutar_no_mesmo_dia_nao_duplica(): void
    {
        // Sem Notification::fake(): a proteção contra duplicata consulta a tabela
        // `notifications` de verdade (ver HealthCareItemService::alreadyNotifiedToday).
        $this->itemSemContexto('2026-10-07');
        $servico = app(HealthCareItemService::class);

        $servico->notifyDue($this->hoje);
        $servico->notifyDue($this->hoje);

        self::assertSame(1, $this->usuarioTitular->notifications()->where('type', HealthCareItemDue::class)->count());
        self::assertSame(1, $this->usuarioConjuge->notifications()->where('type', HealthCareItemDue::class)->count());
    }
    public function test_aviso_no_sino_e_no_email(): void
    {
        $item = $this->itemSemContexto('2026-10-08');
        $item->load('member');

        $this->usuarioTitular->notifyNow(HealthCareItemDue::forItem($item, $this->hoje));

        $apresentada = NotificationPresenter::present($this->usuarioTitular->notifications()->sole());
        self::assertSame('Cuidado de saúde', $apresentada['heading']);
        self::assertStringContainsString('Filtro', $apresentada['message']);
        self::assertStringContainsString('Marcelo', $apresentada['message']);
        self::assertStringContainsString('é amanhã', $apresentada['message']);
        self::assertSame(route('health.care.index'), $apresentada['url']);

        $mail = HealthCareItemDue::forItem($item, $this->hoje)->toMail($this->usuarioTitular);
        $html = (string) $mail->render();
        self::assertStringContainsString('Filtro', $html);
        self::assertStringContainsString('Aparelho auditivo', $html);
        self::assertStringContainsString('08/10/2026', $html);
        self::assertStringContainsString('é amanhã', $mail->subject);
        self::assertStringNotContainsString('—', $html);
    }
}

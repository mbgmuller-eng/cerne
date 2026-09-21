<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Livewire\Consultant\LeadsIndex;
use App\Mail\ClientInviteMail;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class LeadsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultor_cadastra_um_lead(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleLeadForm')
            ->set('leadName', 'Fernanda Lima')
            ->set('leadEmail', 'fernanda@exemplo.com')
            ->set('leadPhone', '11999990000')
            ->call('saveLead')
            ->assertHasNoErrors();

        $lead = Lead::query()->where('name', 'Fernanda Lima')->sole();
        self::assertSame($consultor->id, $lead->consultant_id);
        self::assertSame(LeadStage::NewContact, $lead->stage);
    }

    public function test_editar_lead_de_outro_consultor_falha(): void
    {
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create();
        $leadDeOutro = Lead::factory()->create(['consultant_id' => $outroConsultor->id]);

        $this->actingAs($consultor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(LeadsIndex::class)->call('editLead', $leadDeOutro->id);
    }

    public function test_quadro_separa_pipeline_aberto_de_convertidos_e_perdidos(): void
    {
        $consultor = User::factory()->consultant()->create();
        Lead::factory()->create(['consultant_id' => $consultor->id, 'name' => 'Em aberto', 'stage' => LeadStage::NewContact]);
        Lead::factory()->create(['consultant_id' => $consultor->id, 'name' => 'Ja perdido', 'stage' => LeadStage::Lost]);

        $this->actingAs($consultor);

        $component = Livewire::test(LeadsIndex::class)->set('showClosed', true);

        $porEstagio = $component->get('leadsByStage');
        self::assertCount(1, $porEstagio->get(LeadStage::NewContact->value));
        self::assertNull($porEstagio->get(LeadStage::Lost->value));

        $fechados = $component->get('closedLeads');
        self::assertCount(1, $fechados);
        self::assertSame('Ja perdido', $fechados->first()->name);
    }

    public function test_busca_por_nome_ou_email(): void
    {
        $consultor = User::factory()->consultant()->create();
        Lead::factory()->create(['consultant_id' => $consultor->id, 'name' => 'Fernanda Lima', 'email' => 'fernanda@exemplo.com']);
        Lead::factory()->create(['consultant_id' => $consultor->id, 'name' => 'Outro Contato', 'email' => 'outro@exemplo.com']);

        $this->actingAs($consultor);

        $component = Livewire::test(LeadsIndex::class)->set('search', 'fernanda');

        $todos = $component->get('leadsByStage')->flatten(1);
        self::assertCount(1, $todos);
    }

    public function test_avancar_e_voltar_estagio(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'stage' => LeadStage::NewContact]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->call('advanceStage', $lead->id);
        self::assertSame(LeadStage::MeetingScheduled, $lead->fresh()->stage);

        Livewire::test(LeadsIndex::class)->call('regressStage', $lead->id);
        self::assertSame(LeadStage::NewContact, $lead->fresh()->stage);
    }

    public function test_avancar_no_ultimo_estagio_aberto_nao_faz_nada(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'stage' => LeadStage::ProposalSent]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->call('advanceStage', $lead->id);

        self::assertSame(LeadStage::ProposalSent, $lead->fresh()->stage);
    }

    public function test_registra_atividade_pelo_componente(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleLogActivity', $lead->id)
            ->set('activityType', 'meeting')
            ->set('activityDescription', 'Reunião marcada pra semana que vem')
            ->call('logActivity')
            ->assertHasNoErrors();

        self::assertSame(1, LeadActivity::query()->where('lead_id', $lead->id)->count());
    }

    public function test_marca_lead_como_perdido_pelo_componente(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->call('toggleMarkLost', $lead->id)
            ->set('lostReason', 'Sem orçamento no momento')
            ->call('markLost')
            ->assertHasNoErrors();

        self::assertSame(LeadStage::Lost, $lead->fresh()->stage);
    }

    public function test_converter_lead_sem_email_mostra_erro_em_vez_de_quebrar(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'email' => null]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->call('convertLead', $lead->id)
            ->assertHasErrors('convert');

        self::assertSame(LeadStage::NewContact, $lead->fresh()->stage);
    }

    public function test_converter_lead_com_email_envia_convite(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'email' => 'fernanda@exemplo.com']);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)
            ->call('convertLead', $lead->id)
            ->assertHasNoErrors();

        self::assertSame(LeadStage::Converted, $lead->fresh()->stage);
        Mail::assertQueued(ClientInviteMail::class);
    }

    public function test_excluir_lead(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id]);
        $this->actingAs($consultor);

        Livewire::test(LeadsIndex::class)->call('deleteLead', $lead->id);

        self::assertSame(0, Lead::query()->count());
    }

    public function test_quem_nao_e_consultor_recebe_403(): void
    {
        $cliente = User::factory()->create();
        $this->actingAs($cliente);

        Livewire::test(LeadsIndex::class)->assertStatus(403);
    }
}

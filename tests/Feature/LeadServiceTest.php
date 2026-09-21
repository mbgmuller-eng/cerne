<?php

namespace Tests\Feature;

use App\Enums\LeadActivityType;
use App\Enums\LeadStage;
use App\Mail\ClientInviteMail;
use App\Models\ConsultantInvite;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Lead é a etapa antes de qualquer perfil existir — converter não duplica
 * a lógica de convite, reaproveita ClientInviteService::send() (mesmo
 * caminho que PortfolioOverview::invite() já usa pra cliente novo).
 */
class LeadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_e_atualiza_um_lead(): void
    {
        $consultor = User::factory()->consultant()->create();

        $lead = app(LeadService::class)->create([
            'consultant_id' => $consultor->id,
            'name' => 'Fernanda Lima',
            'email' => 'fernanda@exemplo.com',
            'phone' => '11999990000',
        ]);

        self::assertSame(LeadStage::NewContact, $lead->stage);

        $atualizado = app(LeadService::class)->update($lead, ['name' => 'Fernanda Lima Souza']);
        self::assertSame('Fernanda Lima Souza', $atualizado->fresh()->name);
    }

    public function test_registra_atividade_do_lead(): void
    {
        $lead = Lead::factory()->create();
        $usuario = User::factory()->create();

        $atividade = app(LeadService::class)->logActivity(
            $lead,
            LeadActivityType::Meeting,
            'Primeira reunião, gostou da proposta',
            CarbonImmutable::parse('2026-09-20 14:00'),
            $usuario->id,
        );

        self::assertSame($lead->id, $atividade->lead_id);
        self::assertSame(LeadActivityType::Meeting, $atividade->type);
        self::assertSame($usuario->id, $atividade->created_by_user_id);
    }

    public function test_marca_lead_como_perdido(): void
    {
        $lead = Lead::factory()->create();

        app(LeadService::class)->markLost($lead, 'Fechou com outro consultor');

        $lead->refresh();
        self::assertSame(LeadStage::Lost, $lead->stage);
        self::assertSame('Fechou com outro consultor', $lead->lost_reason);
    }

    public function test_converter_sem_email_lanca_excecao(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'email' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(LeadService::class)->convert($lead, $consultor);
    }

    public function test_converter_emite_convite_de_verdade_e_marca_lead_como_convertido(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        $lead = Lead::factory()->create([
            'consultant_id' => $consultor->id,
            'name' => 'Fernanda Lima',
            'email' => 'fernanda@exemplo.com',
        ]);

        $link = app(LeadService::class)->convert($lead, $consultor);

        self::assertNotEmpty($link);
        self::assertSame(LeadStage::Converted, $lead->fresh()->stage);

        $convite = ConsultantInvite::query()->where('client_email', 'fernanda@exemplo.com')->sole();
        self::assertSame($consultor->id, $convite->consultant_id);
        self::assertSame('Fernanda Lima', $convite->client_name);

        Mail::assertQueued(ClientInviteMail::class);
    }
}

<?php

namespace App\Services;

use App\Enums\EngagementMeetingFormat;
use App\Models\EngagementMeeting;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Support\Engagement\EngagementViewer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos do Acompanhamento. Quem faz a ação não é avisado dela: o consultor avisa o lado do cliente (dono e cônjuge com
 * login) e o cliente avisa o consultor vinculado.
 */
class EngagementNotifier
{
    public function __construct(private readonly EngagementAccess $access) {}

    /** @param  array<string, mixed>  $extra */
    public function toOtherSide(EngagementViewer $visitante, string $kind, string $heading, string $message, array $extra = []): void
    {
        $perfil = FinancialProfile::query()->find($visitante->profileId);

        if ($perfil === null) {
            return;
        }

        $destinatarios = $visitante->isConsultant()
            ? $this->access->clientUsers($perfil)
            : User::query()->whereIn('id', $visitante->consultantIds)->get();

        $this->send($destinatarios->reject(fn (User $u) => $u->id === $visitante->userId), $perfil->id, $kind, $heading, $message, $extra);
    }

    /** Aviso de reunião ao outro lado, com quem, quando, formato e pauta (ver meetingNotice). */
    public function meetingToOtherSide(EngagementViewer $visitante, EngagementMeeting $reuniao, string $kind, string $tipo, ?CarbonInterface $antes = null): void
    {
        ['heading' => $titulo, 'message' => $mensagem, 'extra' => $extra] = $this->meetingNotice($reuniao, $tipo, $antes);

        $this->toOtherSide($visitante, $kind, $titulo, $mensagem, $extra);
    }

    /**
     * Texto de aviso de reunião. O aviso diz com quem é ("{outro}" é resolvido para cada destinatário), quando, em que formato, onde
     * e a pauta, para que quem lê saiba o que vai acontecer sem abrir o app.
     *
     * @param  'tomorrow'|'scheduled'|'rescheduled'|'cancelled'  $tipo
     * @return array{heading: string, message: string, extra: array<string, mixed>}
     */
    public function meetingNotice(EngagementMeeting $reuniao, string $tipo, ?CarbonInterface $antes = null): array
    {
        $reuniao->loadMissing('consultant');
        $perfil = FinancialProfile::query()->find($reuniao->profile_id);

        $cliente = $perfil === null ? 'o cliente' : $this->access->clientUsers($perfil)
            ->sortBy(fn (User $u) => $u->id === $perfil->owner_user_id ? 0 : 1)->pluck('name')->join(', ', ' e ');
        $consultor = $reuniao->consultant?->name ?? 'o consultor';

        $quando = $this->when($reuniao->scheduled_at);
        $formato = match ($reuniao->format) {
            EngagementMeetingFormat::Video => 'por videochamada',
            EngagementMeetingFormat::Phone => 'por telefone',
            default => 'presencial',
        };

        $linhas = [
            ['label' => 'Cliente', 'value' => $cliente],
            ['label' => 'Consultor', 'value' => $consultor],
            ['label' => 'Quando', 'value' => $quando.' ('.$reuniao->duration_minutes.' min)'],
        ];

        if ($tipo === 'rescheduled' && $antes !== null) {
            $linhas[] = ['label' => 'Era em', 'value' => $this->when($antes)];
        }

        $pauta = $reuniao->agendaItems();

        if ($tipo !== 'cancelled') {
            $linhas[] = ['label' => 'Formato', 'value' => (string) $reuniao->format?->label()];

            if (filled($reuniao->location)) {
                $linhas[] = ['label' => $reuniao->format === EngagementMeetingFormat::InPerson ? 'Local' : 'Link ou contato', 'value' => $reuniao->location];
            }

            $linhas[] = ['label' => 'Pauta', 'value' => $pauta ?: 'Ainda não definida. Combine os assuntos na tela de Acompanhamento.'];

            if (filled($reuniao->prep_notes)) {
                $linhas[] = ['label' => 'O que levar', 'value' => $reuniao->prep_notes];
            }
        }

        $assunto = $pauta !== [] ? ' Assuntos: '.implode('; ', array_slice($pauta, 0, 4)).'.' : '';

        [$titulo, $mensagem] = match ($tipo) {
            'tomorrow' => ['Reunião amanhã com {outro}', "Sua reunião de acompanhamento com {outro} é amanhã, {$quando}, {$formato}.".$assunto],
            'rescheduled' => ['Reunião remarcada com {outro}', "A reunião de acompanhamento com {outro} agora é em {$quando}, {$formato}.".$assunto],
            'cancelled' => ['Reunião cancelada com {outro}', "A reunião de acompanhamento com {outro} que seria em {$quando} foi cancelada."],
            default => ['Reunião marcada com {outro}', "Reunião de acompanhamento com {outro} marcada para {$quando}, {$formato}.".$assunto],
        };

        return [
            'heading' => $titulo,
            'message' => $mensagem,
            'extra' => [
                'consultant_id' => $reuniao->consultant_id,
                'parties' => ['consultant' => $consultor, 'client' => $cliente],
                'details' => $linhas,
                'meeting_id' => $tipo === 'cancelled' ? null : $reuniao->id,
            ],
        ];
    }

    private function when(CarbonInterface $data): string
    {
        return $data->translatedFormat('l, d/m').', às '.$data->format('H:i');
    }

    /**
     * @param  Collection<int, User>  $usuarios
     * @param  array<string, mixed>  $extra
     */
    public function send(Collection $usuarios, string $profileId, string $kind, string $heading, string $message, array $extra = []): void
    {
        if ($usuarios->isEmpty()) {
            return;
        }

        Notification::send($usuarios, new EngagementNotice($profileId, $kind, $heading, $message, $extra));
    }
}

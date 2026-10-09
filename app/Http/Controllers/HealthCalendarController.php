<?php

namespace App\Http\Controllers;

use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Support\CalendarEvent;
use App\Support\ProfileContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Arquivo .ics de uma consulta, exame ou item de cuidado — para a pessoa levar para a agenda dela
 * (Google, Apple, Outlook). Autenticado, só de quem enxerga a Saúde do perfil (os dois do casal):
 * o consultor e o corretor não têm acesso, igual às telas de onde o botão sai.
 *
 * As observações cadastradas só vão no arquivo quando a pessoa pede (?obs=1).
 */
class HealthCalendarController extends Controller
{
    public function appointment(string $appointment, Request $request): Response
    {
        $this->authorizeHealth();

        $consulta = HealthAppointment::query()->with('member')->findOrFail($appointment);

        return $this->download(CalendarEvent::forAppointment($consulta, $request->boolean('obs'), $this->showPerson()));
    }

    public function careItem(string $item, Request $request): Response
    {
        $this->authorizeHealth();

        $cuidado = HealthCareItem::query()->with('member')->findOrFail($item);
        $evento = CalendarEvent::forCareItem($cuidado, $request->boolean('obs'), $this->showPerson());

        // Sem próxima data não há dia para marcar.
        abort_if($evento === null, 404);

        return $this->download($evento);
    }

    private function authorizeHealth(): void
    {
        $context = app(ProfileContext::class);

        abort_if($context->isConsultant() || $context->memberId() === null, 403);
    }

    /** O nome da pessoa só entra na descrição quando o perfil tem mais de uma (casal). */
    private function showPerson(): bool
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->count() > 1;
    }

    private function download(CalendarEvent $evento): Response
    {
        return response($evento->ics(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$evento->filename().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

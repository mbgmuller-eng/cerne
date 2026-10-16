<?php

namespace App\Http\Controllers;

use App\Models\EngagementMeeting;
use App\Models\EngagementTask;
use App\Services\EngagementAccess;
use App\Support\CalendarEvent;
use App\Support\ProfileContext;
use Illuminate\Http\Response;

/**
 * Arquivo .ics de uma reunião ou do prazo de uma tarefa do acompanhamento, para a agenda da pessoa (Google, Apple, Outlook).
 * Só quem tem acesso à relação de consultoria (EngagementAccess): o registro é procurado pelo escopo do acompanhamento, então
 * o de outra relação devolve 404.
 */
class EngagementCalendarController extends Controller
{
    public function meeting(string $meeting): Response
    {
        $visitante = app(EngagementAccess::class)->viewer() ?? abort(404);

        $reuniao = EngagementMeeting::query()->findOrFail($meeting);
        $nome = app(ProfileContext::class)->profile()?->profile_name;

        return $this->download(CalendarEvent::forEngagementMeeting(
            $reuniao,
            $visitante->isConsultant() ? 'Reunião de acompanhamento: '.$nome : 'Reunião de acompanhamento financeiro',
        ));
    }

    public function task(string $task): Response
    {
        app(EngagementAccess::class)->viewer() ?? abort(404);

        $evento = CalendarEvent::forEngagementTask(EngagementTask::query()->findOrFail($task));

        abort_if($evento === null, 404);

        return $this->download($evento);
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

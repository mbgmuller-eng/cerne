<?php

namespace App\Http\Controllers;

use App\Models\HealthAllergy;
use App\Models\HealthCondition;
use App\Models\HealthFollowup;
use App\Models\HealthMedication;
use App\Services\HealthFollowupService;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * O resumo do acompanhamento para levar ao médico: a linha do tempo, o programa de atividades e o que a Ficha de Saúde diz (alergias, doenças e
 * remédios). Tudo lido sob os escopos de quem pede: não sai nada que a pessoa não visse na tela.
 */
class HealthFollowupPdfController extends Controller
{
    public function __invoke(string $followup, HealthFollowupService $servico, ProfileContext $contexto): Response
    {
        abort_unless($contexto->isHealthParticipant(), 403);

        $acompanhamento = HealthFollowup::query()->with('member')->findOrFail($followup);
        $membro = $acompanhamento->member_id;

        $pdf = Pdf::loadView('pdf.health-followup', [
            'acompanhamento' => $acompanhamento,
            'linhas' => $servico->timeline($acompanhamento),
            'atividades' => $acompanhamento->activities()->with('exercises')
                ->withCount(['sessions as presencas' => fn ($q) => $q->where('attended', true), 'sessions as faltas' => fn ($q) => $q->where('attended', false)])
                ->withAvg(['sessions as dor_media' => fn ($q) => $q->where('attended', true)], 'pain')
                ->orderBy('created_at')->get(),
            'alergias' => HealthAllergy::query()->where('member_id', $membro)->get(),
            'doencas' => HealthCondition::query()->where('member_id', $membro)->get(),
            // Só os remédios em uso: os encerrados confundiriam quem lê.
            'remedios' => HealthMedication::query()->where('member_id', $membro)->where('is_active', true)->get(),
            'geradoEm' => now(),
        ]);

        // O nome do arquivo não leva o nome do problema: ele aparece na pasta de downloads e no histórico do navegador.
        $nome = 'resumo-de-saude-'.Str::slug($acompanhamento->member->name).'-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($nome)->header('Cache-Control', 'no-store, private');
    }
}

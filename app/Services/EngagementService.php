<?php

namespace App\Services;

use App\Enums\EngagementMeetingFormat;
use App\Enums\EngagementMeetingStatus;
use App\Enums\EngagementRequestStatus;
use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Mail\EngagementPlanMail;
use App\Models\EngagementAttachment;
use App\Models\EngagementMeeting;
use App\Models\EngagementNote;
use App\Models\EngagementRequest;
use App\Models\EngagementRequestComment;
use App\Models\EngagementTask;
use App\Models\FinancialProfile;
use App\Support\Engagement\EngagementDocuments;
use App\Support\Engagement\EngagementViewer;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Regras do Acompanhamento: quem pode fazer o quê, em que situação, e quem é avisado.
 *
 *  - Reunião: o consultor marca, remarca, cancela e escreve a ata; os dois editam a pauta e o que levar.
 *  - Tarefa: os dois criam. O consultor mexe em qualquer uma; o cliente, nas que criou ou que são dele.
 *  - Demanda: o cliente abre; os dois conversam. Resposta do consultor deixa "respondida"; o cliente voltando a escrever
 *    reabre. O consultor pode transformá-la em tarefa.
 *  - Nota: compartilhada, os dois escrevem; privada, só o consultor (e só ele a vê).
 *
 * O visitante (EngagementViewer) vem de EngagementAccess; os models já só aparecem dentro da relação dele (EngagementScope).
 */
class EngagementService
{
    public function __construct(private readonly EngagementNotifier $avisos) {}

    // ------------------------------------------------------------------ reuniões

    /**
     * @param  array{scheduled_at: CarbonInterface, duration_minutes?: int, format?: string, location?: ?string, agenda?: ?string, prep_notes?: ?string}  $dados
     */
    public function saveMeeting(EngagementViewer $v, ?EngagementMeeting $reuniao, array $dados): EngagementMeeting
    {
        $this->somenteConsultor($v);

        $novo = $reuniao === null;
        $antes = $reuniao?->scheduled_at;

        $reuniao ??= new EngagementMeeting(['profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'created_by_user_id' => $v->userId, 'status' => EngagementMeetingStatus::Scheduled]);

        $reuniao->fill([
            'scheduled_at' => $dados['scheduled_at'],
            'duration_minutes' => $dados['duration_minutes'] ?? 45,
            'format' => $dados['format'] ?? EngagementMeetingFormat::Video->value,
            'location' => $dados['location'] ?? null,
            'agenda' => $dados['agenda'] ?? null,
            'prep_notes' => $dados['prep_notes'] ?? null,
        ])->save();

        if ($novo) {
            $this->avisos->meetingToOtherSide($v, $reuniao, 'meeting_scheduled', 'scheduled');
        } elseif ($antes !== null && ! $antes->equalTo($reuniao->scheduled_at)) {
            $this->avisos->meetingToOtherSide($v, $reuniao, 'meeting_rescheduled', 'rescheduled', $antes);
        }

        return $reuniao;
    }

    /**
     * Marca uma série de reuniões de uma vez (semanal, quinzenal ou mensal), todas com a mesma pauta. Cada uma é uma reunião
     * comum depois de criada (dá para remarcar, cancelar e escrever a ata em cada uma); o que as une é o `series_id`, que
     * permite cancelar "esta e as próximas". O cliente recebe um aviso só.
     *
     * @param  array{scheduled_at: CarbonInterface, duration_minutes?: int, format?: string, location?: ?string, agenda?: ?string, prep_notes?: ?string}  $dados
     * @return Collection<int, EngagementMeeting>
     */
    public function scheduleSeries(EngagementViewer $v, array $dados, string $frequencia, int $vezes): Collection
    {
        $this->somenteConsultor($v);

        if (! in_array($frequencia, ['weekly', 'biweekly', 'monthly'], true) || $vezes < 2 || $vezes > 12) {
            throw new \InvalidArgumentException('Série inválida: escolha a frequência e de 2 a 12 reuniões.');
        }

        $serie = (string) Str::uuid();
        $primeira = \Carbon\CarbonImmutable::instance($dados['scheduled_at']);

        $reunioes = DB::transaction(function () use ($v, $dados, $frequencia, $vezes, $serie, $primeira): Collection {
            return collect(range(0, $vezes - 1))->map(function (int $i) use ($v, $dados, $frequencia, $serie, $primeira): EngagementMeeting {
                $quando = match ($frequencia) {
                    'weekly' => $primeira->addWeeks($i),
                    'biweekly' => $primeira->addWeeks(2 * $i),
                    default => $primeira->addMonthsNoOverflow($i),
                };

                return EngagementMeeting::create([
                    'profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'created_by_user_id' => $v->userId, 'series_id' => $serie,
                    'status' => EngagementMeetingStatus::Scheduled, 'scheduled_at' => $quando,
                    'duration_minutes' => $dados['duration_minutes'] ?? 45, 'format' => $dados['format'] ?? EngagementMeetingFormat::Video->value,
                    'location' => $dados['location'] ?? null, 'agenda' => $dados['agenda'] ?? null, 'prep_notes' => $dados['prep_notes'] ?? null,
                ]);
            });
        });

        $rotulo = ['weekly' => 'toda semana', 'biweekly' => 'a cada 15 dias', 'monthly' => 'todo mês'][$frequencia];
        $this->avisos->toOtherSide($v, 'meeting_series', 'Reuniões marcadas', "{$vezes} reuniões de acompanhamento, {$rotulo}, a primeira em ".$primeira->format('d/m \à\s H:i').'.');

        return $reunioes;
    }

    /** Pauta e "o que levar": os dois lados editam, enquanto a reunião não aconteceu. */
    public function updateAgenda(EngagementViewer $v, EngagementMeeting $reuniao, ?string $pauta, ?string $levar): EngagementMeeting
    {
        $this->daRelacao($v, $reuniao->consultant_id);

        if ($reuniao->status !== EngagementMeetingStatus::Scheduled) {
            throw new AuthorizationException('A pauta só pode ser editada antes da reunião.');
        }

        $reuniao->update(['agenda' => $pauta, 'prep_notes' => $levar]);

        return $reuniao;
    }

    public function completeMeeting(EngagementViewer $v, EngagementMeeting $reuniao, ?string $ata): EngagementMeeting
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $reuniao->consultant_id);

        $reuniao->update(['status' => EngagementMeetingStatus::Done, 'summary' => $ata]);

        if (trim((string) $ata) !== '') {
            $this->avisos->toOtherSide($v, 'meeting_summary', 'Ata da reunião', 'O resumo da reunião de '.$reuniao->scheduled_at->format('d/m').' já está no acompanhamento.');
        }

        return $reuniao;
    }

    /** Cancela a reunião; com `$eProximas`, cancela também as da mesma série que ainda não aconteceram. */
    public function cancelMeeting(EngagementViewer $v, EngagementMeeting $reuniao, bool $eProximas = false): EngagementMeeting
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $reuniao->consultant_id);

        if ($eProximas && $reuniao->series_id !== null) {
            $canceladas = EngagementMeeting::query()
                ->where('series_id', $reuniao->series_id)
                ->where('status', EngagementMeetingStatus::Scheduled)
                ->where('scheduled_at', '>=', $reuniao->scheduled_at)
                ->update(['status' => EngagementMeetingStatus::Cancelled, 'updated_at' => now()]);

            $this->avisos->toOtherSide($v, 'meeting_cancelled', 'Reuniões canceladas', "{$canceladas} reuniões de acompanhamento, a partir de ".$reuniao->scheduled_at->format('d/m').', foram canceladas.');

            return $reuniao->refresh();
        }

        $reuniao->update(['status' => EngagementMeetingStatus::Cancelled]);
        $this->avisos->meetingToOtherSide($v, $reuniao, 'meeting_cancelled', 'cancelled');

        return $reuniao;
    }

    // ------------------------------------------------------------------ tarefas

    /**
     * @param  array{title: string, description?: ?string, owner: string, due_date?: ?CarbonInterface, meeting_id?: ?string}  $dados
     */
    public function saveTask(EngagementViewer $v, ?EngagementTask $tarefa, array $dados): EngagementTask
    {
        if ($tarefa !== null) {
            $this->podeEditarTarefa($v, $tarefa);
        }

        $dono = EngagementTaskOwner::from($dados['owner']);

        // A reunião de origem precisa ser da mesma relação (o escopo só deixa achar as dela).
        if (! empty($dados['meeting_id']) && ! EngagementMeeting::query()->whereKey($dados['meeting_id'])->exists()) {
            throw new AuthorizationException('Reunião de outra relação de consultoria.');
        }

        $novo = $tarefa === null;
        $donoAntes = $tarefa?->owner;

        $tarefa ??= new EngagementTask(['profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'created_by_user_id' => $v->userId, 'status' => EngagementTaskStatus::Todo]);

        $tarefa->fill([
            'title' => $dados['title'],
            'description' => $dados['description'] ?? null,
            'owner' => $dono,
            'due_date' => $dados['due_date'] ?? null,
            'meeting_id' => $dados['meeting_id'] ?? null,
        ])->save();

        // Quem recebeu a tarefa de outra pessoa é avisado; quem cria a própria tarefa não precisa de aviso.
        $paraOutroLado = $dono === EngagementTaskOwner::Client ? $v->isConsultant() : $v->isClient();

        if ($paraOutroLado && ($novo || $donoAntes !== $dono)) {
            $prazo = $tarefa->due_date !== null ? ' Prazo: '.$tarefa->due_date->format('d/m').'.' : '';
            $this->avisos->toOtherSide($v, 'task_assigned', 'Nova tarefa para você', $tarefa->title.'.'.$prazo);
        }

        return $tarefa;
    }

    public function setTaskStatus(EngagementViewer $v, EngagementTask $tarefa, EngagementTaskStatus $situacao): EngagementTask
    {
        $this->podeMudarSituacao($v, $tarefa);

        $tarefa->update([
            'status' => $situacao,
            'completed_at' => $situacao === EngagementTaskStatus::Done ? now() : null,
        ]);

        return $tarefa;
    }

    public function deleteTask(EngagementViewer $v, EngagementTask $tarefa): void
    {
        $this->podeEditarTarefa($v, $tarefa);

        $tarefa->delete();
    }

    // ------------------------------------------------------------------ demandas

    public function openRequest(EngagementViewer $v, string $titulo, string $texto): EngagementRequest
    {
        if (! $v->isClient()) {
            throw new AuthorizationException('Quem abre uma demanda é o cliente.');
        }

        $demanda = EngagementRequest::create([
            'profile_id' => $v->profileId,
            'consultant_id' => $v->consultantId(),
            'title' => $titulo,
            'body' => $texto,
            'status' => EngagementRequestStatus::Open,
            'created_by_user_id' => $v->userId,
        ]);

        $this->avisos->toOtherSide($v, 'request_opened', 'Nova demanda de cliente', $titulo);

        return $demanda;
    }

    public function commentRequest(EngagementViewer $v, EngagementRequest $demanda, string $texto): EngagementRequestComment
    {
        $this->daRelacao($v, $demanda->consultant_id);

        return DB::transaction(function () use ($v, $demanda, $texto): EngagementRequestComment {
            $comentario = EngagementRequestComment::create([
                'profile_id' => $demanda->profile_id,
                'consultant_id' => $demanda->consultant_id,
                'request_id' => $demanda->id,
                'author_user_id' => $v->userId,
                'body' => $texto,
            ]);

            // Resposta do consultor deixa a demanda "respondida"; o cliente voltando a escrever a reabre.
            $demanda->update($v->isConsultant()
                ? ['status' => EngagementRequestStatus::Answered, 'resolved_at' => null]
                : ['status' => EngagementRequestStatus::Open, 'resolved_at' => null]);

            $this->avisos->toOtherSide($v, 'request_comment', $v->isConsultant() ? 'Sua demanda foi respondida' : 'Nova mensagem em uma demanda', $demanda->title);

            return $comentario;
        });
    }

    public function resolveRequest(EngagementViewer $v, EngagementRequest $demanda): EngagementRequest
    {
        $this->daRelacao($v, $demanda->consultant_id);

        $demanda->update(['status' => EngagementRequestStatus::Resolved, 'resolved_at' => now()]);

        return $demanda;
    }

    /** O consultor transforma a demanda em tarefa; a demanda fica ligada a ela e é dada como respondida. */
    public function convertRequestToTask(EngagementViewer $v, EngagementRequest $demanda, string $dono, ?CarbonInterface $prazo = null): EngagementTask
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $demanda->consultant_id);

        return DB::transaction(function () use ($v, $demanda, $dono, $prazo): EngagementTask {
            $tarefa = $this->saveTask($v, null, ['title' => $demanda->title, 'description' => $demanda->body, 'owner' => $dono, 'due_date' => $prazo]);

            $demanda->update(['task_id' => $tarefa->id, 'status' => EngagementRequestStatus::Answered]);

            return $tarefa;
        });
    }

    // ------------------------------------------------------------------ notas

    public function addNote(EngagementViewer $v, string $texto, bool $privada): EngagementNote
    {
        if ($privada && ! $v->isConsultant()) {
            throw new AuthorizationException('Só o consultor escreve notas privadas.');
        }

        return EngagementNote::create([
            'profile_id' => $v->profileId,
            'consultant_id' => $v->consultantId(),
            'author_user_id' => $v->userId,
            'body' => $texto,
            'is_private' => $privada,
        ]);
    }

    public function deleteNote(EngagementViewer $v, EngagementNote $nota): void
    {
        if ($nota->author_user_id !== $v->userId) {
            throw new AuthorizationException('Só quem escreveu a nota pode apagá-la.');
        }

        $nota->delete();
    }

    // ------------------------------------------------------------------ anexos (área de Documentos)

    /**
     * Anexa um documento do cofre a uma reunião ou tarefa. Só entra documento que o consultor também pode ver (ver
     * EngagementDocuments): o anexo é uma referência, e a regra de visibilidade do documento não muda por causa dele.
     */
    public function attachDocument(EngagementViewer $v, string $tipo, string $assuntoId, string $documentoId): EngagementAttachment
    {
        $existe = match ($tipo) {
            EngagementAttachment::MEETING => EngagementMeeting::query()->whereKey($assuntoId)->exists(),
            EngagementAttachment::TASK => EngagementTask::query()->whereKey($assuntoId)->exists(),
            default => false,
        };

        if (! $existe) {
            throw new AuthorizationException('Reunião ou tarefa de outra relação de consultoria.');
        }

        $perfil = FinancialProfile::query()->findOrFail($v->profileId);

        if (! EngagementDocuments::shareable($perfil)->contains('id', $documentoId)) {
            throw new AuthorizationException('Este documento não pode ser compartilhado com o consultor.');
        }

        return EngagementAttachment::query()->firstOrCreate(
            ['subject_type' => $tipo, 'subject_id' => $assuntoId, 'document_id' => $documentoId],
            ['profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'attached_by_user_id' => $v->userId],
        );
    }

    public function detachDocument(EngagementViewer $v, EngagementAttachment $anexo): void
    {
        $this->daRelacao($v, $anexo->consultant_id);

        if ($v->isClient() && $anexo->attached_by_user_id !== $v->userId) {
            throw new AuthorizationException('Só quem anexou, ou o consultor, remove o anexo.');
        }

        $anexo->delete();
    }

    // ------------------------------------------------------------------ plano em PDF

    /**
     * O consultor manda o plano em PDF ao e-mail do cliente quando quiser, sem encerrar o vínculo. O cliente nunca baixa por
     * conta própria: quem decide o que sai da relação é o consultor.
     *
     * @return string o e-mail para onde foi
     */
    public function sendPlanToClient(EngagementViewer $v): string
    {
        $this->somenteConsultor($v);

        $perfil = FinancialProfile::query()->findOrFail($v->profileId);

        if (! app(EngagementPlanReport::class)->hasData($perfil->id, $v->consultantId())) {
            throw new AuthorizationException('Ainda não há nada no plano para enviar.');
        }

        $email = $perfil->owner->email;

        Mail::to($email)->queue(new EngagementPlanMail($perfil->id, $v->consultantId(), \App\Models\User::query()->findOrFail($v->consultantId())->name, $perfil->profile_name, false));

        return $email;
    }

    // ------------------------------------------------------------------ permissões

    private function somenteConsultor(EngagementViewer $v): void
    {
        if (! $v->isConsultant()) {
            throw new AuthorizationException('Essa ação é do consultor.');
        }
    }

    private function daRelacao(EngagementViewer $v, string $consultantId): void
    {
        if (! in_array($consultantId, $v->consultantIds, true)) {
            throw new AuthorizationException('Registro de outra relação de consultoria.');
        }
    }

    private function podeEditarTarefa(EngagementViewer $v, EngagementTask $tarefa): void
    {
        $this->daRelacao($v, $tarefa->consultant_id);

        if ($v->isClient() && $tarefa->created_by_user_id !== $v->userId) {
            throw new AuthorizationException('Só o consultor edita uma tarefa que ele criou.');
        }
    }

    private function podeMudarSituacao(EngagementViewer $v, EngagementTask $tarefa): void
    {
        $this->daRelacao($v, $tarefa->consultant_id);

        if ($v->isClient() && $tarefa->created_by_user_id !== $v->userId && $tarefa->owner !== EngagementTaskOwner::Client) {
            throw new AuthorizationException('Essa tarefa é do consultor.');
        }
    }
}

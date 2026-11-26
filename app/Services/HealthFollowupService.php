<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Enums\HealthAppointmentKind;
use App\Enums\HealthAppointmentStatus;
use App\Enums\HealthFollowupStatus;
use App\Models\Document;
use App\Models\HealthAppointment;
use App\Models\HealthExamRequest;
use App\Models\HealthFollowup;
use App\Models\HealthFollowupNote;
use App\Models\HealthRoutine;
use App\Models\ProfileMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Acompanhamento de um problema de saúde: o cadastro, a ligação com compromissos e documentos, as anotações, os exames pedidos sem data e a linha
 * do tempo. Tudo é lido sob os escopos de quem olha: ligar um documento ao acompanhamento não abre documento nenhum a quem não o via.
 */
class HealthFollowupService
{
    /** @param  array{title: string, status: string, started_on: string, appointment_id?: ?string}  $dados */
    public function create(ProfileMember $membro, array $dados, ?ProfileMember $autor): HealthFollowup
    {
        return DB::transaction(function () use ($membro, $dados, $autor): HealthFollowup {
            $situacao = HealthFollowupStatus::from($dados['status']);

            $acompanhamento = HealthFollowup::create([
                'member_id' => $membro->id,
                'title' => trim($dados['title']),
                'status' => $situacao,
                'started_on' => $dados['started_on'],
                'resolved_on' => $situacao === HealthFollowupStatus::Resolved ? CarbonImmutable::today() : null,
                'created_by_member_id' => $autor?->id,
            ]);

            if (! empty($dados['appointment_id'])) {
                $this->attachAppointment($acompanhamento, HealthAppointment::query()->findOrFail($dados['appointment_id']));
            }

            return $acompanhamento;
        });
    }

    public function rename(HealthFollowup $acompanhamento, string $titulo, string $inicio): void
    {
        $acompanhamento->update(['title' => trim($titulo), 'started_on' => $inicio]);
    }

    /** Resolvido guarda a data; reabrir a apaga. */
    public function setStatus(HealthFollowup $acompanhamento, HealthFollowupStatus $situacao): void
    {
        $acompanhamento->update([
            'status' => $situacao,
            'resolved_on' => $situacao === HealthFollowupStatus::Resolved ? ($acompanhamento->resolved_on ?? CarbonImmutable::today()) : null,
        ]);
    }

    /** @param  array{guided_by?: ?string, guided_on?: ?string, avoid?: ?string}  $dados */
    public function saveProgram(HealthFollowup $acompanhamento, array $dados): void
    {
        $acompanhamento->update([
            'guided_by' => $this->blankToNull($dados['guided_by'] ?? null),
            'guided_on' => ($dados['guided_on'] ?? null) ?: null,
            'avoid' => $this->blankToNull($dados['avoid'] ?? null),
        ]);
    }

    /** Anotações, exames sem data (ficam como pedidos soltos), atividades e presenças. Os compromissos e documentos continuam. */
    public function delete(HealthFollowup $acompanhamento): void
    {
        $acompanhamento->delete();
    }

    /** Liga um compromisso da Agenda. Só da mesma pessoa; ligar de novo não duplica. */
    public function attachAppointment(HealthFollowup $acompanhamento, HealthAppointment $consulta): void
    {
        $this->assertSamePerson($acompanhamento, $consulta->member_id);
        $this->assertCanWrite($acompanhamento);

        $acompanhamento->appointments()->syncWithoutDetaching([$consulta->id]);
    }

    public function detachAppointment(HealthFollowup $acompanhamento, string $consultaId): void
    {
        $this->assertCanWrite($acompanhamento);

        $acompanhamento->appointments()->detach($consultaId);
    }

    public function addNote(HealthFollowup $acompanhamento, string $data, ?string $texto, ?int $dor, ?ProfileMember $autor): HealthFollowupNote
    {
        $texto = $this->blankToNull($texto);

        if ($texto === null && $dor === null) {
            throw new InvalidArgumentException('Escreva a anotação ou escolha a nota de dor.');
        }

        return HealthFollowupNote::create([
            'member_id' => $acompanhamento->member_id,
            'followup_id' => $acompanhamento->id,
            'noted_on' => $data,
            'text' => $texto,
            'pain' => $dor === null ? null : max(0, min(10, $dor)),
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /**
     * Exame (ou consulta) pedido e ainda sem data.
     *
     * @param  array{kind?: string, title: string, requested_on?: ?string, requested_by?: ?string}  $dados
     */
    public function requestExam(ProfileMember $membro, array $dados, ?HealthRoutine $rotina, ?HealthFollowup $acompanhamento, ?ProfileMember $autor): HealthExamRequest
    {
        if ($rotina !== null && $rotina->member_id !== $membro->id) {
            throw new InvalidArgumentException('A rotina é de outra pessoa.');
        }

        if ($acompanhamento !== null) {
            $this->assertSamePerson($acompanhamento, $membro->id);
        }

        return HealthExamRequest::create([
            'member_id' => $membro->id,
            'kind' => HealthAppointmentKind::from($dados['kind'] ?? HealthAppointmentKind::Exam->value),
            'title' => trim($dados['title']),
            'requested_on' => ($dados['requested_on'] ?? null) ?: CarbonImmutable::today(),
            'requested_by' => $this->blankToNull($dados['requested_by'] ?? null),
            'routine_id' => $rotina?->id,
            'followup_id' => $acompanhamento?->id,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /** Marcou a data: o pedido vira um compromisso da Agenda (com lembrete e avisos de sempre), ligado à mesma rotina e ao mesmo acompanhamento. */
    public function scheduleRequest(HealthExamRequest $pedido, string $quando, ?ProfileMember $autor): HealthAppointment
    {
        if ($pedido->appointment_id !== null) {
            throw new InvalidArgumentException('Este pedido já tem data marcada.');
        }

        return DB::transaction(function () use ($pedido, $quando, $autor): HealthAppointment {
            $membro = ProfileMember::query()->visibleHere()->findOrFail($pedido->member_id);
            $consulta = app(HealthAppointmentService::class)->create($membro, [
                'kind' => $pedido->kind,
                'title' => $pedido->title,
                'scheduled_at' => $quando,
            ], $autor);

            if ($pedido->routine_id !== null) {
                $consulta->update(['routine_id' => $pedido->routine_id]);
            }

            $pedido->update(['appointment_id' => $consulta->id]);

            if ($pedido->followup_id !== null) {
                $this->attachAppointment(HealthFollowup::query()->findOrFail($pedido->followup_id), $consulta);
            }

            return $consulta;
        });
    }

    public function deleteRequest(HealthExamRequest $pedido): void
    {
        $pedido->delete();
    }

    /** Liga um documento que já está guardado (da mesma pessoa, ou sem pessoa). Não muda quem pode vê-lo. */
    public function linkDocument(HealthFollowup $acompanhamento, Document $documento, ?HealthAppointment $consulta = null): void
    {
        if ($documento->member_id !== null) {
            $this->assertSamePerson($acompanhamento, $documento->member_id);
        }

        if ($consulta !== null && ! $acompanhamento->appointments()->whereKey($consulta->id)->exists()) {
            throw new InvalidArgumentException('Este compromisso não está no acompanhamento.');
        }

        $this->assertCanWrite($acompanhamento);

        $documento->update(['health_followup_id' => $acompanhamento->id, 'health_appointment_id' => $consulta?->id]);
    }

    public function unlinkDocument(Document $documento): void
    {
        $documento->update(['health_followup_id' => null, 'health_appointment_id' => null]);
    }

    /** Envia um arquivo novo para os Documentos (categoria exame de saúde, da pessoa) e já o liga. */
    public function uploadDocument(HealthFollowup $acompanhamento, UploadedFile $arquivo, string $titulo, ?HealthAppointment $consulta, ?ProfileMember $autor): Document
    {
        $this->assertCanWrite($acompanhamento);

        $documento = app(DocumentService::class)->upload($arquivo, [
            'category' => DocumentCategory::HealthExam->value,
            'title' => $titulo,
            'member_id' => $acompanhamento->member_id,
        ], $autor);

        $this->linkDocument($acompanhamento, $documento, $consulta);

        return $documento;
    }

    /**
     * A linha do tempo, do mais antigo para o mais novo, com o que está sem data no fim. Só entra o que quem olha pode ver.
     *
     * @return list<array{date: ?CarbonImmutable, type: string, label: string, title: string, text: ?string, id: string, pain: ?int, pending: bool, routine: ?string, documents: list<Document>, by: ?\Illuminate\Database\Eloquent\Model}>
     */
    public function timeline(HealthFollowup $acompanhamento): array
    {
        $consultas = $acompanhamento->appointments()->with(['routine', 'createdByUser'])->get();
        $documentos = $acompanhamento->documents()->orderBy('created_at')->get();
        $linhas = [];

        foreach ($consultas as $c) {
            $detalhe = implode(' · ', array_filter([$c->specialty, $c->professional_name, $c->location]));

            $linhas[] = $this->entry(
                CarbonImmutable::instance($c->scheduled_at), 'appointment', $c->kind->label().($c->status === HealthAppointmentStatus::Scheduled ? ($c->scheduled_at->isFuture() ? ' marcada' : ' a confirmar') : ' · '.mb_strtolower($c->status->label())),
                $c->title, $this->blankToNull(trim($detalhe."\n".(string) $c->notes)), $c->id, $c,
                routine: $c->routine?->name,
                documents: $documentos->where('health_appointment_id', $c->id)->values()->all(),
            );
        }

        $soltos = $documentos->filter(fn (Document $d) => $d->health_appointment_id === null || ! $consultas->contains('id', $d->health_appointment_id));

        foreach ($soltos as $d) {
            $linhas[] = $this->entry(CarbonImmutable::instance($d->created_at), 'document', 'Documento', $d->title, null, $d->id, null, documents: [$d]);
        }

        foreach ($acompanhamento->notes()->with('createdByUser')->get() as $n) {
            $linhas[] = $this->entry(
                CarbonImmutable::parse($n->noted_on->toDateString()), 'note', 'Anotação', $n->pain !== null ? "Dor {$n->pain} de 10" : 'Anotação', $n->text, $n->id, $n, pain: $n->pain,
            );
        }

        if ($acompanhamento->resolved_on !== null) {
            $linhas[] = $this->entry(CarbonImmutable::parse($acompanhamento->resolved_on->toDateString())->endOfDay(), 'status', 'Situação', 'Marcado como resolvido', null, $acompanhamento->id, null);
        }

        usort($linhas, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        foreach ($acompanhamento->requests()->open()->with('createdByUser')->orderBy('requested_on')->get() as $p) {
            $linhas[] = $this->entry(null, 'request', $p->kind->label().' pedido', $p->title, $p->requested_by !== null ? 'Pedido por '.$p->requested_by : null, $p->id, $p, pending: true);
        }

        return $linhas;
    }

    private function entry(?CarbonImmutable $data, string $tipo, string $rotulo, string $titulo, ?string $texto, string $id, ?\Illuminate\Database\Eloquent\Model $origem, ?int $pain = null, bool $pending = false, ?string $routine = null, array $documents = []): array
    {
        return ['date' => $data, 'type' => $tipo, 'label' => $rotulo, 'title' => $titulo, 'text' => $texto, 'id' => $id, 'pain' => $pain, 'pending' => $pending, 'routine' => $routine, 'documents' => $documents, 'by' => $origem];
    }

    private function assertSamePerson(HealthFollowup $acompanhamento, string $membroId): void
    {
        if ($acompanhamento->member_id !== $membroId) {
            throw new InvalidArgumentException('Isto é de outra pessoa.');
        }
    }

    /** A tabela de ligação e o envio de arquivo não passam pela trava de escrita do model: quem só lê é barrado aqui. */
    private function assertCanWrite(HealthFollowup $acompanhamento): void
    {
        abort_unless(app(\App\Support\ProfileContext::class)->canWriteAsCaregiver($acompanhamento->member_id), 403);
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

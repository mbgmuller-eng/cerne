<?php

namespace App\Services;

use App\Enums\HealthAppointmentKind;
use App\Enums\HealthExamItemStatus;
use App\Models\HealthAppointment;
use App\Models\HealthExamItem;
use App\Models\HealthExamRequest;
use App\Models\HealthFollowup;
use App\Models\HealthRoutine;
use App\Models\ProfileMember;
use App\Support\Health\ExamCatalog;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pedidos de exame: o pedido do médico com a lista, e a marcação das datas. Vários exames podem ir para o mesmo compromisso da Agenda; os outros
 * continuam esperando. O Cerne não sugere exame: a lista só registra o que foi pedido.
 */
class HealthExamService
{
    public const MAX_ITEMS = 60;

    /**
     * @param  array{title: string, category?: ?string, requested_by?: ?string, requested_on?: ?string, notes?: ?string, items: list<array{name: string, detail?: ?string}>}  $dados
     */
    public function createOrder(ProfileMember $membro, array $dados, ?HealthRoutine $rotina, ?HealthFollowup $acompanhamento, ?ProfileMember $autor): HealthExamRequest
    {
        $itens = $this->cleanItems($dados['items'] ?? []);
        $titulo = trim((string) ($dados['title'] ?? ''));

        if ($titulo === '') {
            throw new InvalidArgumentException('Dê um nome ao pedido.');
        }

        if ($itens === []) {
            throw new InvalidArgumentException('Marque pelo menos um exame.');
        }

        if (($rotina !== null && $rotina->member_id !== $membro->id) || ($acompanhamento !== null && $acompanhamento->member_id !== $membro->id)) {
            throw new InvalidArgumentException('Isto é de outra pessoa.');
        }

        $categoria = $dados['category'] ?? null;

        return DB::transaction(function () use ($membro, $dados, $itens, $titulo, $categoria, $rotina, $acompanhamento, $autor): HealthExamRequest {
            $pedido = HealthExamRequest::create([
                'member_id' => $membro->id,
                'kind' => $this->kindFor(array_column($itens, 'name')),
                'category' => isset(ExamCatalog::CATEGORIES[$categoria]) ? $categoria : null,
                'title' => $titulo,
                'requested_on' => ($dados['requested_on'] ?? null) ?: CarbonImmutable::today(),
                'requested_by' => $this->blankToNull($dados['requested_by'] ?? null),
                'notes' => $this->blankToNull($dados['notes'] ?? null),
                'routine_id' => $rotina?->id,
                'followup_id' => $acompanhamento?->id,
                'created_by_member_id' => $autor?->id,
            ]);

            foreach ($itens as $posicao => $item) {
                $this->createItem($pedido, $item, $posicao, $autor);
            }

            return $pedido;
        });
    }

    /** @param  array{title: string, requested_by?: ?string, notes?: ?string}  $dados */
    public function updateOrder(HealthExamRequest $pedido, array $dados): void
    {
        if (trim($dados['title']) === '') {
            throw new InvalidArgumentException('Dê um nome ao pedido.');
        }

        $pedido->update([
            'title' => trim($dados['title']),
            'requested_by' => $this->blankToNull($dados['requested_by'] ?? null),
            'notes' => $this->blankToNull($dados['notes'] ?? null),
        ]);
    }

    /** Os laudos já guardados continuam (só soltam o pedido); os compromissos continuam na Agenda. */
    public function deleteOrder(HealthExamRequest $pedido): void
    {
        $pedido->delete();
    }

    public function addItem(HealthExamRequest $pedido, string $nome, ?string $detalhe, ?ProfileMember $autor): HealthExamItem
    {
        $item = $this->cleanItems([['name' => $nome, 'detail' => $detalhe]])[0] ?? throw new InvalidArgumentException('Informe o nome do exame.');

        if ($pedido->items()->count() >= self::MAX_ITEMS) {
            throw new InvalidArgumentException('Este pedido já tem '.self::MAX_ITEMS.' exames.');
        }

        return $this->createItem($pedido, $item, (int) $pedido->items()->max('position') + 1, $autor);
    }

    /** Exame com resultado não sai (o resultado ficaria sem pedido): primeiro se exclui o resultado. */
    public function removeItem(HealthExamItem $item): void
    {
        if ($item->report_id !== null) {
            throw new InvalidArgumentException('Este exame já tem resultado. Exclua o resultado antes.');
        }

        $item->delete();
    }

    /**
     * Marca juntos os exames escolhidos: um compromisso na Agenda (com o lembrete e os avisos de sempre), ligado ao mesmo check-up e ao mesmo
     * acompanhamento do pedido.
     *
     * @param  list<string>  $itemIds
     */
    public function schedule(HealthExamRequest $pedido, array $itemIds, string $quando, ?string $local, ?ProfileMember $autor): HealthAppointment
    {
        $itens = $pedido->items()->with('appointment')->get()->whereIn('id', $itemIds)->values();

        if ($itens->isEmpty() || $itens->count() !== count(array_unique($itemIds))) {
            throw new InvalidArgumentException('Escolha os exames deste pedido.');
        }

        if ($itens->contains(fn (HealthExamItem $i) => $i->status() !== HealthExamItemStatus::Pending)) {
            throw new InvalidArgumentException('Um dos exames já tem data marcada ou resultado.');
        }

        return DB::transaction(function () use ($pedido, $itens, $quando, $local, $autor): HealthAppointment {
            $membro = ProfileMember::query()->visibleHere()->findOrFail($pedido->member_id);

            $consulta = app(HealthAppointmentService::class)->create($membro, [
                'kind' => $this->kindFor($itens->pluck('name')->all()),
                'title' => $itens->count() === 1 ? $itens->first()->name : $pedido->title,
                'scheduled_at' => $quando,
                'location' => $local,
                'notes' => $this->appointmentNotes($pedido, $itens),
            ], $autor);

            if ($pedido->routine_id !== null) {
                $consulta->update(['routine_id' => $pedido->routine_id]);
            }

            if ($pedido->followup_id !== null) {
                app(HealthFollowupService::class)->attachAppointment(HealthFollowup::query()->findOrFail($pedido->followup_id), $consulta);
            }

            foreach ($itens as $item) {
                $item->update(['appointment_id' => $consulta->id]);
            }

            return $consulta;
        });
    }

    /** O pedido anterior da mesma pessoa e do mesmo tipo, para repetir a lista no check-up seguinte. */
    public function previousOrder(string $membroId, string $categoria): ?HealthExamRequest
    {
        return HealthExamRequest::query()->where('member_id', $membroId)->where('category', $categoria)
            ->with('items')->orderByDesc('requested_on')->orderByDesc('created_at')->first();
    }

    /**
     * Exames que a pessoa já digitou e que não estão no catálogo: voltam a aparecer na lista. Os nomes são cifrados, então a filtragem é em PHP.
     *
     * @return list<string>
     */
    public function customNames(string $membroId): array
    {
        return HealthExamItem::query()->where('member_id', $membroId)->latest()->limit(300)->get()
            ->pluck('name')
            ->filter(fn (string $nome) => ExamCatalog::examFor($nome) === null)
            ->unique(fn (string $nome) => ExamCatalog::normalize($nome))
            ->sort()->values()->all();
    }

    private function createItem(HealthExamRequest $pedido, array $item, int $posicao, ?ProfileMember $autor): HealthExamItem
    {
        return HealthExamItem::create([
            'member_id' => $pedido->member_id,
            'request_id' => $pedido->id,
            'name' => $item['name'],
            'detail' => $item['detail'],
            'position' => $posicao,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /** @return list<array{name: string, detail: ?string}> sem vazios nem repetidos (o mesmo exame com detalhes diferentes conta duas vezes) */
    private function cleanItems(array $itens): array
    {
        $limpos = [];

        foreach ($itens as $item) {
            $nome = mb_substr(trim((string) ($item['name'] ?? '')), 0, 160);
            $detalhe = $this->blankToNull(mb_substr((string) ($item['detail'] ?? ''), 0, 300));

            if ($nome !== '') {
                $limpos[ExamCatalog::normalize($nome).'|'.ExamCatalog::normalize((string) $detalhe)] ??= ['name' => $nome, 'detail' => $detalhe];
            }
        }

        if (count($limpos) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Um pedido aceita até '.self::MAX_ITEMS.' exames.');
        }

        return array_values($limpos);
    }

    /** @param  list<string>  $nomes */
    private function kindFor(array $nomes): HealthAppointmentKind
    {
        foreach ($nomes as $nome) {
            if (! ExamCatalog::isConsultation($nome)) {
                return HealthAppointmentKind::Exam;
            }
        }

        return HealthAppointmentKind::Consultation;
    }

    /** @param  Collection<int, HealthExamItem>  $itens */
    private function appointmentNotes(HealthExamRequest $pedido, Collection $itens): string
    {
        $linhas = ['Exames: '.$itens->map(fn (HealthExamItem $i) => $i->name.($i->detail !== null ? " ({$i->detail})" : ''))->implode('; ').'.'];

        if ($pedido->notes !== null) {
            $linhas[] = 'Observações do pedido: '.$pedido->notes;
        }

        return implode("\n", $linhas);
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

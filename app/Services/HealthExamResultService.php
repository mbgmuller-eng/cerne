<?php

namespace App\Services;

use App\Models\HealthExamItem;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Models\HealthExamValue;
use App\Models\ProfileMember;
use App\Support\Health\ExamCatalog;
use App\Support\Health\ReferenceRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resultados de exame e a comparação entre eles. O Cerne destaca o valor que ficou fora da referência IMPRESSA NO PRÓPRIO laudo e não
 * interpreta nada; a conclusão é guardada como está no papel.
 *
 * Tudo é cifrado no banco, então a comparação é montada em PHP, sob os escopos de quem olha. São poucos laudos por pessoa.
 */
class HealthExamResultService
{
    public const MAX_VALUES = 150;

    /** Quantas datas a tabela de comparação mostra (as mais recentes). */
    private const MAX_COLUMNS = 8;

    /**
     * @param  array{kind: string, performed_on: string, lab?: ?string, doctor?: ?string, exam_name?: ?string, conclusion?: ?string, highlight?: ?string, values?: list<array{name?: string, value?: string, unit?: ?string, reference?: ?string}>}  $dados
     * @param  list<string>  $itemIds  os exames do pedido que este laudo resolve
     */
    public function save(ProfileMember $membro, ?HealthExamRequest $pedido, array $dados, array $itemIds, ?ProfileMember $autor): HealthExamReport
    {
        $tipo = $dados['kind'] === HealthExamReport::KIND_TEXT ? HealthExamReport::KIND_TEXT : HealthExamReport::KIND_VALUES;
        $valores = $tipo === HealthExamReport::KIND_VALUES ? $this->cleanValues($dados['values'] ?? []) : [];
        $exame = $this->blankToNull($dados['exam_name'] ?? null);

        if ($tipo === HealthExamReport::KIND_VALUES && $valores === []) {
            throw new InvalidArgumentException('Informe pelo menos um resultado, com nome e valor.');
        }

        if ($tipo === HealthExamReport::KIND_TEXT && ($exame === null || $this->blankToNull($dados['conclusion'] ?? null) === null)) {
            throw new InvalidArgumentException('Informe o exame e a conclusão do laudo.');
        }

        if ($pedido !== null && $pedido->member_id !== $membro->id) {
            throw new InvalidArgumentException('Este pedido é de outra pessoa.');
        }

        $data = CarbonImmutable::parse($dados['performed_on'])->startOfDay();

        if ($data->isFuture()) {
            throw new InvalidArgumentException('A data do exame não pode ser no futuro.');
        }

        return DB::transaction(function () use ($membro, $pedido, $dados, $tipo, $valores, $exame, $data, $itemIds, $autor): HealthExamReport {
            $laudo = HealthExamReport::create([
                'member_id' => $membro->id,
                'request_id' => $pedido?->id,
                'kind' => $tipo,
                'performed_on' => $data,
                'lab' => $this->blankToNull($dados['lab'] ?? null),
                'doctor' => $this->blankToNull($dados['doctor'] ?? null),
                'professional_id' => $dados['professional_id'] ?? null,
                'exam_name' => $tipo === HealthExamReport::KIND_TEXT ? $exame : null,
                'conclusion' => $this->blankToNull($dados['conclusion'] ?? null),
                'highlight' => $this->blankToNull($dados['highlight'] ?? null),
                'created_by_member_id' => $autor?->id,
            ]);

            foreach ($valores as $posicao => $valor) {
                HealthExamValue::create($valor + [
                    'member_id' => $membro->id, 'report_id' => $laudo->id, 'position' => $posicao, 'created_by_member_id' => $autor?->id,
                ]);
            }

            if ($itemIds !== []) {
                // Só itens do próprio pedido: o id vem do navegador.
                $itens = $pedido === null ? collect() : $pedido->items()->whereNull('report_id')->get()->whereIn('id', $itemIds);

                if ($itens->count() !== count(array_unique($itemIds))) {
                    throw new InvalidArgumentException('Um dos exames escolhidos não é deste pedido ou já tem resultado.');
                }

                $itens->each(fn (HealthExamItem $i) => $i->update(['report_id' => $laudo->id]));
            }

            return $laudo;
        });
    }

    /** Os valores saem junto e os exames voltam a esperar resultado. O PDF continua nos Documentos. */
    public function delete(HealthExamReport $laudo): void
    {
        $laudo->delete();
    }

    /**
     * A comparação dos resultados de valores de uma pessoa: uma linha por marcador, uma coluna por data.
     *
     * @return array{columns: list<string>, groups: array<string, list<array{key: string, name: string, unit: string, reference: string, cells: array<string, array{value: string, unit: string, reference: string, flag: string, comparable: bool}>}>>}
     */
    public function comparison(string $membroId): array
    {
        $laudos = HealthExamReport::query()->where('member_id', $membroId)->where('kind', HealthExamReport::KIND_VALUES)
            ->with('values')->orderBy('performed_on')->orderBy('created_at')->get();

        $colunas = $laudos->map(fn (HealthExamReport $r) => $r->performed_on->toDateString())->unique()->values()->slice(-self::MAX_COLUMNS)->values();
        $linhas = [];

        foreach ($laudos as $laudo) {
            $data = $laudo->performed_on->toDateString();

            if (! $colunas->contains($data)) {
                continue;
            }

            foreach ($laudo->values as $v) {
                $chave = ExamCatalog::markerKey($v->name);
                $marcador = ExamCatalog::markerFor($v->name);
                $linhas[$chave] ??= ['key' => $chave, 'name' => '', 'exam' => $marcador['exam'] ?? 'Outros resultados', 'unit' => '', 'reference' => '', 'cells' => []];
                // Os laudos vêm do mais antigo para o mais novo: nome, unidade e referência da linha são os mais recentes.
                $linhas[$chave]['name'] = $marcador['name'] ?? $v->name;
                $linhas[$chave]['unit'] = (string) $v->unit;
                $linhas[$chave]['reference'] = (string) ($v->reference ?? '') !== '' ? (string) $v->reference : $linhas[$chave]['reference'];
                $linhas[$chave]['cells'][$data] = [
                    'value' => (string) $v->value, 'unit' => (string) $v->unit, 'reference' => (string) $v->reference,
                    'flag' => ReferenceRange::flag($v->value, $v->reference),
                ];
            }
        }

        $grupos = [];

        foreach (collect($linhas)->sortBy([fn (array $a, array $b) => [ExamCatalog::markerOrder($a['name']), $a['key']] <=> [ExamCatalog::markerOrder($b['name']), $b['key']]]) as $linha) {
            foreach ($linha['cells'] as $data => $celula) {
                $linha['cells'][$data]['comparable'] = $this->sameUnit($celula['unit'], $linha['unit']);
            }

            $grupos[$linha['exam']][] = array_diff_key($linha, ['exam' => true]);
        }

        return ['columns' => $colunas->all(), 'groups' => $grupos];
    }

    /**
     * Os pontos do gráfico de um marcador. Unidade diferente da mais recente e resultado que não é número ficam de fora, e a tela diz quais.
     *
     * @param  array{unit: string, reference: string, cells: array<string, array{value: string, unit: string, comparable: bool, flag: string}>}  $linha
     * @return array{points: list<array{date: string, label: string, text: string, value: float, flag: string}>, excluded: list<array{date: string, text: string, reason: string}>, min: ?float, max: ?float}
     */
    public function trend(array $linha): array
    {
        $pontos = [];
        $fora = [];

        foreach ($linha['cells'] as $data => $celula) {
            $quando = CarbonImmutable::parse($data);
            $numero = ReferenceRange::value($celula['value']);

            if (! $celula['comparable']) {
                $fora[] = ['date' => $quando->format('d/m/Y'), 'text' => trim($celula['value'].' '.$celula['unit']), 'reason' => 'unidade diferente'];
            } elseif ($numero === null) {
                $fora[] = ['date' => $quando->format('d/m/Y'), 'text' => $celula['value'], 'reason' => 'não é um número'];
            } else {
                $pontos[] = ['date' => $data, 'label' => $quando->format('m/Y'), 'text' => $celula['value'], 'value' => $numero, 'flag' => $celula['flag']];
            }
        }

        $faixa = ReferenceRange::parse($linha['reference']);

        return ['points' => $pontos, 'excluded' => $fora, 'min' => $faixa['min'] ?? null, 'max' => $faixa['max'] ?? null];
    }

    /**
     * Os laudos de conclusão, juntos por exame, do mais antigo para o mais novo.
     *
     * @return array<string, array{name: string, reports: Collection<int, HealthExamReport>}>
     */
    public function textHistory(string $membroId): array
    {
        return HealthExamReport::query()->where('member_id', $membroId)->where('kind', HealthExamReport::KIND_TEXT)
            ->with(['document', 'createdByUser'])->orderBy('performed_on')->orderBy('created_at')->get()
            ->groupBy(fn (HealthExamReport $r) => ExamCatalog::normalize(ExamCatalog::examFor((string) $r->exam_name) ?? (string) $r->exam_name))
            ->map(fn (Collection $grupo) => ['name' => ExamCatalog::examFor((string) $grupo->last()->exam_name) ?? (string) $grupo->last()->exam_name, 'reports' => $grupo->values()])
            ->sortKeys()
            ->all();
    }

    /** "mg/dL", "mg / dl" e "MG/DL" são a mesma unidade; "ng/mL" e "nmol/L" não. */
    private function sameUnit(string $a, string $b): bool
    {
        $forma = fn (string $u) => str_replace([' ', 'µ', 'μ', '³'], ['', 'u', 'u', '3'], mb_strtolower($u));

        return $forma($a) === $forma($b);
    }

    /** @return list<array{name: string, value: string, unit: ?string, reference: ?string}> */
    private function cleanValues(array $valores): array
    {
        $limpos = [];

        foreach ($valores as $v) {
            $nome = mb_substr(trim((string) ($v['name'] ?? '')), 0, 160);
            $valor = mb_substr(trim((string) ($v['value'] ?? '')), 0, 120);

            if ($nome === '' || $valor === '') {
                continue;
            }

            $limpos[] = [
                'name' => $nome,
                'value' => $valor,
                'unit' => $this->blankToNull(mb_substr((string) ($v['unit'] ?? ''), 0, 60)),
                'reference' => $this->blankToNull(mb_substr((string) ($v['reference'] ?? ''), 0, 300)),
            ];
        }

        if (count($limpos) > self::MAX_VALUES) {
            throw new InvalidArgumentException('Um laudo aceita até '.self::MAX_VALUES.' resultados.');
        }

        return $limpos;
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

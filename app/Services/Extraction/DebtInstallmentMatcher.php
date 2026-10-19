<?php

namespace App\Services\Extraction;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reconhece, numa saída do extrato bancário, o pagamento de uma parcela de financiamento, empréstimo ou consórcio em aberto.
 *
 * Pistas: o valor perto do da parcela, a data perto do vencimento e o nome do credor ou da dívida na descrição. Só sugere: quem
 * decide é a pessoa, na revisão (regra 5 do CLAUDE.md). Confirmada, a linha vira a despesa da parcela, ligada a ela, e o dinheiro
 * é contado uma vez só.
 *
 * As parcelas consultadas são as das dívidas que o perfil ativo enxerga (a privacidade da dívida vale aqui também).
 */
class DebtInstallmentMatcher
{
    /** Diferença de valor aceita sem pista de texto: juros de mora e arredondamento pequenos. */
    private const TOLERANCE_PERCENT = '0.02';

    /** Com o nome do credor na descrição, aceita uma diferença maior (parcela paga com multa ou desconto). */
    private const TOLERANCE_PERCENT_WITH_TEXT = '0.15';

    /** Pagou antes do vencimento? Poucos dias; depois, até um mês e meio de atraso. */
    private const DAYS_EARLY = 10;

    private const DAYS_LATE = 45;

    /**
     * Parcelas em aberto de dívidas ativas, vencendo perto do período que o extrato cobre.
     *
     * @return Collection<int, DebtInstallment>
     */
    public function candidates(CarbonImmutable $de, CarbonImmutable $ate): Collection
    {
        $dividas = Debt::query()->where('status', DebtStatus::Active->value)->get()->keyBy('id');

        if ($dividas->isEmpty()) {
            return collect();
        }

        return DebtInstallment::query()
            ->whereIn('debt_id', $dividas->keys())
            ->where('status', DebtInstallmentStatus::Open->value)
            ->whereBetween('due_date', [$de->subDays(self::DAYS_EARLY)->toDateString(), $ate->addDays(self::DAYS_LATE)->toDateString()])
            ->orderBy('due_date')
            ->get()
            ->each(fn (DebtInstallment $p) => $p->setRelation('debt', $dividas[$p->debt_id]));
    }

    /**
     * @param  Collection<int, DebtInstallment>  $parcelas  o que candidates() devolveu
     * @return array{sugerida: ?DebtInstallment, opcoes: Collection<int, DebtInstallment>}
     */
    public function match(Collection $parcelas, string $descricao, string|float|int|null $valor, CarbonImmutable $data): array
    {
        $pago = ltrim(Money::parse($valor ?? 0), '-');

        if (bccomp($pago, '0', 2) <= 0) {
            return ['sugerida' => null, 'opcoes' => collect()];
        }

        $texto = $this->normalize($descricao);

        $janela = $parcelas->filter(fn (DebtInstallment $p) => $p->due_date->greaterThanOrEqualTo($data->subDays(self::DAYS_LATE)->startOfDay())
            && $p->due_date->lessThanOrEqualTo($data->addDays(self::DAYS_EARLY)->endOfDay()));

        // Mais perto em valor primeiro; empate, a que venceu mais perto da data.
        $ordenadas = $janela->sortBy(fn (DebtInstallment $p) => [$this->differencePercent($p, $pago), abs($p->due_date->diffInDays($data))])->values();

        $sugerida = $ordenadas->first(function (DebtInstallment $p) use ($pago, $texto): bool {
            $dif = $this->differencePercent($p, $pago);

            return bccomp($dif, self::TOLERANCE_PERCENT, 4) <= 0
                || ($this->mentions($texto, $p->debt) && bccomp($dif, self::TOLERANCE_PERCENT_WITH_TEXT, 4) <= 0);
        });

        return ['sugerida' => $sugerida, 'opcoes' => $ordenadas->take(8)->values()];
    }

    public static function label(DebtInstallment $p): string
    {
        return $p->debt->name.', parcela '.$p->number.' de '.$p->debt->term.' (venc. '.$p->due_date->format('d/m/Y').', '.Money::format($p->amount).')';
    }

    /** |valor pago - parcela| / parcela, como fração com 4 casas. */
    private function differencePercent(DebtInstallment $p, string $pago): string
    {
        $valor = (string) $p->amount;

        if (bccomp($valor, '0', 2) <= 0) {
            return '9';
        }

        $dif = bcsub($pago, $valor, 4);

        return bcdiv(ltrim($dif, '-'), $valor, 4);
    }

    /** O nome do credor ou da dívida (palavras de 4 letras ou mais) aparece na descrição do extrato? */
    private function mentions(string $texto, Debt $d): bool
    {
        foreach ([$d->creditor, $d->name] as $nome) {
            foreach (explode(' ', $this->normalize((string) $nome)) as $palavra) {
                if (strlen($palavra) >= 4 && ! in_array($palavra, ['para', 'banco', 'credito', 'parcela', 'prestacao', 'emprestimo', 'financiamento', 'consorcio', 'carro', 'casa'], true) && str_contains($texto, $palavra)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();
    }
}

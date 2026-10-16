<?php

namespace App\Services\Extraction;

use App\Enums\AdjustmentIndex;
use App\Enums\ConsortiumType;
use App\Enums\DebtStatus;
use App\Enums\ProcessingStatus;
use App\Models\Debt;
use App\Models\DocumentUpload;
use App\Services\DebtService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Do extrato da administradora de consórcio para o cadastro em Dívidas.
 *
 * A leitura por IA só vira consórcio e parcelas depois que a pessoa confere a linha de revisão (regra 5 do CLAUDE.md). O PDF
 * traz CPF, endereço e telefone, então é apagado assim que a leitura é resolvida: o que importa fica nas parcelas.
 */
class ConsortiumImportService
{
    public function __construct(private readonly DebtService $dividas) {}

    /**
     * A linha de revisão do consórcio lido (o extrato é de uma cota só), com o destino sugerido: o consórcio que já existe
     * com o mesmo grupo e cota, ou o único consórcio do mesmo tipo ainda sem grupo (um cadastro manual feito antes do extrato).
     *
     * @return array<string, mixed>|null
     */
    public function rowFor(DocumentUpload $documento): ?array
    {
        $item = $documento->extractedItems()[0] ?? null;

        if ($item === null) {
            return null;
        }

        $tipo = match ($item['tipo_bem'] ?? 'outro') {
            'imovel' => ConsortiumType::Property,
            'veiculo' => ConsortiumType::Vehicle,
            default => ConsortiumType::Services,
        };

        $grupoCota = trim(trim((string) ($item['grupo'] ?? '')).' / '.trim((string) ($item['cota'] ?? '')), ' /');
        $parcelas = collect($item['parcelas'] ?? [])
            ->map(fn (array $p) => [
                'numero' => (int) $p['numero'], 'pagamento' => (string) $p['pagamento'],
                'valor_a_pagar' => Money::parse($p['valor_a_pagar'] ?: $p['valor_pago']), 'valor_pago' => Money::parse($p['valor_pago']),
            ])
            ->sortBy('numero')->values();

        $ultima = (int) ($parcelas->max('numero') ?? 0);
        $numeros = $parcelas->pluck('numero')->all();
        $puladas = $ultima > 0 ? array_values(array_diff(range(1, $ultima), $numeros)) : [];
        $lido = Money::sum($parcelas->pluck('valor_pago'));
        $destino = $this->suggestTarget($grupoCota, (string) ($item['contrato'] ?? ''), $tipo);
        $existente = $destino instanceof Debt ? $destino : null;

        return [
            'import' => true,
            'target' => $existente?->id ?? 'new',
            'name' => $existente?->name ?? '',
            'creditor' => trim((string) ($item['administradora'] ?? '')),
            'consortium_type' => $tipo->value,
            'group' => $grupoCota,
            'contract' => trim((string) ($item['contrato'] ?? '')),
            'credit_amount' => Money::parse($item['valor_credito'] ?? 0),
            'installment_amount' => Money::parse($item['valor_contribuicao_mensal'] ?? 0),
            'term' => (int) ($item['plano_meses'] ?? 0),
            'flex_percent' => (string) ($existente?->flex_percent ?? $this->suggestFlex((int) ($item['plano_meses'] ?? 0), (string) ($item['percentual_fundo_comum_mensal'] ?? ''))),
            // O índice não está no extrato: o que já estava cadastrado é mantido; num cadastro novo, a pessoa escolhe.
            'adjustment_index' => $existente?->adjustment_index?->value ?? '',
            'adjustment_every_months' => (string) ($existente?->adjustment_every_months ?? 12),
            'last_adjustment_on' => (string) ($item['ultimo_reajuste'] ?? ''),
            'anchor_number' => $ultima,
            'anchor_due' => (string) ($item['vencimento_atual'] ?? ''),
            'statement_on' => substr((string) ($item['data_extrato'] ?? ''), 0, 10),
            'statement_balance' => ($item['a_pagar_total'] ?? '') !== '' ? Money::parse($item['a_pagar_total']) : '',
            'statement_remaining' => (string) ((int) ($item['parcelas_a_pagar'] ?? 0)),
            'insurance_percent' => (string) ($item['percentual_seguro'] ?? ''),
            'member_id' => (string) ($existente?->member_id ?? ''),
            'is_private' => (bool) ($existente?->is_private ?? false),
            'goal_choice' => '',
            'goal_name' => '',
            'declared_paid' => ($item['total_pago'] ?? '') !== '' ? Money::parse($item['total_pago']) : '',
            'read_paid' => $lido,
            'installments' => $parcelas->all(),
            'skipped' => $puladas,
            'uncertain' => array_values($item['campos_incertos'] ?? []),
        ];
    }

    /**
     * O "quanto a parcela é menor até a contemplação" não vem no extrato, mas dá para estimar: o fundo comum mensal pago em relação
     * ao de uma parcela cheia (100% do crédito dividido pelo prazo). Só sugere um dos valores de 10 em 10, e só se a conta fechar
     * perto; a pessoa confirma ou corrige.
     */
    public function suggestFlex(int $planoMeses, string $percentualFundoComum): string
    {
        $pago = (float) str_replace(',', '.', $percentualFundoComum);

        if ($planoMeses < 1 || $pago <= 0) {
            return '';
        }

        $reducao = (1 - $pago / (100 / $planoMeses)) * 100;
        $arredondado = (int) round($reducao / 10) * 10;

        if ($arredondado < 10 || $arredondado > 50 || abs($reducao - $arredondado) > 4) {
            return '';
        }

        return (string) $arredondado;
    }

    /** @return Collection<int, Debt> os consórcios ativos que podem receber o extrato */
    public function candidates(): Collection
    {
        return Debt::query()->where('kind', 'consortium')->where('status', DebtStatus::Active)->orderBy('name')->get();
    }

    private function suggestTarget(string $grupoCota, string $contrato, ConsortiumType $tipo): ?Debt
    {
        // Grupo e cota se digitam de vários jeitos ("1020 / 3503-00", "001020/3503-00"): compara sem separador e sem zeros à esquerda.
        $normalizar = fn (?string $v) => implode('-', array_map(fn (string $t) => ltrim($t, '0') ?: '0', preg_split('/[^a-z0-9]+/', strtolower((string) $v), -1, PREG_SPLIT_NO_EMPTY)));
        $candidatos = $this->candidates();

        $igual = $candidatos->first(fn (Debt $d) => $grupoCota !== '' && $normalizar($d->consortium_group) === $normalizar($grupoCota))
            ?? $candidatos->first(fn (Debt $d) => $contrato !== '' && $normalizar($d->consortium_contract) === $normalizar($contrato));

        if ($igual !== null) {
            return $igual;
        }

        $semGrupo = $candidatos->filter(fn (Debt $d) => $d->consortium_type === $tipo && blank($d->consortium_group) && ! $d->isContemplated());

        return $semGrupo->count() === 1 ? $semGrupo->first() : null;
    }

    /**
     * Grava a linha conferida. Devolve o consórcio criado ou atualizado; o PDF é apagado e a leitura fica como resolvida (continua
     * contando no limite diário).
     *
     * @param  array<string, mixed>  $linha
     */
    public function commit(DocumentUpload $documento, array $linha): Debt
    {
        $existente = ($linha['target'] ?? 'new') !== 'new' ? Debt::query()->findOrFail($linha['target']) : null;

        $divida = DB::transaction(function () use ($documento, $linha, $existente): Debt {
            $divida = $this->dividas->importConsortiumStatement([
                'consortium_type' => $linha['consortium_type'],
                'name' => $linha['name'] ?: null,
                'creditor' => $linha['creditor'] ?: null,
                'consortium_group' => $linha['group'] ?: null,
                'consortium_contract' => $linha['contract'] ?: null,
                'credit_amount' => $linha['credit_amount'],
                'installment_amount' => $linha['installment_amount'],
                'term' => $linha['term'],
                'flex_percent' => $linha['flex_percent'] ?: null,
                'adjustment_index' => $linha['adjustment_index'] ?: null,
                'adjustment_every_months' => $linha['adjustment_every_months'] ?: null,
                'last_adjustment_on' => $linha['last_adjustment_on'] ?: null,
                'anchor_number' => $linha['anchor_number'],
                'anchor_due' => $linha['anchor_due'],
                'statement_on' => $linha['statement_on'] ?: null,
                'statement_balance' => $linha['statement_balance'] ?: null,
                'statement_remaining' => $linha['statement_remaining'] ?: null,
                'insurance_percent' => $linha['insurance_percent'] ?: null,
                'member_id' => $linha['member_id'] ?: null,
                'is_private' => ! empty($linha['is_private']),
                'goal_id' => ! in_array($linha['goal_choice'] ?? '', ['', 'new'], true) ? $linha['goal_choice'] : null,
                'goal_name' => ($linha['goal_choice'] ?? '') === 'new' ? $linha['goal_name'] : null,
                'paid' => collect($linha['installments'])->map(fn (array $p) => [
                    'number' => $p['numero'], 'paid_on' => $p['pagamento'], 'due_amount' => $p['valor_a_pagar'], 'paid_amount' => $p['valor_pago'],
                ])->all(),
            ], $existente);

            $documento->update([
                'imported_item_indices' => [0],
                'records_extracted' => 1,
                'processing_status' => ProcessingStatus::Committed,
                'committed_at' => now(),
            ]);

            return $divida;
        });

        // Fora da transação: apagar arquivo não dá para desfazer.
        $documento->deleteFile();

        return $divida;
    }
}

<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Models\ConsultantClient;
use App\Models\FeeAgreement;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\User;
use App\Support\ClientDisplayName;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Recebimentos do profissional (consultor ou corretor): o livro, os totais do mês e os contratos de honorário.
 *
 * Tudo é filtrado por professional_id: a renda de um profissional não aparece nem para o cliente nem para o outro
 * profissional do mesmo cliente. Somas em SQL (regra 3). Os meses seguem a competência (o vencimento do previsto); a
 * data em que o dinheiro caiu fica registrada à parte.
 */
class ProfessionalIncomeService
{
    public function __construct(private readonly FeeForecastService $forecast) {}

    /**
     * Clientes com vínculo ativo que podem entrar num lançamento ou contrato.
     *
     * @return array<string, string> profile_id => nome do cliente
     */
    public function clientOptions(User $professional): array
    {
        $clienteIds = ConsultantClient::query()
            ->where('consultant_id', $professional->id)
            ->where('status', ConsultantClientStatus::Active)
            ->pluck('client_id');

        return FinancialProfile::query()
            ->whereIn('owner_user_id', $clienteIds)
            ->with(['owner', 'members.user'])
            ->get()
            ->mapWithKeys(fn (FinancialProfile $p) => [$p->id => ClientDisplayName::forProfile($p)])
            ->sort()
            ->all();
    }

    /**
     * @return array{previsto: string, recebido: string, a_receber: string, atrasado: string, por_origem: list<array>, por_cliente: list<array>}
     */
    public function monthSummary(User $professional, int $ano, int $mes): array
    {
        $base = fn () => IncomeEntry::query()->ownedBy($professional)->forPeriod($ano, $mes)
            ->where('status', '!=', IncomeStatus::Cancelled);
        $recebido = IncomeStatus::Received->value;
        $previsto = IncomeStatus::Expected->value;
        $hoje = CarbonImmutable::today()->toDateString();

        $totais = $base()->selectRaw(
            'COALESCE(SUM(amount), 0) AS previsto,
             COALESCE(SUM(CASE WHEN status = ? THEN received_amount ELSE 0 END), 0) AS recebido,
             COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS a_receber,
             COALESCE(SUM(CASE WHEN status = ? AND due_on < ? THEN amount ELSE 0 END), 0) AS atrasado',
            [$recebido, $previsto, $previsto, $hoje],
        )->first();

        $porOrigem = $base()->selectRaw($this->groupedSums('source'), [$recebido])->groupBy('source')->orderByDesc('previsto')->get()
            ->map(fn ($l) => [
                // O model já devolve o enum (cast de `source`), mesmo na linha agregada.
                'origem' => $l->source ?? IncomeSource::Other,
                'previsto' => $this->money($l->previsto),
                'recebido' => $this->money($l->recebido),
            ])->all();

        $linhasCliente = $base()->selectRaw($this->groupedSums('profile_id'), [$recebido])->groupBy('profile_id')->orderByDesc('previsto')->get();
        $nomes = $this->profileNames($linhasCliente->pluck('profile_id')->filter()->all());

        $porCliente = $linhasCliente->map(fn ($l) => [
            'cliente' => $l->profile_id !== null ? ($nomes[$l->profile_id] ?? 'Cliente') : 'Sem cliente',
            'previsto' => $this->money($l->previsto),
            'recebido' => $this->money($l->recebido),
        ])->all();

        return [
            'previsto' => $this->money($totais->previsto),
            'recebido' => $this->money($totais->recebido),
            'a_receber' => $this->money($totais->a_receber),
            'atrasado' => $this->money($totais->atrasado),
            'por_origem' => $porOrigem,
            'por_cliente' => $porCliente,
        ];
    }

    /** @return array{previsto: string, recebido: string} */
    public function yearTotals(User $professional, int $ano): array
    {
        $linha = IncomeEntry::query()->ownedBy($professional)->forYear($ano)
            ->where('status', '!=', IncomeStatus::Cancelled)
            ->selectRaw(
                'COALESCE(SUM(amount), 0) AS previsto, COALESCE(SUM(CASE WHEN status = ? THEN received_amount ELSE 0 END), 0) AS recebido',
                [IncomeStatus::Received->value],
            )->first();

        return ['previsto' => $this->money($linha->previsto), 'recebido' => $this->money($linha->recebido)];
    }

    /**
     * Os 12 meses que terminam no informado, para o gráfico de previsto x recebido.
     *
     * @return list<array{rotulo: string, previsto: string, recebido: string}>
     */
    public function evolution(User $professional, int $ano, int $mes, int $meses = 12): array
    {
        $fim = CarbonImmutable::create($ano, $mes, 1);
        $inicio = $fim->subMonthsNoOverflow($meses - 1);

        $linhas = IncomeEntry::query()->ownedBy($professional)
            ->where('status', '!=', IncomeStatus::Cancelled)
            ->whereRaw('(year * 100 + month) BETWEEN ? AND ?', [(int) $inicio->format('Ym'), (int) $fim->format('Ym')])
            ->selectRaw(
                'year, month, COALESCE(SUM(amount), 0) AS previsto, COALESCE(SUM(CASE WHEN status = ? THEN received_amount ELSE 0 END), 0) AS recebido',
                [IncomeStatus::Received->value],
            )->groupBy('year', 'month')->get()
            ->keyBy(fn ($l) => $l->year * 100 + $l->month);

        $serie = [];

        for ($i = 0; $i < $meses; $i++) {
            $ref = $inicio->addMonthsNoOverflow($i);
            $linha = $linhas->get((int) $ref->format('Ym'));
            $serie[] = [
                'rotulo' => mb_strtolower($ref->translatedFormat('M')).'/'.$ref->format('y'),
                'previsto' => $this->money($linha?->previsto ?? 0),
                'recebido' => $this->money($linha?->recebido ?? 0),
            ];
        }

        return $serie;
    }

    /** @return Collection<int, IncomeEntry> */
    public function entriesFor(User $professional, int $ano, int $mes): Collection
    {
        return IncomeEntry::query()->ownedBy($professional)->forPeriod($ano, $mes)
            ->with(['profile.owner', 'profile.members.user'])
            ->orderBy('due_on')->orderBy('created_at')
            ->get();
    }

    /** Lançamento manual (criar ou editar). O contrato gera os seus; esses o profissional não edita, só dá baixa ou cancela. */
    public function saveEntry(User $professional, ?string $id, array $dados): IncomeEntry
    {
        $entry = $id !== null
            ? IncomeEntry::query()->ownedBy($professional)->whereNull('fee_agreement_id')->whereNull('origin_key')->findOrFail($id)
            : new IncomeEntry(['professional_id' => $professional->id]);

        $recebido = ($dados['status'] ?? null) === IncomeStatus::Received->value;
        $valor = Money::parse($dados['amount']);

        $entry->fill([
            'profile_id' => $this->blank($dados['profile_id'] ?? null),
            'source' => $dados['source'],
            'status' => $recebido ? IncomeStatus::Received : IncomeStatus::Expected,
            'description' => $this->blank($dados['description'] ?? null),
            'due_on' => $dados['due_on'],
            'amount' => $valor,
            'received_amount' => $recebido ? Money::parse($this->blank($dados['received_amount'] ?? null) ?? $valor) : null,
            'received_on' => $recebido ? ($this->blank($dados['received_on'] ?? null) ?? $dados['due_on']) : null,
            'notes' => $this->blank($dados['notes'] ?? null),
        ])->save();

        return $entry;
    }

    public function markReceived(User $professional, string $id, string $valor, string $data): IncomeEntry
    {
        $entry = IncomeEntry::query()->ownedBy($professional)->findOrFail($id);
        $entry->fill(['status' => IncomeStatus::Received, 'received_amount' => Money::parse($valor), 'received_on' => $data])->save();

        return $entry;
    }

    /** Volta a "previsto" (recebi errado, ou o dinheiro voltou). */
    public function reopen(User $professional, string $id): IncomeEntry
    {
        $entry = IncomeEntry::query()->ownedBy($professional)->findOrFail($id);
        $entry->fill(['status' => IncomeStatus::Expected, 'received_amount' => null, 'received_on' => null])->save();

        return $entry;
    }

    /** Não vai mais entrar (cliente saiu, estorno). Mantém a linha: o histórico e o índice único do contrato dependem dela. */
    public function cancel(User $professional, string $id): IncomeEntry
    {
        $entry = IncomeEntry::query()->ownedBy($professional)->findOrFail($id);
        $entry->fill(['status' => IncomeStatus::Cancelled, 'received_amount' => null, 'received_on' => null])->save();

        return $entry;
    }

    /** Só lançamento manual some de vez; o gerado por contrato se cancela. */
    public function delete(User $professional, string $id): bool
    {
        return IncomeEntry::query()->ownedBy($professional)->whereNull('fee_agreement_id')->whereNull('origin_key')->whereKey($id)->delete() > 0;
    }

    /** @return Collection<int, FeeAgreement> */
    public function agreements(User $professional): Collection
    {
        return FeeAgreement::query()->where('professional_id', $professional->id)
            ->with(['profile.owner', 'profile.members.user'])
            ->orderByDesc('is_active')->orderBy('created_at')
            ->get();
    }

    public function saveAgreement(User $professional, ?string $id, array $dados): FeeAgreement
    {
        $agreement = $id !== null
            ? FeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id)
            : new FeeAgreement(['professional_id' => $professional->id]);

        $agreement->fill([
            'profile_id' => $dados['profile_id'],
            'amount' => Money::parse($dados['amount']),
            'period' => $dados['period'],
            'starts_on' => $dados['starts_on'],
            'ends_on' => $this->blank($dados['ends_on'] ?? null),
            'notes' => $this->blank($dados['notes'] ?? null),
            'is_active' => $dados['is_active'] ?? true,
        ])->save();

        $this->forecast->resync($agreement);

        return $agreement;
    }

    public function setAgreementActive(User $professional, string $id, bool $ativo): FeeAgreement
    {
        $agreement = FeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id);
        $agreement->update(['is_active' => $ativo]);
        $this->forecast->resync($agreement);

        return $agreement;
    }

    /** Tira o contrato e o que ainda era previsão dele; o que já foi recebido ou cancelado continua no livro. */
    public function deleteAgreement(User $professional, string $id): void
    {
        $agreement = FeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id);

        IncomeEntry::query()->where('fee_agreement_id', $agreement->id)
            ->where('status', IncomeStatus::Expected)
            ->where('due_on', '>=', CarbonImmutable::today()->startOfMonth()->toDateString())
            ->delete();

        $agreement->delete();
    }

    private function groupedSums(string $coluna): string
    {
        return "{$coluna}, COALESCE(SUM(amount), 0) AS previsto, COALESCE(SUM(CASE WHEN status = ? THEN received_amount ELSE 0 END), 0) AS recebido";
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function profileNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return FinancialProfile::query()->whereIn('id', $ids)->with(['owner', 'members.user'])->get()
            ->mapWithKeys(fn (FinancialProfile $p) => [$p->id => ClientDisplayName::forProfile($p)])->all();
    }

    private function money(string|int|float|null $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    private function blank(mixed $valor): mixed
    {
        return $valor === '' ? null : $valor;
    }
}

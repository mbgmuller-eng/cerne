<?php

namespace App\Services\Investments;

use App\Enums\AssetClass;
use App\Enums\MemberRole;
use App\Enums\RecordSource;
use App\Enums\ReturnRateType;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\InvestmentPortfolioMonth;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvestmentTransactionService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Carga dos investimentos de uma corretora a partir de um "pacote" já lido dos documentos dela (notas de corretagem, extrato de
 * preço médio e relatório de performance): ativos, compras, proventos, eventos e a carteira mês a mês.
 *
 * Duas garantias que importam para dado financeiro:
 *  - Simulação de verdade: sem `$aplicar`, tudo roda pelo mesmo caminho da gravação, dentro de uma transação que é desfeita no
 *    fim. O relatório mostra exatamente o que a gravação produziria, inclusive as diferenças em relação ao que a corretora
 *    declara ter (quantidade, preço médio e custo).
 *  - Idempotente: cada movimentação tem uma chave (nº da nota, ou dia + tipo + ordem no extrato) com índice único por ativo.
 *    Rodar de novo não duplica nada, e um ativo que já existe com o mesmo ticker, cadastrado à mão, não é tocado: vira "conflito"
 *    para uma pessoa decidir.
 */
class BrokerPackageImporter
{
    public function __construct(private readonly InvestmentTransactionService $service) {}

    /**
     * @param  array<string, mixed>  $pacote
     * @return list<array<string, mixed>> uma linha por ativo, mais uma da carteira mensal
     */
    public function run(User $usuario, array $pacote, bool $aplicar, ?string $nomeDoMembro = null): array
    {
        $perfil = FinancialProfile::query()->where('owner_user_id', $usuario->id)->first()
            ?? throw new InvalidArgumentException('Esta conta não tem perfil financeiro.');

        $membros = ProfileMember::query()->where('profile_id', $perfil->id)->get();
        $membro = $nomeDoMembro !== null
            ? $membros->first(fn (ProfileMember $m) => str_contains($this->normalize($m->name), $this->normalize($nomeDoMembro)))
            : $membros->firstWhere('role', MemberRole::Primary);

        if ($membro === null) {
            throw new InvalidArgumentException('Membro do perfil não encontrado.');
        }

        $contexto = app(ProfileContext::class);
        $contexto->set($perfil, $membro);

        DB::beginTransaction();

        try {
            $linhas = $this->process($usuario, $perfil, $membro, $pacote);
            $aplicar ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            $contexto->clear();
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $pacote
     * @return list<array<string, mixed>>
     */
    private function process(User $usuario, FinancialProfile $perfil, ProfileMember $membro, array $pacote): array
    {
        $origem = $pacote['origem'] ?? [];
        $conta = (string) ($origem['conta'] ?? '');
        $corretora = (string) ($origem['corretora'] ?? '');
        $contaInvestimento = BankAccount::query()
            ->where('account_type', 'investment_account')
            ->where('bank_name', 'like', '%'.Str::before($corretora, ' ').'%')
            ->first();

        $linhas = [];

        foreach ($pacote['ativos'] ?? [] as $ativo) {
            $linhas[] = $this->asset($usuario, $perfil, $membro, $ativo, $conta, $corretora, $contaInvestimento);
        }

        if (! empty($pacote['carteira_mensal'])) {
            $linhas[] = $this->portfolioMonths($perfil, $membro, $pacote['carteira_mensal'], $corretora);
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $ativo
     * @return array<string, mixed>
     */
    private function asset(User $usuario, FinancialProfile $perfil, ProfileMember $membro, array $ativo, string $conta, string $corretora, ?BankAccount $contaInvestimento): array
    {
        $classe = AssetClass::from($ativo['classe']);
        $chaveExterna = 'btg:'.$conta.':'.$ativo['chave'];
        $linha = ['ativo' => $ativo['chave'], 'classe' => $classe->label(), 'status' => '', 'criadas' => 0, 'puladas' => 0, 'avisos' => [], 'diferencas' => []];

        $registro = InvestmentRecord::withoutProfileScope()->where('profile_id', $perfil->id)->where('external_asset_id', $chaveExterna)->first();

        if ($registro === null && ! empty($ativo['ticker'])) {
            $manual = InvestmentRecord::withoutProfileScope()->where('profile_id', $perfil->id)->where('ticker', $ativo['ticker'])->first();

            if ($manual !== null) {
                $linha['status'] = 'conflito';
                $linha['avisos'][] = 'Já existe um ativo com o ticker '.$ativo['ticker'].' cadastrado à mão. Nada foi feito com ele: decida se a carga deve assumi-lo.';

                return $linha;
            }
        }

        $linha['status'] = $registro === null ? 'criado' : 'existente';

        $registro ??= InvestmentRecord::create([
            'profile_id' => $perfil->id,
            'member_id' => $membro->id,
            'sector' => $classe->sector()->value,
            'asset_class' => $classe->value,
            'ticker' => $ativo['ticker'] ?? null,
            'name' => $ativo['nome'],
            'institution' => $corretora !== '' ? $corretora : null,
            'current_amount' => Money::parse($ativo['cotacao']['valor'] ?? $ativo['valor_atual'] ?? 0),
            'invested_amount' => null,
            'quantity' => null,
            'purchase_date' => $ativo['data_inicial'] ?? null,
            'maturity_date' => $ativo['vencimento'] ?? null,
            'return_rate' => $ativo['taxa'] ?? null,
            'return_rate_type' => ! empty($ativo['taxa']) ? ReturnRateType::PostfixedIpca->value : null,
            'broker_account_id' => $contaInvestimento?->id,
            'source' => RecordSource::PdfImport->value,
            'external_asset_id' => $chaveExterna,
            'is_active' => true,
            'notes' => $ativo['nota'] ?? null,
            'created_by_user_id' => $usuario->id,
        ]);

        if ($classe === AssetClass::Tesouro) {
            return $this->treasuryLots($linha, $registro, $ativo, $perfil, $membro, $usuario);
        }

        foreach ($this->sortedMovements($ativo['movimentos'] ?? []) as $mov) {
            $this->movement($linha, $registro, $mov, $usuario);
        }

        if (! empty($ativo['quantidade']) && empty($ativo['movimentos'])) {
            $this->fundPosition($linha, $registro, $ativo, $usuario);
        }

        $registro->refresh();

        if (! empty($ativo['cotacao']['preco']) && $registro->hasPosition()) {
            $data = CarbonImmutable::parse($ativo['cotacao']['data']);

            if ($registro->price_date === null || $data->startOfDay()->greaterThan($registro->price_date)) {
                $this->service->updateQuote($registro, $this->plain($ativo['cotacao']['preco']), $data);
                $registro->refresh();
            }
        }

        $linha['posicao'] = [
            'quantidade' => $this->plain((string) $registro->quantity),
            'preco_medio' => (string) $registro->average_price,
            'custo' => (string) $registro->invested_amount,
            'valor_atual' => (string) $registro->current_amount,
        ];

        $linha['diferencas'] = $this->differences($registro, $ativo);

        return $linha;
    }

    /**
     * @param  array<string, mixed>  $linha
     * @param  array<string, mixed>  $mov
     */
    private function movement(array &$linha, InvestmentRecord $registro, array $mov, User $usuario): void
    {
        $chave = (string) $mov['chave'];

        if (InvestmentTransaction::withoutProfileScope()->where('investment_id', $registro->id)->where('import_key', $chave)->exists()) {
            $linha['puladas']++;

            return;
        }

        $data = CarbonImmutable::parse($mov['data']);
        $registro->refresh();

        match ($mov['tipo']) {
            'compra' => $this->service->record($registro, [
                'type' => TransactionType::Buy,
                'quantity' => $mov['quantidade'],
                'unit_price' => $mov['preco'],
                'total_amount' => $mov['total'],
                'broker_fee' => $mov['corretagem'] ?? null,
                'other_fees' => $mov['outras_taxas'] ?? null,
                'operation_date' => $data,
                'settlement_date' => ! empty($mov['liquidacao']) ? CarbonImmutable::parse($mov['liquidacao']) : null,
                'import_key' => $chave,
            ], $usuario->id),
            'dividendo' => $this->service->registerIncome($registro, TransactionType::Dividend, $mov['bruto'], $mov['imposto'] ?? '0', $data, null, $usuario->id, $chave),
            'jcp' => $this->service->registerIncome($registro, TransactionType::Jcp, $mov['bruto'], $mov['imposto'] ?? '0', $data, null, $usuario->id, $chave),
            'amortizacao' => $this->service->registerIncome($registro, TransactionType::Amortization, $mov['bruto'], '0', $data, null, $usuario->id, $chave),
            'posicao_inicial' => $this->service->setInitialPosition($registro, $mov['quantidade'], $mov['preco_medio'], $data, $usuario->id, $chave),
            default => throw new InvalidArgumentException('Tipo de movimentação desconhecido: '.$mov['tipo']),
        };

        $linha['criadas']++;
    }

    /**
     * Fundo cadastrado só com a posição de hoje (o relatório não traz o histórico de aplicações): vira posição inicial.
     *
     * @param  array<string, mixed>  $linha
     * @param  array<string, mixed>  $ativo
     */
    private function fundPosition(array &$linha, InvestmentRecord $registro, array $ativo, User $usuario): void
    {
        $chave = 'posicao:'.$ativo['chave'];

        if (InvestmentTransaction::withoutProfileScope()->where('investment_id', $registro->id)->where('import_key', $chave)->exists()) {
            $linha['puladas']++;

            return;
        }

        $custo = Money::parse($ativo['valor_aplicado']);
        $preco = bcdiv($custo, $this->plain($ativo['quantidade']), 6);

        $this->service->setInitialPosition($registro, $this->plain($ativo['quantidade']), $preco, CarbonImmutable::parse($ativo['data']), $usuario->id, $chave);
        $linha['criadas']++;
    }

    /**
     * Tesouro Direto: o relatório lista cada aporte (lote) com a data, os títulos (frações), o valor aplicado e o saldo de hoje.
     * Cada lote vira uma compra, para a história dos aportes e o custo de aquisição do imposto de renda; a posição do ativo é
     * o valor aplicado e o saldo atual, porque Tesouro não negocia em cotas no Cerne.
     *
     * @param  array<string, mixed>  $linha
     * @param  array<string, mixed>  $ativo
     * @return array<string, mixed>
     */
    private function treasuryLots(array $linha, InvestmentRecord $registro, array $ativo, FinancialProfile $perfil, ProfileMember $membro, User $usuario): array
    {
        $aplicadoTotal = '0';

        foreach ($ativo['lotes'] ?? [] as $lote) {
            $aplicado = Money::parse($lote['aplicado']);
            $aplicadoTotal = bcadd($aplicadoTotal, $aplicado, 2);

            if (InvestmentTransaction::withoutProfileScope()->where('investment_id', $registro->id)->where('import_key', $lote['chave'])->exists()) {
                $linha['puladas']++;

                continue;
            }

            InvestmentTransaction::create([
                'profile_id' => $perfil->id,
                'member_id' => $membro->id,
                'investment_id' => $registro->id,
                'transaction_type' => TransactionType::Buy,
                'quantity' => $lote['quantidade'],
                'unit_price' => bcdiv($aplicado, $lote['quantidade'], 6),
                'total_amount' => $aplicado,
                'net_amount' => $aplicado,
                'operation_date' => $lote['data'],
                'import_key' => $lote['chave'],
                'created_by_user_id' => $usuario->id,
            ]);

            $linha['criadas']++;
        }

        $registro->update([
            'invested_amount' => $aplicadoTotal,
            'current_amount' => Money::parse($ativo['valor_atual']),
        ]);

        $linha['posicao'] = ['quantidade' => '—', 'preco_medio' => '—', 'custo' => $aplicadoTotal, 'valor_atual' => (string) $registro->current_amount];

        if (isset($ativo['esperado']['valor_aplicado']) && bccomp(Money::parse($ativo['esperado']['valor_aplicado']), $aplicadoTotal, 2) !== 0) {
            $linha['diferencas'][] = 'valor aplicado: relatório '.Money::format($ativo['esperado']['valor_aplicado']).', soma dos lotes '.Money::format($aplicadoTotal);
        }

        return $linha;
    }

    /** @param  array<string, mixed>  $ativo @return list<string> */
    private function differences(InvestmentRecord $registro, array $ativo): array
    {
        $esperado = $ativo['esperado'] ?? null;
        $diferencas = [];

        if ($esperado === null) {
            return $diferencas;
        }

        if (isset($esperado['quantidade']) && bccomp($this->plain((string) $registro->quantity), $this->plain($esperado['quantidade']), 2) !== 0) {
            $diferencas[] = 'cotas: relatório '.$esperado['quantidade'].', Cerne '.$this->plain((string) $registro->quantity);
        }

        if (isset($esperado['preco_medio']) && $this->far((string) $registro->average_price, $esperado['preco_medio'], '0.011')) {
            $diferencas[] = 'preço médio: relatório '.Money::format($esperado['preco_medio']).', Cerne '.Money::format((string) $registro->average_price);
        }

        if (isset($esperado['valor_aplicado']) && $this->far((string) $registro->invested_amount, $esperado['valor_aplicado'], '0.06')) {
            $diferencas[] = 'valor aplicado: relatório '.Money::format($esperado['valor_aplicado']).', Cerne '.Money::format((string) $registro->invested_amount);
        }

        if (isset($ativo['cotacao']['valor']) && $this->far((string) $registro->current_amount, $ativo['cotacao']['valor'], '0.06')) {
            $diferencas[] = 'valor atual: relatório '.Money::format($ativo['cotacao']['valor']).', Cerne '.Money::format((string) $registro->current_amount);
        }

        return $diferencas;
    }

    /**
     * @param  list<array<string, mixed>>  $meses
     * @return array<string, mixed>
     */
    private function portfolioMonths(FinancialProfile $perfil, ProfileMember $membro, array $meses, string $corretora): array
    {
        $criados = 0;
        $atualizados = 0;

        foreach ($meses as $m) {
            $registro = InvestmentPortfolioMonth::withoutProfileScope()->updateOrCreate(
                ['profile_id' => $perfil->id, 'member_id' => $membro->id, 'institution' => $corretora, 'year' => $m['ano'], 'month' => $m['mes']],
                [
                    'opening_balance' => $m['abertura'] ?? null,
                    'movements' => $m['movimentacoes'] ?? null,
                    'taxes' => $m['impostos'] ?? null,
                    'closing_balance' => $m['fechamento'] ?? null,
                    'gain' => $m['ganho'] ?? null,
                    'return_percent' => $m['rentabilidade'] ?? null,
                    'cdi_percent' => $m['cdi'] ?? null,
                ],
            );

            $registro->wasRecentlyCreated ? $criados++ : $atualizados++;
        }

        return ['ativo' => 'Carteira mês a mês', 'classe' => 'Gráficos', 'status' => 'meses', 'criadas' => $criados, 'puladas' => $atualizados, 'avisos' => [], 'diferencas' => []];
    }

    /** Movimentos na ordem das datas; no mesmo dia, a ordem em que vieram no pacote. */
    private function sortedMovements(array $movimentos): array
    {
        return collect($movimentos)->values()->sortBy(fn (array $m, int $i) => $m['data'].'|'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->values()->all();
    }

    private function far(string $a, string $b, string $tolerancia): bool
    {
        return bccomp(ltrim(bcsub(bcadd($a, '0', 6), bcadd($b, '0', 6), 6), '-'), $tolerancia, 6) > 0;
    }

    private function plain(string $valor): string
    {
        $texto = rtrim(rtrim(bcadd($valor, '0', 6), '0'), '.');

        return $texto === '' ? '0' : $texto;
    }

    private function normalize(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }
}

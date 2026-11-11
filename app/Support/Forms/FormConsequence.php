<?php

namespace App\Support\Forms;

use App\Enums\DebtAmortization;
use App\Enums\DebtRateBasis;
use App\Models\CreditCard;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * O texto de "o que vai acontecer ao salvar" dos formulários de cadastro. São contas puras sobre o que a pessoa já preencheu (nada é gravado nem consultado
 * aqui), no mesmo cálculo que o Cerne usa para gravar de verdade: a fatura vem de CreditCard::billingPeriodFor e as parcelas de Money::split. Se a regra
 * de fatura ou de parcela mudar lá, o aviso muda junto, porque ele chama a mesma conta.
 */
final class FormConsequence
{
    /** "dezembro de 2026". */
    public static function monthYear(int $ano, int $mes): string
    {
        return CarbonImmutable::create($ano, $mes, 1)->translatedFormat('F \d\e Y');
    }

    /**
     * Compra no cartão: em que fatura cai, quando vence e que nenhuma conta é debitada agora (a conta só é debitada ao pagar a fatura).
     *
     * @return list<string>
     */
    public static function cardPurchase(CreditCard $cartao, CarbonImmutable $data, string $valor, int $parcelas, bool $estorno = false): array
    {
        [$ano, $mes] = $cartao->billingPeriodFor($data);

        if ($estorno) {
            return ['Estorno: o valor abate a fatura de '.self::monthYear($ano, $mes).'.'];
        }

        if ($parcelas <= 1) {
            return [
                'Entra na fatura de '.self::monthYear($ano, $mes).', com vencimento em '.$cartao->dueDateFor($ano, $mes)->format('d/m/Y').'. Nenhuma conta é debitada agora.',
            ];
        }

        $valores = Money::split(Money::parse($valor), $parcelas);
        [$anoFim, $mesFim] = $cartao->billingPeriodFor($data->addMonthsNoOverflow($parcelas - 1));

        $linhas = [
            $parcelas.' parcelas de '.Money::format($valores[0]).', da fatura de '.self::monthYear($ano, $mes).' até a de '.self::monthYear($anoFim, $mesFim).'. Nenhuma conta é debitada agora.',
        ];

        // A sobra de centavos vai para a última parcela (ver Money::split).
        if ($valores[$parcelas - 1] !== $valores[0]) {
            $linhas[] = 'A última parcela ajusta os centavos: '.Money::format($valores[$parcelas - 1]).'.';
        }

        return $linhas;
    }

    /** O saldo da conta antes e depois, para lançamento que mexe nele (despesa tira, receita e estorno põem). */
    public static function accountBalance(string $conta, string $saldoAtual, string $valor, bool $debito): string
    {
        $atual = Money::parse($saldoAtual);
        $novo = $debito ? bcsub($atual, Money::parse($valor), 2) : bcadd($atual, Money::parse($valor), 2);

        return 'O saldo de '.$conta.' passa de '.Money::format($atual).' para '.Money::format($novo).' ao salvar, mesmo com data passada.';
    }

    /**
     * Conta fixa ou receita recorrente nova: o vencimento (ou recebimento) do mês corrente é criado na hora, mesmo que a data já tenha passado.
     *
     * @param  list<CarbonImmutable>  $datas  as ocorrências do mês corrente (FixedBill::occurrencesInMonth)
     */
    public static function recurringCreation(array $datas, string $substantivo = 'vencimento'): ?string
    {
        if ($datas === []) {
            return null;
        }

        if (count($datas) === 1) {
            return 'O '.$substantivo.' deste mês ('.$datas[0]->format('d/m').') será criado agora, mesmo que a data já tenha passado.';
        }

        return 'Serão criados agora os '.count($datas).' '.$substantivo.'s deste mês (de '.$datas[0]->format('d/m').' a '.end($datas)->format('d/m').'), mesmo os que já passaram.';
    }

    /**
     * O que "Pagar" faz numa conta fixa: só lança a despesa se a conta tiver conta bancária (e categoria). Sem conta, apenas marca como paga.
     * O valor de uma conta de valor variável só é conhecido na hora de pagar.
     */
    public static function payBill(?string $conta, string $valor, bool $variavel, string $categoria): string
    {
        if ($conta === null) {
            return 'Sem conta, “Pagar” só marca como paga: nenhuma despesa é criada.';
        }

        $quanto = $variavel ? 'do valor que você informar ao pagar' : self::valorDaConta($valor);

        return 'Ao pagar, será criada uma despesa '.$quanto.' em '.$categoria.', com a data do pagamento, e '.$conta.' será debitada.';
    }

    /** O que "Receber" faz numa receita recorrente (espelho de payBill). */
    public static function receiveIncome(?string $conta, string $valor, bool $variavel, string $categoria): string
    {
        if ($conta === null) {
            return 'Sem conta, “Receber” só marca como recebida: nenhuma receita é criada.';
        }

        $quanto = $variavel ? 'do valor que você informar ao receber' : self::valorDaConta($valor);

        return 'Ao receber, será criada uma receita '.$quanto.' em '.$categoria.', com a data do recebimento, e '.$conta.' será creditada.';
    }

    /** "de R$ 300,00", ou "do valor da conta" enquanto a pessoa ainda não digitou o valor. */
    private static function valorDaConta(string $valor): string
    {
        $parseado = Money::parse($valor);

        return bccomp($parseado, '0', 2) > 0 ? 'de '.Money::format($parseado) : 'do valor da conta';
    }

    /** Editar o saldo de uma conta SUBSTITUI o valor: não cria lançamento nenhum. */
    public static function balanceReplaced(string $anterior, string $novo): string
    {
        return 'Ao salvar, o saldo passa de '.Money::format(Money::parse($anterior)).' para '.Money::format(Money::parse($novo)).', sem criar lançamento.';
    }

    /**
     * O ciclo da fatura de um cartão, na mesma regra de CreditCard::billingPeriodFor e dueDateFor: compras até o fechamento entram na fatura do mês,
     * e o vencimento fica no próprio mês quando o dia dele é maior que o do fechamento (senão, no mês seguinte).
     */
    public static function cardCycle(CreditCard $cartao): string
    {
        $mes = $cartao->due_day > $cartao->closing_day ? 'do próprio mês' : 'do mês seguinte';

        return 'Compras até o dia '.$cartao->closing_day.' entram na fatura do mês; as seguintes, na do mês seguinte. A fatura vence no dia '.$cartao->due_day.' '.$mes.'.';
    }

    /**
     * Financiamento ou empréstimo: quantas parcelas nascem, de quando a quando, quanto valem (na mesma conta do cronograma que grava, `DebtScheduleCalculator`)
     * e o que acontece com as que a pessoa diz já ter pago. Valor ainda não digitado ou taxa inválida: o aviso fica sem o valor, nunca com um chute.
     *
     * @return list<string>
     */
    public static function debtSchedule(
        DebtAmortization $sistema,
        int $prazo,
        CarbonImmutable $primeira,
        int $jaPagas,
        ?string $parcela,
        ?string $principal,
        ?string $taxa,
        ?DebtRateBasis $base,
        string $taxas = '0',
    ): array {
        if ($prazo < 1 || $prazo > 480) {
            return [];
        }

        $ultima = $primeira->addMonthsNoOverflow($prazo - 1);
        $periodo = $prazo === 1 ? 'em '.$primeira->format('d/m/Y') : 'de '.$primeira->format('d/m/Y').' a '.$ultima->format('d/m/Y');
        $linhas = [];

        $valorFixo = $parcela !== null ? Money::parse($parcela) : '0.00';
        $emprestado = $principal !== null ? Money::parse($principal) : '0.00';

        if ($sistema === DebtAmortization::Fixed) {
            $linhas[] = bccomp($valorFixo, '0', 2) > 0
                ? 'Serão criadas '.$prazo.' parcelas de '.Money::format($valorFixo).', '.$periodo.'.'
                : 'Serão criadas '.$prazo.' parcelas, '.$periodo.'.';
        } elseif (bccomp($emprestado, '0', 2) > 0 && $taxa !== null && is_numeric($taxa) && $base !== null) {
            $cronograma = DebtScheduleCalculator::schedule($sistema, $emprestado, DebtScheduleCalculator::monthlyRate($taxa, $base), $prazo, Money::parse($taxas));
            $primeiraParcela = $cronograma[0]['amount'];
            $ultimaParcela = $cronograma[$prazo - 1]['amount'];

            $linhas[] = $primeiraParcela === $ultimaParcela
                ? 'Serão criadas '.$prazo.' parcelas de cerca de '.Money::format($primeiraParcela).', '.$periodo.'.'
                : 'Serão criadas '.$prazo.' parcelas, '.$periodo.', de '.Money::format($primeiraParcela).' (a 1ª) a '.Money::format($ultimaParcela).' (a última).';
            $linhas[] = 'É uma estimativa pelo contrato: o banco pode cobrar um valor um pouco diferente. Você ajusta cada parcela depois.';
        } else {
            $linhas[] = 'Serão criadas '.$prazo.' parcelas, '.$periodo.'.';
        }

        return [...$linhas, ...self::alreadyPaid($prazo, $primeira, $jaPagas)];
    }

    /**
     * Consórcio: as parcelas que nascem, o que as já pagas e as puladas fazem, o destino de cada parcela paga (investimento, não dívida)
     * e quando o Cerne avisa do primeiro reajuste (a mesma data que fica em `next_adjustment_on`).
     *
     * @return list<string>
     */
    public static function consortium(int $prazo, CarbonImmutable $primeira, int $jaPagas, int $puladas, ?string $parcela, ?int $primeiroReajuste): array
    {
        if ($prazo < 1 || $prazo > 480) {
            return [];
        }

        $valor = $parcela !== null ? Money::parse($parcela) : '0.00';
        $ultima = $primeira->addMonthsNoOverflow($prazo - 1);
        $periodo = $prazo === 1 ? 'em '.$primeira->format('d/m/Y') : 'de '.$primeira->format('d/m/Y').' a '.$ultima->format('d/m/Y');

        $linhas = [bccomp($valor, '0', 2) > 0
            ? 'Serão criadas '.$prazo.' parcelas de '.Money::format($valor).', '.$periodo.'.'
            : 'Serão criadas '.$prazo.' parcelas, '.$periodo.'.'];

        $vencidas = $jaPagas + $puladas;

        if ($puladas > 0 && $vencidas <= $prazo) {
            $linhas[] = ($puladas === 1 ? 'A parcela pulada fica' : 'As '.$puladas.' parcelas puladas ficam').' fora do que está em aberto e do que foi pago.';
        }

        if ($jaPagas > 0 && $vencidas <= $prazo) {
            $linhas[] = ($jaPagas === 1 ? 'A parcela já paga entra' : 'As '.$jaPagas.' parcelas já pagas entram').($jaPagas === 1 ? ' como paga' : ' como pagas').', sem criar despesa nem mexer no saldo de conta.';
        }

        if ($vencidas < $prazo) {
            $proxima = $vencidas + 1;
            $linhas[] = 'A 1ª parcela em aberto é a '.$proxima.', com vencimento em '.$primeira->addMonthsNoOverflow($vencidas)->format('d/m/Y').'.';
        }

        $linhas[] = 'Antes da contemplação, cada parcela que você pagar vira despesa de investimento (Consórcio): não conta como dívida nem como patrimônio.';

        if ($primeiroReajuste !== null && $primeiroReajuste >= 1) {
            $linhas[] = 'O Cerne avisa do primeiro reajuste da carta em '.$primeira->addMonthsNoOverflow($primeiroReajuste)->format('d/m/Y').'.';
        }

        return $linhas;
    }

    /**
     * Dívida em aberto: entra no total devedor pelo saldo informado e não gera parcela nem despesa até o acordo.
     *
     * @return list<string>
     */
    public static function openDebt(?string $saldo, CarbonImmutable $data): array
    {
        $valor = $saldo !== null ? Money::parse($saldo) : '0.00';

        $linhas = [bccomp($valor, '0', 2) > 0
            ? 'Entra no total devedor com saldo de '.Money::format($valor).', informado em '.$data->format('d/m/Y').'.'
            : 'Entra no total devedor pelo saldo informado.'];

        $linhas[] = 'Não gera parcelas nem despesas até você registrar um acordo.';

        return $linhas;
    }

    /**
     * Pagar uma parcela de dívida: a despesa que nasce (valor, data, categoria) e o que acontece com a conta. `$conta` é a conta que de fato será usada
     * (a escolhida, ou a do contrato quando nenhuma é escolhida), no mesmo critério de `DebtService::pay`.
     *
     * @return list<string>
     */
    public static function installmentPayment(string $valor, CarbonImmutable $data, ?string $conta, string $categoria): array
    {
        $pago = Money::parse($valor);

        if (bccomp($pago, '0', 2) <= 0) {
            return [];
        }

        return [
            'Será criada uma despesa de '.Money::format($pago).' em '.$data->format('d/m/Y').', na categoria '.$categoria.'.',
            $conta !== null
                ? 'O saldo de '.$conta.' cai '.Money::format($pago).'.'
                : 'Nenhuma conta é debitada.',
        ];
    }

    /** Parcelas que a pessoa diz já ter pago ao cadastrar: nascem pagas, sem despesa e sem mexer em conta. @return list<string> */
    private static function alreadyPaid(int $prazo, CarbonImmutable $primeira, int $jaPagas): array
    {
        if ($jaPagas < 1 || $jaPagas > $prazo) {
            return [];
        }

        if ($jaPagas === $prazo) {
            return ['As '.$prazo.' parcelas entram como pagas, sem criar despesa nem mexer no saldo de conta.'];
        }

        return [
            ($jaPagas === 1 ? 'A 1ª parcela entra como paga' : 'As '.$jaPagas.' primeiras parcelas entram como pagas').', sem criar despesa nem mexer no saldo de conta.',
            'A 1ª em aberto é a '.($jaPagas + 1).', com vencimento em '.$primeira->addMonthsNoOverflow($jaPagas)->format('d/m/Y').'.',
        ];
    }

    /** Lançamento de outro mês que o exibido na tela: não vai aparecer onde a pessoa está olhando. */
    public static function otherMonth(CarbonImmutable $data, int $anoExibido, int $mesExibido): ?string
    {
        if ($data->year === $anoExibido && $data->month === $mesExibido) {
            return null;
        }

        return 'Este lançamento aparecerá em '.self::monthYear($data->year, $data->month).', e não em '.self::monthYear($anoExibido, $mesExibido).'.';
    }
}

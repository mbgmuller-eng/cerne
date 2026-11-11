<?php

namespace App\Support\Forms;

use App\Models\CreditCard;
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

    /** Lançamento de outro mês que o exibido na tela: não vai aparecer onde a pessoa está olhando. */
    public static function otherMonth(CarbonImmutable $data, int $anoExibido, int $mesExibido): ?string
    {
        if ($data->year === $anoExibido && $data->month === $mesExibido) {
            return null;
        }

        return 'Este lançamento aparecerá em '.self::monthYear($data->year, $data->month).', e não em '.self::monthYear($anoExibido, $mesExibido).'.';
    }
}

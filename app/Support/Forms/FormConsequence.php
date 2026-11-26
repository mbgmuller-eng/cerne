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

    /**
     * Conta fixa NO CARTÃO: no vencimento o Cerne lança a compra sozinho na fatura (ver FixedBillService::postDueCardCharges). O período da fatura sai
     * de CreditCard::billingPeriodFor e o vencimento dela de dueDateFor, a MESMA conta que grava.
     *
     * @return list<string>
     */
    public static function payBillOnCard(?CreditCard $cartao, string $valor, bool $variavel, string $categoria, ?CarbonImmutable $proximoVencimento): array
    {
        if ($cartao === null) {
            return ['Escolha o cartão: a conta fixa será lançada na fatura dele a cada vencimento.'];
        }

        $linhas = [];
        // Antes de escolher a categoria a tela passa "a categoria escolhida": "em a categoria" não existe em português.
        $em = $categoria === 'a categoria escolhida' ? 'na categoria escolhida' : 'em '.$categoria;

        if ($variavel) {
            $linhas[] = 'Valor variável: ao pagar, informe o valor. Ele entra na fatura do cartão '.$cartao->displayName().' '.$em.'. Nenhuma conta bancária é debitada.';
        } else {
            $linhas[] = 'No dia de cada vencimento o Cerne lança sozinho uma compra '.self::valorDaConta($valor).' '.$em.' na fatura do cartão '.$cartao->displayName().' e marca a conta como paga. Nenhuma conta bancária é debitada agora: o dinheiro sai quando você pagar a fatura.';
        }

        if ($proximoVencimento !== null) {
            [$ano, $mes] = $cartao->billingPeriodFor($proximoVencimento);
            $linhas[] = 'O vencimento de '.$proximoVencimento->format('d/m').' cai na fatura de '.str_pad((string) $mes, 2, '0', STR_PAD_LEFT).'/'.$ano.', que vence em '.$cartao->dueDateFor($ano, $mes)->format('d/m/Y').'.';

            if (! $variavel && $proximoVencimento->lessThanOrEqualTo(CarbonImmutable::now())) {
                $linhas[] = 'O vencimento deste mês já passou: a compra será lançada ao salvar.';
            }
        }

        return $linhas;
    }

    /**
     * O que "Registrar acordo" faz: as parcelas que nascem (a entrada, quando há, é a 1ª) e o desconto sobre o saldo de hoje. O cronograma sai de
     * DebtService::agreementSchedule, a MESMA conta que grava.
     *
     * @return list<string>
     */
    public static function agreement(string $saldo, string $total, int $parcelas, string $entrada, ?CarbonImmutable $primeiroVencimento): array
    {
        $total = Money::parse($total);
        $entrada = Money::parse($entrada);

        if ($primeiroVencimento === null || bccomp($total, '0', 2) <= 0 || $parcelas < 1 || $parcelas > 480 || bccomp($entrada, '0', 2) < 0 || bccomp($entrada, $total, 2) >= 0) {
            return [];
        }

        $cronograma = \App\Services\DebtService::agreementSchedule($total, $parcelas, $entrada, $primeiroVencimento);
        $regulares = array_values(array_filter($cronograma, fn (array $l) => ! $l['is_down']));
        $ultima = $regulares[count($regulares) - 1]['due']->format('d/m/Y');

        if (bccomp($entrada, '0', 2) > 0) {
            $primeiraRegular = $regulares[0];
            $linhas = ['Serão criadas '.count($cronograma).' parcelas: a entrada de '.Money::format($entrada).' em '.$primeiroVencimento->format('d/m/Y').' e '.$parcelas.' '.($parcelas === 1 ? 'parcela' : 'parcelas').' de '.Money::format($primeiraRegular['amount']).', de '.$primeiraRegular['due']->format('d/m/Y').' a '.$ultima.'.'];
        } else {
            $linhas = ['Serão criadas '.$parcelas.' '.($parcelas === 1 ? 'parcela' : 'parcelas').' de '.Money::format($cronograma[0]['amount']).', de '.$primeiroVencimento->format('d/m/Y').' a '.$ultima.', e a dívida passa a ter parcelas no mês.'];
        }

        $desconto = bcsub(Money::parse($saldo), $total, 2);
        $linhas[] = match (bccomp($desconto, '0', 2)) {
            1 => 'Desconto de '.Money::format($desconto).' sobre o saldo de hoje.',
            0 => 'Sem desconto: você paga o saldo de hoje.',
            default => 'Você paga '.Money::format(bcsub('0', $desconto, 2)).' a mais que o saldo de hoje.',
        };

        return $linhas;
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
     * Editar um lançamento que mexe no saldo: o Cerne DESFAZ o efeito antigo na conta de antes e aplica o novo na conta de agora (mesma ordem de
     * `CashFlowIndex::updateExpense/updateIncome`). `$efeitoAntes` e `$efeitoDepois` são o que o lançamento faz no saldo (despesa tira, receita e
     * estorno põem), com sinal. Cada conta vem como `['id' =>, 'nome' =>, 'saldo' =>]`, ou nulo quando não há conta.
     *
     * @param  array{id: string, nome: string, saldo: string}|null  $antes
     * @param  array{id: string, nome: string, saldo: string}|null  $depois
     * @return list<string>
     */
    public static function editedBalance(?array $antes, string $efeitoAntes, ?array $depois, string $efeitoDepois): array
    {
        $efeitoAntes = Money::parse($efeitoAntes);
        $efeitoDepois = Money::parse($efeitoDepois);

        if ($antes !== null && $depois !== null && $antes['id'] === $depois['id']) {
            $atual = Money::parse($antes['saldo']);

            return ['O saldo de '.$antes['nome'].' passa de '.Money::format($atual).' para '.Money::format(bcadd(bcsub($atual, $efeitoAntes, 2), $efeitoDepois, 2)).' ao salvar.'];
        }

        $linhas = [];

        if ($antes !== null) {
            $atual = Money::parse($antes['saldo']);
            $linhas[] = 'O lançamento deixa '.$antes['nome'].': o saldo passa de '.Money::format($atual).' para '.Money::format(bcsub($atual, $efeitoAntes, 2)).'.';
        }

        if ($depois !== null) {
            $atual = Money::parse($depois['saldo']);
            $linhas[] = 'E passa para '.$depois['nome'].': o saldo vai de '.Money::format($atual).' para '.Money::format(bcadd($atual, $efeitoDepois, 2)).'.';
        } elseif ($antes !== null) {
            $linhas[] = 'Sem conta, o lançamento deixa de mexer em saldo.';
        }

        return $linhas;
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
     * Rotina preventiva: quando fica a próxima data (a mesma conta de HealthRoutineService: última vez mais o intervalo) e o que o Cerne passa a fazer.
     *
     * @return list<string>
     */
    public static function healthRoutine(int $meses, ?CarbonImmutable $ultimaVez): array
    {
        if ($meses < 1) {
            return [];
        }

        $linhas = $ultimaVez !== null
            ? ['A próxima fica para '.$ultimaVez->addMonthsNoOverflow($meses)->format('d/m/Y').'. Avisamos 60 dias antes e no dia, se não houver compromisso marcado.']
            : ['Sem a última vez, a rotina fica "Sem registro" até você ligar uma consulta realizada. Aí a próxima data é calculada.'];

        $linhas[] = 'O histórico passa a mostrar se foi feito em cada ciclo.';

        return $linhas;
    }

    /** @return list<string> */
    public static function healthFollowup(bool $comConsulta): array
    {
        return array_values(array_filter([
            $comConsulta ? 'A consulta escolhida entra na linha do tempo, sem ser copiada.' : null,
            'Quem vê a Agenda de Saúde desta pessoa vê o acompanhamento. O consultor não vê.',
        ]));
    }

    /** @return list<string> */
    public static function examOrder(int $quantos): array
    {
        if ($quantos === 0) {
            return [];
        }

        return [
            ($quantos === 1 ? 'O exame fica' : "Os {$quantos} exames ficam").' em "falta agendar". Nada vai para a Agenda de Saúde até você marcar a data.',
            'Quem vê a Agenda de Saúde desta pessoa vê o pedido. O consultor não vê.',
        ];
    }

    /**
     * @param  list<string>  $exames
     * @return list<string>
     */
    public static function examSchedule(array $exames): array
    {
        if ($exames === []) {
            return [];
        }

        return [
            'Entra um compromisso na Agenda de Saúde com '.(count($exames) === 1 ? 'este exame' : 'estes '.count($exames).' exames').': '.implode('; ', $exames).'.',
            'O lembrete sai um dia antes, como nos outros compromissos.',
        ];
    }

    /** @return list<string> */
    public static function examReading(): array
    {
        return [
            'O PDF é enviado à Anthropic, que faz a leitura. O laudo pode trazer o seu nome e documentos.',
            'Nada vira resultado sem a sua revisão. Ao salvar a revisão, o PDF vai para os Documentos; se descartar, ele é apagado.',
        ];
    }

    /** @return list<string> */
    public static function examResult(string $tipo, int $valores, int $exames, bool $comArquivo, bool $comPedido): array
    {
        $linhas = [];

        if ($tipo === 'report') {
            $linhas[] = 'A conclusão entra na comparação dos laudos deste exame.';
        } elseif ($valores > 0) {
            $linhas[] = ($valores === 1 ? '1 resultado entra' : "{$valores} resultados entram").' na comparação, na data da coleta.';
        }

        if ($comPedido) {
            $linhas[] = $exames > 0
                ? ($exames === 1 ? '1 exame do pedido fica' : "{$exames} exames do pedido ficam").' com resultado.'
                : 'Nenhum exame do pedido está marcado: todos continuam aguardando resultado.';
        }

        if ($comArquivo) {
            $linhas[] = 'O PDF vai para os Documentos, como exame de saúde desta pessoa. Quem pode vê-lo segue a regra dos Documentos.';
        }

        return $linhas;
    }

    /** @return list<string> */
    public static function healthSession(bool $presente): array
    {
        return $presente
            ? ['A presença entra na contagem da semana, e a nota de dor entra no gráfico do programa.']
            : ['A falta fica registrada, sem nota de dor, e não conta como sessão feita.'];
    }

    /**
     * A conta fixa que a dívida ganha em Contas fixas (ver DebtFixedBillSync): vinculada à que já existe ou criada, e até quando. Sem parcela em
     * aberto (tudo já pago) não há o que listar.
     *
     * @return list<string>
     */
    public static function debtFixedBill(?string $nomeExistente, ?CarbonImmutable $primeiraEmAberto, ?CarbonImmutable $ultima): array
    {
        if ($primeiraEmAberto === null || $ultima === null || $ultima->lt($primeiraEmAberto)) {
            return [];
        }

        if ($nomeExistente !== null) {
            return ['Contas fixas: a conta "'.$nomeExistente.'", que já existe, será vinculada a esta dívida. O valor e o vencimento passam a seguir as parcelas, e pagar por lá paga a parcela.'];
        }

        return ['Contas fixas: esta dívida aparece lá sozinha, vencendo todo dia '.$primeiraEmAberto->day.', até a última parcela ('.$ultima->format('m/Y').'). Pagar por lá paga a parcela, e a conta sai da lista quando a dívida acabar.'];
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

    /**
     * Revisão de extrato ou fatura: o que "Importar selecionados" vai criar, por destino. `$contagens` conta as linhas prontas pelo caminho que o
     * `DocumentCommitService` seguirá (mesmas escolhas da tela de revisão). Ao confirmar, o serviço ainda confere se a conta fixa, a receita ou a
     * parcela casada continua em aberto; se mudou nesse meio-tempo, a linha segue como lançamento comum.
     *
     * @param  array<string, int>  $contagens  despesa, estorno, receita, contaFixa, receitaRecorrente, parcela, fatura, transferencia
     * @return list<string>
     */
    public static function importReview(array $contagens, bool $fatura, ?string $origem, bool $mexeNoSaldo, bool $oculta, array $regras = []): array
    {
        $partes = [];

        foreach ([
            'despesa' => ['despesa nova', 'despesas novas'],
            'estorno' => ['estorno', 'estornos'],
            'receita' => ['receita nova', 'receitas novas'],
            'contaFixa' => ['baixa de conta fixa', 'baixas de conta fixa'],
            'receitaRecorrente' => ['baixa de receita recorrente', 'baixas de receita recorrente'],
            'parcela' => ['pagamento de parcela de dívida', 'pagamentos de parcela de dívida'],
            'fatura' => ['pagamento de fatura de cartão', 'pagamentos de fatura de cartão'],
            'transferencia' => ['transferência entre contas', 'transferências entre contas'],
        ] as $chave => [$singular, $plural]) {
            $n = $contagens[$chave] ?? 0;

            if ($n > 0) {
                $partes[] = $n.' '.($n === 1 ? $singular : $plural);
            }
        }

        if ($partes === []) {
            return [];
        }

        $linhas = ['Ao importar: '.implode(', ', $partes).'.'];

        if (($contagens['transferencia'] ?? 0) > 0) {
            $linhas[] = 'Transferência entre contas só mexe nos saldos: não vira receita nem despesa.';
        }

        if ($fatura) {
            $linhas[] = 'As compras entram na fatura do cartão'.($origem !== null ? ' '.$origem : '').' pela data de cada uma. Nenhuma conta é debitada agora: o dinheiro só sai quando a fatura é paga.';
        } else {
            $linhas[] = $mexeNoSaldo
                ? 'O saldo'.($origem !== null ? ' de '.$origem : '').' é atualizado pelas linhas importadas.'
                : 'Este envio não altera o saldo de nenhuma conta.';
        }

        if ($oculta) {
            $linhas[] = 'O que for criado fica oculto do seu cônjuge, porque '.($fatura ? 'o cartão' : 'a conta').' não é visível a ele.';
        }

        return [...$linhas, ...self::importRules($regras)];
    }

    /**
     * As regras de categorização que nascem junto com a importação ("Criar regra também") e a conta fixa a que cada uma fica ligada. A ligação vale
     * para as próximas importações com o mesmo texto; a linha de agora dá baixa no vencimento mais próximo quando há um em aberto.
     *
     * @param  list<array{padrao: string, contaFixa: ?string, vencimento: ?string}>  $regras
     * @return list<string>
     */
    public static function importRules(array $regras): array
    {
        $linhas = [];

        foreach (array_slice($regras, 0, 5) as $r) {
            $linhas[] = match (true) {
                $r['contaFixa'] !== null && $r['vencimento'] !== null => 'Regra "'.$r['padrao'].'": será criada (ou atualizada) e ligada à conta fixa "'.$r['contaFixa'].'". Esta linha dá baixa no vencimento de '.$r['vencimento'].', e as próximas importações com esse texto também.',
                $r['contaFixa'] !== null => 'Regra "'.$r['padrao'].'": será criada (ou atualizada) e ligada à conta fixa "'.$r['contaFixa'].'". Esta linha não tem vencimento em aberto por perto: só as próximas importações com esse texto dão baixa.',
                default => 'Regra "'.$r['padrao'].'": será criada (ou atualizada) com a categoria escolhida, e as próximas importações com esse texto já vêm categorizadas.',
            };
        }

        if (count($regras) > 5) {
            $linhas[] = 'E mais '.(count($regras) - 5).' regra(s) criadas do mesmo jeito.';
        }

        return $linhas;
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

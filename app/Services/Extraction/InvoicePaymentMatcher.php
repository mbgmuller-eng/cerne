<?php

namespace App\Services\Extraction;

use App\Enums\InvoiceStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reconhece, numa linha de extrato, o pagamento de uma fatura de cartão que o Cerne já conhece.
 *
 * Duas pistas, e quanto mais juntas melhor:
 *  - o texto da linha ("pagamento fatura cartão", o nome do cartão);
 *  - o valor e a data: o total de uma fatura cadastrada, pago perto do vencimento.
 *
 * Só sugere. Quem decide é a pessoa, na revisão: dado financeiro não ganha baixa automática (regra 5 do CLAUDE.md).
 * As faturas consultadas são as dos cartões que o perfil ativo enxerga (a privacidade do cartão vale aqui também).
 */
class InvoicePaymentMatcher
{
    /** Diferença aceita entre o valor da linha e o total da fatura (arredondamento, IOF pequeno). */
    private const TOLERANCE = '1.00';

    /** Pagou antes do fechamento? Aceita poucos dias de adiantamento; depois do vencimento, até um mês de atraso. */
    private const DAYS_BEFORE_CLOSING = 3;

    private const DAYS_AFTER_DUE = 30;

    /** Sem pista de texto, o valor só basta se bater exato e perto do vencimento. */
    private const DAYS_AROUND_DUE_WITHOUT_TEXT = 5;

    /** Faturas dos últimos meses com algum valor, dos cartões que o perfil ativo enxerga. @return Collection<int, CreditCardInvoice> */
    public function invoices(): Collection
    {
        // Cartões primeiro, pelo escopo normal (perfil e privacidade); um whereHas aqui não garantiria os escopos do cartão.
        $cartoes = CreditCard::query()->get()->keyBy('id');

        if ($cartoes->isEmpty()) {
            return collect();
        }

        return CreditCardInvoice::query()
            ->whereIn('credit_card_id', $cartoes->keys())
            ->where('due_date', '>=', now()->subMonths(12)->toDateString())
            ->where('total_amount', '>', 0)
            ->orderByDesc('due_date')
            ->get()
            ->each(fn (CreditCardInvoice $f) => $f->setRelation('creditCard', $cartoes[$f->credit_card_id]));
    }

    /**
     * @param  Collection<int, CreditCardInvoice>  $faturas  o que invoices() devolveu
     * @return array{
     *     texto: bool, forte: bool,
     *     sugeridas: Collection<int, CreditCardInvoice>,
     *     ja_paga: ?CreditCardInvoice,
     *     abertas: Collection<int, CreditCardInvoice>
     * }
     *   texto: a descrição lembra pagamento de fatura; forte: lembra bem (cartão de crédito, fatura do cartão ou o nome de um
     *   cartão do perfil); sugeridas: faturas em aberto que casam por valor e data; ja_paga: uma fatura já marcada como
     *   paga que casa com a linha; abertas: as demais faturas em aberto, para a pessoa escolher à mão.
     */
    public function match(Collection $faturas, string $descricao, string|int|float|null $valor, CarbonImmutable $data): array
    {
        $valor = ltrim(Money::parse($valor), '-');
        $texto = $this->textHint($descricao, $faturas);

        $casaValor = fn (CreditCardInvoice $f, string $alvo): bool => $this->within($alvo, $valor);

        $emAberto = $faturas->reject(fn (CreditCardInvoice $f) => $f->status === InvoiceStatus::Paid);

        $sugeridas = $emAberto
            ->filter(fn (CreditCardInvoice $f) => $casaValor($f, (string) $f->total_amount) && $this->inWindow($f, $data))
            ->filter(fn (CreditCardInvoice $f) => $texto['texto']
                || (bccomp((string) $f->total_amount, $valor, 2) === 0 && $this->daysFromDue($f, $data) <= self::DAYS_AROUND_DUE_WITHOUT_TEXT))
            ->sortBy(fn (CreditCardInvoice $f) => $this->daysFromDue($f, $data))
            ->values();

        $jaPaga = $faturas
            ->filter(fn (CreditCardInvoice $f) => $f->status === InvoiceStatus::Paid
                && $this->within((string) ($f->paid_amount ?? $f->total_amount), $valor)
                && ($f->paid_at !== null ? abs($f->paid_at->diffInDays($data)) <= 7 : $this->inWindow($f, $data)))
            ->sortBy(fn (CreditCardInvoice $f) => $this->daysFromDue($f, $data))
            ->first();

        return [
            'texto' => $texto['texto'],
            'forte' => $texto['forte'],
            'sugeridas' => $sugeridas,
            'ja_paga' => $jaPaga,
            'abertas' => $emAberto->reject(fn (CreditCardInvoice $f) => $sugeridas->contains('id', $f->id))->values(),
        ];
    }

    /** "Nubank ····1234 · fatura 10/2026 · R$ 2.340,00 · vence 10/10". */
    public static function label(CreditCardInvoice $fatura): string
    {
        return $fatura->creditCard->displayName()
            .' · fatura '.$fatura->competenceLabel()
            .' · '.Money::format($fatura->total_amount)
            .' · vence '.$fatura->due_date->format('d/m');
    }

    /**
     * @param  Collection<int, CreditCardInvoice>  $faturas
     * @return array{texto: bool, forte: bool}
     */
    public function textHint(string $descricao, Collection $faturas): array
    {
        $normal = Str::lower(Str::ascii($descricao));

        if ($normal === '') {
            return ['texto' => false, 'forte' => false];
        }

        $forte = (bool) preg_match('/cartao\s+(de\s+)?credito|fatura\s+(do\s+)?cartao|pag\w*\s+(d[aeo]\s+)?(fatura|fat)\b.*cart/u', $normal);

        // O nome de um cartão cadastrado perto de "fatura" ou "pagamento" também é pista forte.
        if (! $forte && preg_match('/\b(fatura|fat|pagamento|pgto|pag)\b/u', $normal)) {
            foreach ($faturas->pluck('creditCard')->unique('id') as $cartao) {
                foreach ([$cartao->card_name, $cartao->bank_name] as $nome) {
                    $nomeNormal = Str::lower(Str::ascii((string) $nome));

                    if (mb_strlen($nomeNormal) >= 3 && str_contains($normal, $nomeNormal)) {
                        $forte = true;
                    }
                }
            }
        }

        return ['texto' => $forte || ExternalReportReview::looksLikeCardPayment($descricao), 'forte' => $forte];
    }

    private function within(string $a, string $b): bool
    {
        $diferenca = bcsub($a, $b, 2);

        return bccomp(ltrim($diferenca, '-'), self::TOLERANCE, 2) <= 0;
    }

    private function inWindow(CreditCardInvoice $fatura, CarbonImmutable $data): bool
    {
        return $data->greaterThanOrEqualTo(CarbonImmutable::instance($fatura->closing_date)->subDays(self::DAYS_BEFORE_CLOSING)->startOfDay())
            && $data->lessThanOrEqualTo(CarbonImmutable::instance($fatura->due_date)->addDays(self::DAYS_AFTER_DUE)->endOfDay());
    }

    private function daysFromDue(CreditCardInvoice $fatura, CarbonImmutable $data): int
    {
        return (int) abs(CarbonImmutable::instance($fatura->due_date)->startOfDay()->diffInDays($data->startOfDay()));
    }
}

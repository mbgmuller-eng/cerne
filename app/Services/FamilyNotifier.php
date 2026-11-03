<?php

namespace App\Services;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBillPayment;
use App\Models\ProfileCaregiver;
use App\Models\Scopes\MemberPrivacyScope;
use App\Models\Scopes\ProfileScope;
use App\Models\User;
use App\Notifications\FamilyFinanceNotice;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos a quem acompanha as finanças de alguém (os filhos de dois idosos): conta a vencer ou atrasada, gasto acima do limite combinado e o resumo
 * da semana. Segue a mesma regra do que eles enxergam: nada que seja privado, e só o que é das pessoas que cada familiar acompanha. Cada familiar liga
 * ou desliga cada tipo de aviso (notify_finance, notify_alerts, notify_weekly).
 */
class FamilyNotifier
{
    public function __construct(private readonly ReminderLedger $livro) {}

    /**
     * Familiares com as finanças liberadas que enxergam um dado de `$memberId` (nulo = da família, de todos), com o aviso `$flag` ligado.
     *
     * @param  'notify_finance'|'notify_alerts'|'notify_weekly'  $flag
     * @return Collection<int, User>
     */
    public function recipients(string $profileId, ?string $memberId, string $flag): Collection
    {
        return ProfileCaregiver::query()->active()
            ->where('profile_id', $profileId)->where('can_view_finance', true)->where($flag, true)
            ->when($memberId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('member_id')->orWhere('member_id', $memberId)))
            ->with('user')->get()->pluck('user')->filter()->unique('id')->values();
    }

    // --- Conta a vencer, fatura a vencer e atrasos ---------------------------------------------------------------------------

    /** Conta fixa que vence em breve. Conta privada nunca avisa a família. */
    public function billDueSoon(FixedBillPayment $pagamento): int
    {
        $conta = $pagamento->fixedBill;

        if ($conta === null || $conta->is_private) {
            return 0;
        }

        $quando = $pagamento->due_date->format('d/m');

        return $this->send(
            $this->recipients($conta->profile_id, $conta->member_id, 'notify_finance'), $conta->profile_id, 'bill_due_soon', "Conta a vencer: {$conta->name}",
            "{$conta->name} vence em {$quando} (".Money::format($conta->amount).').', $pagamento->id,
        );
    }

    /** Fatura de cartão que vence em breve. Cartão privado (só do dono) nunca avisa a família. */
    public function invoiceDueSoon(CreditCardInvoice $fatura): int
    {
        $cartao = $fatura->creditCard;

        if ($cartao === null || ! $this->cardIsFamilyVisible($cartao)) {
            return 0;
        }

        $quando = $fatura->due_date->format('d/m');

        return $this->send(
            $this->recipients($cartao->profile_id, $cartao->is_joint ? null : $cartao->member_id, 'notify_finance'), $cartao->profile_id, 'invoice_due_soon',
            "Fatura a vencer: {$cartao->card_name}", "A fatura do cartão {$cartao->card_name} vence em {$quando} (".Money::format($fatura->total_amount).').', $fatura->id,
        );
    }

    /**
     * Conta ou fatura que passou do vencimento e continua aberta, até 30 dias de atraso (não despeja o passado). Avisa uma vez por vencimento.
     *
     * @return int quantos avisos foram enviados
     */
    public function notifyOverdue(?CarbonImmutable $hoje = null): int
    {
        $hoje = ($hoje ?? CarbonImmutable::now())->startOfDay();
        $desde = $hoje->subDays(30)->toDateString();
        $enviados = 0;

        FixedBillPayment::withoutProfileScope()->where('status', FixedBillPaymentStatus::Overdue)
            ->whereDate('due_date', '<', $hoje->toDateString())->whereDate('due_date', '>=', $desde)
            ->with(['fixedBill' => fn ($q) => $q->withoutGlobalScope(ProfileScope::class)->withoutGlobalScope(MemberPrivacyScope::class)])
            ->chunkById(200, function ($atrasados) use (&$enviados): void {
                foreach ($atrasados as $pagamento) {
                    $conta = $pagamento->fixedBill;

                    if ($conta === null || $conta->is_private) {
                        continue;
                    }

                    $enviados += $this->sendOnce(
                        $this->recipients($conta->profile_id, $conta->member_id, 'notify_finance'), $conta->profile_id, 'bill_overdue', "Conta atrasada: {$conta->name}",
                        "{$conta->name} venceu em {$pagamento->due_date->format('d/m')} (".Money::format($conta->amount).') e continua em aberto.', $pagamento->id,
                    );
                }
            });

        CreditCardInvoice::withoutProfileScope()->where('status', InvoiceStatus::Overdue)
            ->whereDate('due_date', '<', $hoje->toDateString())->whereDate('due_date', '>=', $desde)
            ->with(['creditCard' => fn ($q) => $q->withoutGlobalScope(ProfileScope::class)->withoutGlobalScope(MemberPrivacyScope::class)])
            ->chunkById(200, function ($atrasadas) use (&$enviados): void {
                foreach ($atrasadas as $fatura) {
                    $cartao = $fatura->creditCard;

                    if ($cartao === null || ! $this->cardIsFamilyVisible($cartao)) {
                        continue;
                    }

                    $enviados += $this->sendOnce(
                        $this->recipients($cartao->profile_id, $cartao->is_joint ? null : $cartao->member_id, 'notify_finance'), $cartao->profile_id, 'invoice_overdue',
                        "Fatura atrasada: {$cartao->card_name}", "A fatura do cartão {$cartao->card_name} venceu em {$fatura->due_date->format('d/m')} (".Money::format($fatura->total_amount).') e continua em aberto.',
                        $fatura->id,
                    );
                }
            });

        return $enviados;
    }

    // --- Gasto acima do limite ---------------------------------------------------------------------------------------------------

    /**
     * Um gasto novo que chega ao limite que a casa combinou (financial_profiles.family_alert_threshold; vazio = desligado). Não avisa gasto privado,
     * estorno, parcela que não é a primeira (a compra já avisou) nem lançamento de data antiga (importação de extrato antigo).
     */
    public function expenseAlert(ExpenseRecord $despesa): int
    {
        if ($despesa->is_private || $despesa->is_refund || ($despesa->installment_number ?? 1) > 1) {
            return 0;
        }

        $perfil = FinancialProfile::query()->find($despesa->profile_id);
        $limite = $perfil?->family_alert_threshold;

        if ($perfil === null || $limite === null || bccomp((string) $despesa->amount, (string) $limite, 2) < 0) {
            return 0;
        }

        if ($despesa->expense_date === null || $despesa->expense_date->lt(CarbonImmutable::now()->subDays(3)->startOfDay())) {
            return 0;
        }

        $destinatarios = $this->recipients($despesa->profile_id, $despesa->member_id, 'notify_alerts')->reject(fn (User $u) => $u->id === auth()->id());

        return $this->send(
            $destinatarios, $despesa->profile_id, 'high_expense', 'Gasto acima do limite combinado',
            Money::format($despesa->amount)." em \"{$despesa->description}\", no dia {$despesa->expense_date->format('d/m')}.", $despesa->id,
        );
    }

    // --- Resumo da semana --------------------------------------------------------------------------------------------------------

    /**
     * Um resumo por familiar e por perfil, na segunda-feira, calculado SOB o que ele enxerga (o mesmo contexto das telas de leitura). Idempotente:
     * o livro de lembretes reserva (usuário, perfil, semana) antes de enviar; o assunto é um hash porque a coluna cabe 36 caracteres.
     *
     * @return int quantos resumos foram enviados
     */
    public function sendWeeklySummaries(?CarbonImmutable $hoje = null): int
    {
        $hoje = ($hoje ?? CarbonImmutable::now())->startOfDay();
        $semana = $hoje->isoWeekYear.'-W'.str_pad((string) $hoje->isoWeek, 2, '0', STR_PAD_LEFT);
        $enviados = 0;

        $acessos = ProfileCaregiver::query()->active()->where('can_view_finance', true)->where('notify_weekly', true)->orderBy('created_at')->get()
            ->groupBy(fn (ProfileCaregiver $a) => $a->user_id.'|'.$a->profile_id);

        foreach ($acessos as $grupo) {
            $primeiro = $grupo->first();
            $perfil = FinancialProfile::query()->find($primeiro->profile_id);
            $usuario = User::query()->find($primeiro->user_id);

            if ($perfil === null || $usuario === null || ! $usuario->is_active) {
                continue;
            }

            if (! $this->livro->claim($usuario->id, 'family_week', md5($perfil->id.':'.$semana), 'weekly')) {
                continue;
            }

            $contexto = app(ProfileContext::class);

            try {
                // Cada familiar vê só o que o acompanhamento dele cobre: o resumo é calculado com o mesmo contexto das telas de leitura.
                $contexto->set($perfil, null, false, $primeiro, $grupo->all());
                $texto = $this->weeklyText($contexto, $hoje);
            } finally {
                $contexto->clear();
            }

            Notification::send($usuario, new FamilyFinanceNotice($perfil->id, 'weekly_summary', 'Resumo da semana: '.$texto['nomes'], $texto['mensagem']));
            $enviados++;
        }

        return $enviados;
    }

    /** @return array{nomes: string, mensagem: string} */
    private function weeklyText(ProfileContext $contexto, CarbonImmutable $hoje): array
    {
        $painel = app(DashboardService::class);

        $gastos = Money::parse(ExpenseRecord::query()->whereBetween('expense_date', [$hoje->subDays(6)->toDateString(), $hoje->toDateString()])->sum('amount'));
        $proximas = $painel->upcomingBills();
        $atrasadas = FixedBillPayment::query()->outstanding()->whereDate('due_date', '<', $hoje->toDateString())->count()
            + CreditCardInvoice::query()->outstanding()->whereDate('due_date', '<', $hoje->toDateString())->count();
        $emConta = $painel->netWorth()['contas'];

        $partes = [
            'Gastos dos últimos 7 dias: '.Money::format($gastos).'.',
            count($proximas['itens']) === 0
                ? "Nada vence nos próximos {$proximas['dias']} dias."
                : count($proximas['itens']).' '.(count($proximas['itens']) === 1 ? 'conta vence' : 'contas vencem')." nos próximos {$proximas['dias']} dias (".Money::format($proximas['total']).').',
            $atrasadas > 0 ? "{$atrasadas} em atraso." : 'Nada em atraso.',
            'Saldo em contas: '.Money::format($emConta).'.',
        ];

        return ['nomes' => $contexto->followedNames(), 'mensagem' => implode(' ', $partes)];
    }

    // --- Envio -----------------------------------------------------------------------------------------------------------------------

    /** Cartão que a família pode enxergar: da família, conjunto, ou da pessoa e aberto ao cônjuge (não "só meu"). */
    private function cardIsFamilyVisible(CreditCard $cartao): bool
    {
        return $cartao->member_id === null || $cartao->is_joint || $cartao->visible_to_partner;
    }

    /** @param  Collection<int, User>  $usuarios */
    private function send(Collection $usuarios, string $profileId, string $kind, string $titulo, string $mensagem, string $subjectId): int
    {
        // O mesmo vencimento não avisa duas vezes no mesmo dia (a rotina pode rodar de novo).
        $novos = $usuarios->reject(fn (User $u) => $u->notifications()->where('type', FamilyFinanceNotice::class)
            ->whereJsonContains('data->subject_id', $subjectId)->whereJsonContains('data->notice', $kind)->whereDate('created_at', today())->exists());

        if ($novos->isEmpty()) {
            return 0;
        }

        Notification::send($novos, new FamilyFinanceNotice($profileId, $kind, $titulo, $mensagem, $subjectId));

        return $novos->count();
    }

    /** Uma vez por vencimento, nunca de novo (atraso). */
    private function sendOnce(Collection $usuarios, string $profileId, string $kind, string $titulo, string $mensagem, string $subjectId): int
    {
        $novos = $usuarios->reject(fn (User $u) => $u->notifications()->where('type', FamilyFinanceNotice::class)
            ->whereJsonContains('data->subject_id', $subjectId)->whereJsonContains('data->notice', $kind)->exists());

        if ($novos->isEmpty()) {
            return 0;
        }

        Notification::send($novos, new FamilyFinanceNotice($profileId, $kind, $titulo, $mensagem, $subjectId));

        return $novos->count();
    }
}

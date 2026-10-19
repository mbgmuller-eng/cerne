<?php

namespace App\Services;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Enums\DebtKind;
use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Notifications\DebtAdjustmentDue;
use App\Notifications\DebtInstallmentOverdue;
use App\Support\ClientDisplayName;
use App\Notifications\DebtInstallmentDueSoon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisa quem tem parcela de financiamento ou empréstimo vencendo daqui a `cerne.notifications.days_before_due` dias, como as
 * contas fixas. Idempotente pelo livro de lembretes (índice único por usuário e parcela): rodar duas vezes não avisa duas.
 *
 * Roda no cron, sem perfil ativo, então lê sem os escopos e reproduz a privacidade à mão: dívida privada só avisa o dono.
 */
class DebtReminderService
{
    public function __construct(private readonly ReminderLedger $livro) {}

    /** @return int quantos avisos foram enviados */
    public function notifyUpcoming(?CarbonImmutable $hoje = null, ?int $diasAntes = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $alvo = $hoje->addDays($diasAntes ?? config('cerne.notifications.days_before_due'))->toDateString();
        $enviados = 0;

        DebtInstallment::withoutGlobalScopes()
            ->where('status', DebtInstallmentStatus::Open)
            ->whereDate('due_date', $alvo)
            ->whereIn('debt_id', Debt::withoutGlobalScopes()->where('status', DebtStatus::Active)->select('id'))
            ->with(['debt' => fn ($q) => $q->withoutGlobalScopes()])
            ->chunkById(200, function ($parcelas) use (&$enviados): void {
                foreach ($parcelas as $parcela) {
                    foreach ($this->recipientsFor($parcela->profile_id, $parcela->member_id, $parcela->is_private) as $usuario) {
                        if (! $this->livro->claim($usuario->id, 'debt_installment', $parcela->id, 'due_soon')) {
                            continue;
                        }

                        $usuario->notify(DebtInstallmentDueSoon::forInstallment($parcela));
                        $enviados++;
                    }
                }
            });

        return $enviados;
    }

    /**
     * Chegou (ou passou) a data do reajuste da carta de um consórcio ativo: avisa uma vez por data prevista. Quem registra o
     * reajuste move a data para a frente (DebtService::registerAdjustment), então o aviso seguinte é de outra data.
     *
     * @return int quantos avisos foram enviados
     */
    public function notifyAdjustments(?CarbonImmutable $hoje = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $enviados = 0;

        Debt::withoutGlobalScopes()
            ->where('kind', DebtKind::Consortium)
            ->where('status', DebtStatus::Active)
            ->whereNotNull('next_adjustment_on')
            ->whereDate('next_adjustment_on', '<=', $hoje->toDateString())
            ->chunkById(200, function ($dividas) use (&$enviados): void {
                foreach ($dividas as $divida) {
                    foreach ($this->recipientsFor($divida->profile_id, $divida->member_id, $divida->is_private) as $usuario) {
                        if (! $this->livro->claim($usuario->id, 'debt_adjust', $divida->id, 'adjust_'.$divida->next_adjustment_on->toDateString())) {
                            continue;
                        }

                        $usuario->notify(new DebtAdjustmentDue($divida->profile_id, $divida->id, $divida->name, $divida->adjustment_index?->label() ?? 'índice', $divida->next_adjustment_on->toDateString()));
                        $enviados++;
                    }
                }
            });

        return $enviados;
    }

    /** Dias de atraso do primeiro aviso, do lembrete e do aviso ao consultor; passado o limite, a pessoa já sabe e não se insiste. */
    private const OVERDUE_FIRST_DAY = 1;

    private const OVERDUE_REMINDER_DAY = 7;

    private const OVERDUE_CONSULTANT_DAY = 7;

    private const OVERDUE_MAX_DAYS = 45;

    /**
     * Avisa a parcela em aberto que passou do vencimento: quem deve é avisado no primeiro dia de atraso e de novo na semana
     * seguinte; o consultor do cliente, uma vez, quando chega a uma semana. Acima de 45 dias de atraso não avisa mais. Parcela
     * pulada (diluída) não está em aberto, então não entra. Idempotente pelo livro de lembretes.
     *
     * @return int quantos avisos foram enviados
     */
    public function notifyOverdue(?CarbonImmutable $hoje = null): int
    {
        $hoje = ($hoje ?? CarbonImmutable::now())->startOfDay();
        $enviados = 0;
        $consultores = [];

        DebtInstallment::withoutGlobalScopes()
            ->where('status', DebtInstallmentStatus::Open)
            ->whereDate('due_date', '<=', $hoje->subDays(self::OVERDUE_FIRST_DAY)->toDateString())
            ->whereDate('due_date', '>=', $hoje->subDays(self::OVERDUE_MAX_DAYS)->toDateString())
            ->whereIn('debt_id', Debt::withoutGlobalScopes()->where('status', DebtStatus::Active)->select('id'))
            ->with(['debt' => fn ($q) => $q->withoutGlobalScopes()])
            ->chunkById(200, function ($parcelas) use (&$enviados, &$consultores, $hoje): void {
                foreach ($parcelas as $parcela) {
                    $dias = (int) CarbonImmutable::instance($parcela->due_date)->startOfDay()->diffInDays($hoje);

                    foreach ($this->recipientsFor($parcela->profile_id, $parcela->member_id, $parcela->is_private) as $usuario) {
                        $primeiro = $this->livro->claim($usuario->id, 'debt_installment', $parcela->id, 'overdue_1');
                        $lembrete = $dias >= self::OVERDUE_REMINDER_DAY && $this->livro->claim($usuario->id, 'debt_installment', $parcela->id, 'overdue_7');

                        // Quem só vê a parcela depois de uma semana de atraso recebe um aviso, não dois.
                        if ($primeiro || $lembrete) {
                            $usuario->notify(DebtInstallmentOverdue::forInstallment($parcela, $dias));
                            $enviados++;
                        }
                    }

                    if ($dias < self::OVERDUE_CONSULTANT_DAY) {
                        continue;
                    }

                    $consultores[$parcela->profile_id] ??= $this->consultantsFor($parcela->profile_id);

                    foreach ($consultores[$parcela->profile_id]['users'] as $consultor) {
                        if ($this->livro->claim($consultor->id, 'debt_installment', $parcela->id, 'overdue_consultant')) {
                            $consultor->notify(DebtInstallmentOverdue::forInstallment($parcela, $dias, $consultores[$parcela->profile_id]['name']));
                            $enviados++;
                        }
                    }
                }
            });

        return $enviados;
    }

    /**
     * Os consultores com vínculo ativo com o dono do perfil e o nome do cliente para o aviso.
     *
     * @return array{users: Collection<int, User>, name: string}
     */
    private function consultantsFor(string $profileId): array
    {
        $perfil = FinancialProfile::with(['owner', 'members.user'])->find($profileId);

        if ($perfil === null) {
            return ['users' => collect(), 'name' => ''];
        }

        $ids = ConsultantClient::query()
            ->where('client_id', $perfil->owner_user_id)
            ->where('status', ConsultantClientStatus::Active)
            ->pluck('consultant_id');

        return [
            'users' => User::query()->whereIn('id', $ids)->get()->filter(fn (User $u) => $u->isConsultant())->values(),
            'name' => ClientDisplayName::forProfile($perfil),
        ];
    }

    /** @return Collection<int, User> */
    private function recipientsFor(string $profileId, ?string $memberId, bool $privada): Collection
    {
        if ($memberId === null || ! $privada) {
            return FinancialProfile::find($profileId)
                ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
                ->pluck('user')->filter()->values() ?? collect();
        }

        return collect([ProfileMember::find($memberId)?->user])->filter();
    }
}

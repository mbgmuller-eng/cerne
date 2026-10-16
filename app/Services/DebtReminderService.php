<?php

namespace App\Services;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
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
                    foreach ($this->recipientsFor($parcela) as $usuario) {
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

    /** @return Collection<int, User> */
    private function recipientsFor(DebtInstallment $parcela): Collection
    {
        if ($parcela->member_id === null || ! $parcela->is_private) {
            return FinancialProfile::find($parcela->profile_id)
                ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
                ->pluck('user')->filter()->values() ?? collect();
        }

        return collect([ProfileMember::find($parcela->member_id)?->user])->filter();
    }
}

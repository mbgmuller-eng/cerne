<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthCareItemDue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Itens de saúde de troca periódica (aparelho auditivo, palmilha, óculos...).
 * `$membro` é DE QUEM é o item; `$autor` é quem está cadastrando (mesma regra do
 * HealthAppointmentService).
 *
 * A próxima data é sempre derivada da última vez + frequência, gravada para o cron
 * achar o que vence por índice.
 */
class HealthCareItemService
{
    /** @param  array<string, mixed>  $dados  category, name, device_name, interval_value, interval_unit, last_done_on, notes */
    public function create(ProfileMember $membro, array $dados, ProfileMember $autor): HealthCareItem
    {
        $ultima = isset($dados['last_done_on']) && $dados['last_done_on'] !== ''
            ? CarbonImmutable::parse($dados['last_done_on'])
            : CarbonImmutable::today();

        $item = new HealthCareItem([
            'member_id' => $membro->id,
            'category' => $dados['category'],
            'name' => trim($dados['name']),
            'device_name' => $this->blankToNull($dados['device_name'] ?? null),
            'interval_value' => (int) $dados['interval_value'],
            'interval_unit' => $dados['interval_unit'],
            'last_done_on' => $ultima,
            'notes' => $this->blankToNull($dados['notes'] ?? null),
            'is_active' => true,
            'created_by_member_id' => $autor->id,
        ]);
        $item->next_due_on = $item->dueAfter($ultima);
        $item->save();

        return $item;
    }

    /** @param  array<string, mixed>  $dados */
    public function update(HealthCareItem $item, array $dados): HealthCareItem
    {
        $item->fill([
            'category' => $dados['category'] ?? $item->category,
            'name' => isset($dados['name']) ? trim($dados['name']) : $item->name,
            'device_name' => array_key_exists('device_name', $dados) ? $this->blankToNull($dados['device_name']) : $item->device_name,
            'interval_value' => isset($dados['interval_value']) ? (int) $dados['interval_value'] : $item->interval_value,
            'interval_unit' => $dados['interval_unit'] ?? $item->interval_unit,
            'last_done_on' => isset($dados['last_done_on']) && $dados['last_done_on'] !== '' ? CarbonImmutable::parse($dados['last_done_on']) : $item->last_done_on,
            'notes' => array_key_exists('notes', $dados) ? $this->blankToNull($dados['notes']) : $item->notes,
        ]);

        $item->next_due_on = $item->last_done_on !== null ? $item->dueAfter($item->last_done_on) : null;
        $item->save();

        return $item;
    }

    /** Fez a troca/revisão: a próxima conta a partir de agora (ou da data informada), não do vencimento antigo. */
    public function markDone(HealthCareItem $item, ?CarbonInterface $em = null): HealthCareItem
    {
        $em = $em !== null ? CarbonImmutable::instance($em)->startOfDay() : CarbonImmutable::today();

        $item->last_done_on = $em;
        $item->next_due_on = $item->dueAfter($em);
        $item->save();

        return $item;
    }

    /** Pausa (parou de usar) ou retoma; ao retomar a contagem recomeça de hoje. */
    public function setActive(HealthCareItem $item, bool $ativo): HealthCareItem
    {
        $item->is_active = $ativo;

        if ($ativo) {
            $item->last_done_on = CarbonImmutable::today();
            $item->next_due_on = $item->dueAfter($item->last_done_on);
        }

        $item->save();

        return $item;
    }

    public function delete(HealthCareItem $item): void
    {
        $item->delete();
    }

    /**
     * Roda no cron, sem ProfileContext (atravessa TODOS os perfis de propósito, como
     * HealthAppointmentService::notifyUpcoming()). Avisa no dia anterior e no próprio
     * dia: depois disso, quem não fez vê o item em atraso na tela, sem mais e-mails.
     */
    public function notifyDue(?CarbonImmutable $hoje = null, ?int $diasAntes = null): int
    {
        $hoje ??= CarbonImmutable::today();
        $antes = $diasAntes ?? (int) config('cerne.notifications.days_before_care_item');
        $datas = array_values(array_unique([$hoje->toDateString(), $hoje->addDays($antes)->toDateString()]));
        $notificados = 0;

        HealthCareItem::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereIn('next_due_on', $datas)
            ->with('member')
            ->chunkById(200, function (Collection $itens) use (&$notificados, $hoje): void {
                foreach ($itens as $item) {
                    foreach ($this->recipientsFor($item) as $user) {
                        if ($this->alreadyNotifiedToday($user, $item->id)) {
                            continue;
                        }

                        $user->notify(HealthCareItemDue::forItem($item, $hoje));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    /** @return Collection<int, User> */
    private function recipientsFor(HealthCareItem $item): Collection
    {
        return FinancialProfile::find($item->profile_id)
            ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter()->values() ?? collect();
    }

    private function alreadyNotifiedToday(User $user, string $itemId): bool
    {
        return $user->notifications()
            ->where('type', HealthCareItemDue::class)
            ->whereJsonContains('data->health_care_item_id', $itemId)
            ->whereDate('created_at', today())
            ->exists();
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

<?php

namespace App\Services;

use App\Enums\HealthCareItemEvent;
use App\Models\FinancialProfile;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthCareItemChanged;
use App\Notifications\HealthCareItemDue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

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
    public function create(ProfileMember $membro, array $dados, ?ProfileMember $autor): HealthCareItem
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
            'created_by_member_id' => $autor?->id,
        ]);
        $item->next_due_on = $item->dueAfter($ultima);
        $item->save();

        $this->announce($item, HealthCareItemEvent::Created, ['next_due' => $item->next_due_on?->toDateString()]);

        return $item;
    }

    /** @param  array<string, mixed>  $dados */
    public function update(HealthCareItem $item, array $dados): HealthCareItem
    {
        $antes = $this->snapshot($item);

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

        $depois = $this->snapshot($item);
        $mudou = array_keys(array_filter(self::NOTABLE_FIELDS, fn (string $rotulo, string $campo) => $antes[$campo] !== $depois[$campo], ARRAY_FILTER_USE_BOTH));

        if ($mudou !== [] && $item->is_active) {
            $this->announce($item, HealthCareItemEvent::Updated, ['changed' => array_map(fn (string $campo) => self::NOTABLE_FIELDS[$campo], $mudou), 'next_due' => $item->next_due_on?->toDateString()]);
        }

        return $item;
    }

    /** O que, ao mudar, avisa quem acompanha (campo => como aparece no aviso). */
    private const NOTABLE_FIELDS = [
        'name' => 'item', 'device_name' => 'aparelho', 'category' => 'categoria', 'frequency' => 'frequência', 'next_due_on' => 'próxima data',
    ];

    /** @return array<string, ?string> */
    private function snapshot(HealthCareItem $item): array
    {
        return [
            'name' => $item->name,
            'device_name' => $item->device_name,
            'category' => $item->category?->value,
            'frequency' => $item->interval_value.' '.$item->interval_unit?->value,
            'next_due_on' => $item->next_due_on?->toDateString(),
        ];
    }

    /**
     * Avisa quem acompanha a pessoa (HealthReminderRecipients::forCareItemChange), menos quem fez a mudança. Nunca atrapalha a ação de quem mexeu:
     * se o aviso falhar, a falha é registrada e o item segue.
     *
     * @param  array<string, mixed>  $extra
     */
    private function announce(HealthCareItem $item, HealthCareItemEvent $evento, array $extra = []): void
    {
        $ator = auth()->user();

        if ($ator === null) {
            return;
        }

        try {
            $item->loadMissing('member');

            $destinatarios = app(HealthReminderRecipients::class)->forCareItemChange($item)->reject(fn (User $u) => $u->id === $ator->id);

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, HealthCareItemChanged::make($item, $evento, $ator->name, $extra));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Fez a troca/revisão: a próxima conta a partir de agora (ou da data informada), não do vencimento antigo. */
    public function markDone(HealthCareItem $item, ?CarbonInterface $em = null): HealthCareItem
    {
        $em = $em !== null ? CarbonImmutable::instance($em)->startOfDay() : CarbonImmutable::today();

        $item->last_done_on = $em;
        $item->next_due_on = $item->dueAfter($em);
        $item->save();

        $this->announce($item, HealthCareItemEvent::Done, ['done_on' => $em->toDateString(), 'next_due' => $item->next_due_on->toDateString()]);

        return $item;
    }

    /** Pausa (parou de usar) ou retoma; ao retomar a contagem recomeça de hoje. */
    public function setActive(HealthCareItem $item, bool $ativo): HealthCareItem
    {
        $mudou = (bool) $item->is_active !== $ativo;
        $item->is_active = $ativo;

        if ($ativo) {
            $item->last_done_on = CarbonImmutable::today();
            $item->next_due_on = $item->dueAfter($item->last_done_on);
        }

        $item->save();

        if ($mudou) {
            $this->announce($item, $ativo ? HealthCareItemEvent::Resumed : HealthCareItemEvent::Paused, ['next_due' => $ativo ? $item->next_due_on?->toDateString() : null]);
        }

        return $item;
    }

    public function delete(HealthCareItem $item): void
    {
        $this->announce($item, HealthCareItemEvent::Removed);

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
                        // O consentimento de saúde já foi conferido em HealthReminderRecipients (o do membro, ou o declarado da pessoa acompanhada).
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
        return app(HealthReminderRecipients::class)->forProfile($item->profile_id, 'care_items', $item->member_id);
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

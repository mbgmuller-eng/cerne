<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agenda de consulta/exame da ficha de saúde. `$membro` é sempre o
 * paciente; `$autor` é quem está registrando (podem ser pessoas
 * diferentes, mesmo raciocínio de HealthCardService).
 */
class HealthAppointmentService
{
    /** @param  array<string, mixed>  $dados  kind, title, location, scheduled_at, notes */
    public function create(ProfileMember $membro, array $dados, ProfileMember $autor): HealthAppointment
    {
        return HealthAppointment::create([
            'member_id' => $membro->id,
            'kind' => $dados['kind'],
            'title' => trim($dados['title']),
            'location' => $this->blankToNull($dados['location'] ?? null),
            'scheduled_at' => $dados['scheduled_at'],
            'notes' => $this->blankToNull($dados['notes'] ?? null),
            'created_by_member_id' => $autor->id,
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public function update(HealthAppointment $consulta, array $dados): HealthAppointment
    {
        $consulta->update([
            'kind' => $dados['kind'] ?? $consulta->kind,
            'title' => isset($dados['title']) ? trim($dados['title']) : $consulta->title,
            'location' => array_key_exists('location', $dados) ? $this->blankToNull($dados['location']) : $consulta->location,
            'scheduled_at' => $dados['scheduled_at'] ?? $consulta->scheduled_at,
            'notes' => array_key_exists('notes', $dados) ? $this->blankToNull($dados['notes']) : $consulta->notes,
        ]);

        return $consulta;
    }

    public function delete(HealthAppointment $consulta): void
    {
        $consulta->delete();
    }

    /**
     * Roda no cron, sem ProfileContext (atravessa TODOS os perfis de
     * propósito) — mesmo padrão de FixedBillService::notifyUpcomingDueDates().
     */
    public function notifyUpcoming(?CarbonImmutable $hoje = null, ?int $diasAntes = null): int
    {
        $hoje ??= CarbonImmutable::now();
        $alvo = $hoje->copy()->addDays($diasAntes ?? config('cerne.notifications.days_before_appointment'))->toDateString();
        $notificados = 0;

        HealthAppointment::query()
            ->withoutGlobalScopes()
            ->whereDate('scheduled_at', $alvo)
            ->with('member')
            ->chunkById(200, function (Collection $consultas) use (&$notificados): void {
                foreach ($consultas as $consulta) {
                    foreach ($this->recipientsFor($consulta) as $user) {
                        if ($this->alreadyNotifiedToday($user, $consulta->id)) {
                            continue;
                        }

                        $user->notify(HealthAppointmentUpcoming::forAppointment($consulta));
                        $notificados++;
                    }
                }
            });

        return $notificados;
    }

    /** @return Collection<int, User> */
    private function recipientsFor(HealthAppointment $consulta): Collection
    {
        return FinancialProfile::find($consulta->profile_id)
            ?->activeMembers()->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter()->values() ?? collect();
    }

    private function alreadyNotifiedToday(User $user, string $appointmentId): bool
    {
        return $user->notifications()
            ->where('type', HealthAppointmentUpcoming::class)
            ->whereJsonContains('data->health_appointment_id', $appointmentId)
            ->whereDate('created_at', today())
            ->exists();
    }

    private function blankToNull(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

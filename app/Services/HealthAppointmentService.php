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
    /** Campos de texto opcionais da consulta; vazio vira nulo. */
    private const CAMPOS_OPCIONAIS = ['professional_name', 'specialty', 'location', 'address', 'phone', 'booked_by_name', 'booked_with_name', 'notes'];

    /**
     * @param  array<string, mixed>  $dados  kind, title, scheduled_at e os campos opcionais (profissional, especialidade,
     *                                       local, endereço, telefone, quem agendou e com quem, anotações)
     */
    public function create(ProfileMember $membro, array $dados, ProfileMember $autor): HealthAppointment
    {
        $opcionais = $this->opcionais($dados);

        return HealthAppointment::create($opcionais + [
            'member_id' => $membro->id,
            'kind' => $dados['kind'],
            'title' => $this->titulo($dados['title'] ?? '', $opcionais),
            'scheduled_at' => $dados['scheduled_at'],
            'created_by_member_id' => $autor->id,
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public function update(HealthAppointment $consulta, array $dados): HealthAppointment
    {
        $opcionais = $this->opcionais($dados, $consulta);

        $consulta->update($opcionais + [
            'kind' => $dados['kind'] ?? $consulta->kind,
            'title' => array_key_exists('title', $dados) ? $this->titulo($dados['title'], $opcionais) : $consulta->title,
            'scheduled_at' => $dados['scheduled_at'] ?? $consulta->scheduled_at,
        ]);

        return $consulta;
    }

    /**
     * Consulta sem título digitado ganha um: "Especialidade · Profissional". O título
     * continua obrigatório quando não há nem especialidade nem profissional (e em exame,
     * que não tem esses campos).
     *
     * @param  array<string, mixed>  $opcionais
     */
    private function titulo(string $digitado, array $opcionais): string
    {
        $digitado = trim($digitado);

        if ($digitado !== '') {
            return $digitado;
        }

        return implode(' · ', array_filter([$opcionais['specialty'] ?? null, $opcionais['professional_name'] ?? null]));
    }

    /**
     * Campos opcionais já normalizados (espaços cortados, vazio = nulo). Na edição só
     * entram os que vieram em `$dados`; os demais ficam como estão.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, ?string>
     */
    private function opcionais(array $dados, ?HealthAppointment $atual = null): array
    {
        $saida = [];

        foreach (self::CAMPOS_OPCIONAIS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $saida[$campo] = $this->blankToNull($dados[$campo]);
            } elseif ($atual === null) {
                $saida[$campo] = null;
            }
        }

        return $saida;
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

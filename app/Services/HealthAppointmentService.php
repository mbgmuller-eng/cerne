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
    public function create(ProfileMember $membro, array $dados, ?ProfileMember $autor): HealthAppointment
    {
        $opcionais = $this->opcionais($dados);

        return HealthAppointment::create($opcionais + [
            'member_id' => $membro->id,
            'kind' => $dados['kind'],
            'title' => $this->titulo($dados['title'] ?? '', $opcionais),
            'scheduled_at' => $dados['scheduled_at'],
            'created_by_member_id' => $autor?->id,
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
    /**
     * Confirma o que aconteceu com a consulta. "Realizada" e "não realizada" só depois da data (antes, ninguém sabe); "cancelada" a qualquer momento;
     * voltar para "agendada" corrige um engano. Guarda quem marcou e quando, para quem acompanha saber.
     */
    public function setStatus(HealthAppointment $consulta, \App\Enums\HealthAppointmentStatus $status, User $por): HealthAppointment
    {
        if (in_array($status, [\App\Enums\HealthAppointmentStatus::Done, \App\Enums\HealthAppointmentStatus::NotDone], true) && ! $consulta->isPast()) {
            throw new \DomainException('Só dá para confirmar se foi realizada depois da data da consulta. Antes disso, você pode cancelá-la.');
        }

        $consulta->update([
            'status' => $status,
            'status_at' => $status === \App\Enums\HealthAppointmentStatus::Scheduled ? null : now(),
            'status_by_user_id' => $status === \App\Enums\HealthAppointmentStatus::Scheduled ? null : $por->id,
        ]);

        return $consulta;
    }

    public function delete(HealthAppointment $consulta): void
    {
        $consulta->delete();
    }

    /**
     * "Eu levo": o usuário se oferece para levar a pessoa à consulta. Qualquer um que faz parte do cuidado pode, inclusive o cuidador de LEITURA:
     * é combinar quem vai, não alterar saúde. Por isso grava só a coluna `escort_user_id`, por consulta em lote (sem os eventos do model, que
     * barram a escrita de quem só lê), e só quando quem pede é o próprio escolhido.
     */
    public function volunteerEscort(HealthAppointment $consulta, User $usuario): void
    {
        $this->assertParticipant($consulta, $usuario);

        $this->writeEscort($consulta, $usuario->id);
    }

    /** Escolhe outra pessoa para levar (dono e cuidadores de edição). Avisa o escolhido, a menos que seja quem escolheu. */
    public function assignEscort(HealthAppointment $consulta, User $alvo, User $porQuem): void
    {
        if (! app(\App\Support\ProfileContext::class)->canWriteAsCaregiver()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Seu acesso a esta pessoa é só de leitura: você pode se oferecer para levar, mas não escolher outra pessoa.');
        }

        $this->assertParticipant($consulta, $alvo);
        $this->writeEscort($consulta, $alvo->id);

        if ($alvo->id !== $porQuem->id) {
            $consulta->loadMissing('member');

            $alvo->notify(new \App\Notifications\HealthEscortAssigned(
                $consulta->profile_id, $consulta->id, $porQuem->name, $consulta->member->name, $consulta->title, $consulta->scheduled_at->toIso8601String(),
            ));
        }
    }

    /** Solta a escolha: quem está escalado desiste, ou quem pode editar libera. */
    public function releaseEscort(HealthAppointment $consulta, User $usuario): void
    {
        $ehOEscalado = $consulta->escort_user_id === $usuario->id;

        if (! $ehOEscalado && ! app(\App\Support\ProfileContext::class)->canWriteAsCaregiver()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Só quem vai levar, ou quem pode editar, solta a escolha.');
        }

        $this->writeEscort($consulta, null);
    }

    private function writeEscort(HealthAppointment $consulta, ?string $userId): void
    {
        HealthAppointment::query()->whereKey($consulta->id)->update(['escort_user_id' => $userId]);
        $consulta->escort_user_id = $userId;
    }

    private function assertParticipant(HealthAppointment $consulta, User $usuario): void
    {
        $perfil = FinancialProfile::withCareProfiles()->findOrFail($consulta->profile_id);

        if (! $perfil->isCare() || ! app(CareParticipants::class)->forProfile($perfil)->contains('id', $usuario->id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Só quem cuida desta pessoa pode ser escolhido para levá-la.');
        }
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
            // Cancelada ou não realizada não gera lembrete.
            ->where('status', \App\Enums\HealthAppointmentStatus::Scheduled->value)
            ->with(['member', 'escort'])
            ->chunkById(200, function (Collection $consultas) use (&$notificados): void {
                foreach ($consultas as $consulta) {
                    foreach ($this->recipientsFor($consulta) as $user) {
                        // O consentimento de saúde já foi conferido em HealthReminderRecipients (o do membro, ou o declarado da pessoa acompanhada).
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
        return app(HealthReminderRecipients::class)->forProfile($consulta->profile_id, 'appointments', $consulta->member_id);
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

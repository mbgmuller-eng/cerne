<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Enums\HealthAppointmentEvent;
use App\Notifications\HealthAppointmentChanged;
use App\Notifications\HealthAppointmentUpcoming;
use Illuminate\Support\Facades\Notification;
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

        $consulta = HealthAppointment::create($opcionais + [
            'member_id' => $membro->id,
            'kind' => $dados['kind'],
            'title' => $this->titulo($dados['title'] ?? '', $opcionais),
            'scheduled_at' => $dados['scheduled_at'],
            'created_by_member_id' => $autor?->id,
        ]);

        // Registro de uma consulta que já passou (histórico) não é novidade para ninguém.
        if ($consulta->scheduled_at->isFuture()) {
            $this->announce($consulta, HealthAppointmentEvent::Created);
        }

        return $consulta;
    }

    /** @param  array<string, mixed>  $dados */
    public function update(HealthAppointment $consulta, array $dados): HealthAppointment
    {
        $opcionais = $this->opcionais($dados, $consulta);
        $antes = $this->snapshot($consulta);

        $consulta->update($opcionais + [
            'kind' => $dados['kind'] ?? $consulta->kind,
            'title' => array_key_exists('title', $dados) ? $this->titulo($dados['title'], $opcionais) : $consulta->title,
            'scheduled_at' => $dados['scheduled_at'] ?? $consulta->scheduled_at,
        ]);

        // Só o que ainda vai acontecer é novidade (corrigir um registro antigo não avisa ninguém).
        if ($consulta->status === \App\Enums\HealthAppointmentStatus::Scheduled && $consulta->scheduled_at->isFuture()) {
            $depois = $this->snapshot($consulta);

            if ($antes['scheduled_at'] !== $depois['scheduled_at']) {
                $this->announce($consulta, HealthAppointmentEvent::Rescheduled, null, ['old_at' => $antes['scheduled_at']]);
            } else {
                // Compara o texto já decifrado: o campo cifrado muda a cada gravação mesmo sem o texto mudar.
                $mudou = array_keys(array_filter(self::NOTABLE_FIELDS, fn (string $rotulo, string $campo) => $antes[$campo] !== $depois[$campo], ARRAY_FILTER_USE_BOTH));

                if ($mudou !== []) {
                    $this->announce($consulta, HealthAppointmentEvent::Updated, null, ['changed' => array_map(fn (string $campo) => self::NOTABLE_FIELDS[$campo], $mudou)]);
                }
            }
        }

        return $consulta;
    }

    /** O que, ao mudar, avisa quem acompanha (campo => como aparece no aviso). */
    private const NOTABLE_FIELDS = [
        'title' => 'título', 'kind' => 'tipo', 'professional_name' => 'profissional', 'specialty' => 'especialidade',
        'location' => 'local', 'address' => 'endereço', 'phone' => 'telefone',
    ];

    /** @return array<string, ?string> a data e os campos notáveis, em texto (já decifrados), para comparar antes e depois de editar */
    private function snapshot(HealthAppointment $consulta): array
    {
        $retrato = ['scheduled_at' => $consulta->scheduled_at->toIso8601String()];

        foreach (array_keys(self::NOTABLE_FIELDS) as $campo) {
            $valor = $consulta->getAttribute($campo);
            $retrato[$campo] = $valor instanceof \BackedEnum ? $valor->value : ($valor === null ? null : (string) $valor);
        }

        return $retrato;
    }

    /**
     * Avisa quem acompanha a pessoa (HealthReminderRecipients::forAppointmentChange), menos quem fez a mudança. Nunca atrapalha a ação de quem
     * mexeu: se o aviso falhar, a falha é registrada e a consulta segue.
     *
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $excluir  ids de quem já recebeu um aviso mais específico
     */
    private function announce(HealthAppointment $consulta, HealthAppointmentEvent $evento, ?User $ator = null, array $extra = [], array $excluir = []): void
    {
        $ator ??= auth()->user();

        if ($ator === null) {
            return;
        }

        try {
            $consulta->loadMissing('member');

            $destinatarios = app(HealthReminderRecipients::class)->forAppointmentChange($consulta)
                ->reject(fn (User $u) => $u->id === $ator->id || in_array($u->id, $excluir, true));

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, HealthAppointmentChanged::make($consulta, $evento, $ator->name, $extra));
            }
        } catch (\Throwable $e) {
            report($e);
        }
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

        $this->announce($consulta, match ($status) {
            \App\Enums\HealthAppointmentStatus::Done => HealthAppointmentEvent::Done,
            \App\Enums\HealthAppointmentStatus::NotDone => HealthAppointmentEvent::NotDone,
            \App\Enums\HealthAppointmentStatus::Cancelled => HealthAppointmentEvent::Cancelled,
            \App\Enums\HealthAppointmentStatus::Scheduled => HealthAppointmentEvent::Reopened,
        }, $por);

        return $consulta;
    }

    public function delete(HealthAppointment $consulta): void
    {
        // Remover uma consulta que ainda ia acontecer é novidade; apagar um registro antigo do histórico, não.
        if ($consulta->status === \App\Enums\HealthAppointmentStatus::Scheduled && $consulta->scheduled_at->isFuture()) {
            $this->announce($consulta, HealthAppointmentEvent::Removed);
        }

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
        $this->announce($consulta, HealthAppointmentEvent::EscortAssigned, $usuario, ['escort' => $usuario->name]);
    }

    /** Escolhe outra pessoa para levar (dono e cuidadores de edição). Avisa o escolhido, a menos que seja quem escolheu. */
    public function assignEscort(HealthAppointment $consulta, User $alvo, User $porQuem): void
    {
        if (! app(\App\Support\ProfileContext::class)->canWriteAsCaregiver()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Seu acesso a esta pessoa é só de leitura: você pode se oferecer para levar, mas não escolher outra pessoa.');
        }

        $this->assertParticipant($consulta, $alvo);
        $this->writeEscort($consulta, $alvo->id);

        // Todos os que cuidam sabem quem leva; quem foi escolhido recebe, além disso, o aviso próprio abaixo.
        $this->announce($consulta, HealthAppointmentEvent::EscortAssigned, $porQuem, ['escort' => $alvo->name], [$alvo->id]);

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

        $consulta->loadMissing('escort');
        $anterior = $consulta->escort;

        $this->writeEscort($consulta, null);

        if ($anterior !== null) {
            $this->announce($consulta, HealthAppointmentEvent::EscortReleased, $usuario, ['previous_escort' => $anterior->name]);
        }
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

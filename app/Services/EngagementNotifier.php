<?php

namespace App\Services;

use App\Models\FinancialProfile;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Support\Engagement\EngagementViewer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos do Acompanhamento. Quem faz a ação não é avisado dela: o consultor avisa o lado do cliente (dono e cônjuge com
 * login) e o cliente avisa o consultor vinculado.
 */
class EngagementNotifier
{
    public function __construct(private readonly EngagementAccess $access) {}

    public function toOtherSide(EngagementViewer $visitante, string $kind, string $heading, string $message): void
    {
        $perfil = FinancialProfile::query()->find($visitante->profileId);

        if ($perfil === null) {
            return;
        }

        $destinatarios = $visitante->isConsultant()
            ? $this->access->clientUsers($perfil)
            : User::query()->whereIn('id', $visitante->consultantIds)->get();

        $this->send($destinatarios->reject(fn (User $u) => $u->id === $visitante->userId), $perfil->id, $kind, $heading, $message);
    }

    /** @param  Collection<int, User>  $usuarios */
    public function send(Collection $usuarios, string $profileId, string $kind, string $heading, string $message): void
    {
        if ($usuarios->isEmpty()) {
            return;
        }

        Notification::send($usuarios, new EngagementNotice($profileId, $kind, $heading, $message));
    }
}

<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\UserRole;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\Engagement\EngagementViewer;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;

/**
 * Quem pode abrir a área de Acompanhamento de um perfil.
 *
 *  - O cliente (dono ou cônjuge com login) só tem a área enquanto existir um consultor financeiro vinculado e ativo. Sem
 *    consultor, a área não existe para ele.
 *  - O consultor só entra no perfil de um cliente com o qual tem vínculo ativo, e vê apenas a relação dele.
 *  - Corretor de seguros nunca entra: o acompanhamento é da consultoria financeira.
 *
 * O resultado é guardado por (usuário, perfil) durante a requisição, porque o escopo de cada model consulta isto; o cache é
 * limpo sempre que um vínculo muda (ver ConsultantClient::booted()).
 */
class EngagementAccess
{
    /** @var array<string, ?EngagementViewer> */
    private array $memo = [];

    /** O visitante da requisição atual: usuário logado + perfil ativo. Nulo sem um dos dois, ou sem acesso. */
    public function viewer(): ?EngagementViewer
    {
        $usuario = auth()->user();
        $perfil = app(ProfileContext::class)->profile();

        if ($usuario === null || $perfil === null) {
            return null;
        }

        return $this->viewerFor($usuario, $perfil);
    }

    public function viewerFor(User $usuario, FinancialProfile $perfil): ?EngagementViewer
    {
        $chave = $usuario->id.'|'.$perfil->id;

        if (! array_key_exists($chave, $this->memo)) {
            $this->memo[$chave] = $this->resolve($usuario, $perfil);
        }

        return $this->memo[$chave];
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return Collection<int, User> consultores financeiros com vínculo ativo com o dono do perfil */
    public function activeConsultants(FinancialProfile $perfil): Collection
    {
        return User::query()
            ->where('role', UserRole::Consultant)
            ->whereIn('id', ConsultantClient::query()
                ->where('client_id', $perfil->owner_user_id)
                ->where('status', ConsultantClientStatus::Active)
                ->select('consultant_id'))
            ->orderBy('name')
            ->get();
    }

    /** Usuários com login que são do lado do cliente: o dono e os membros ativos com conta. */
    public function clientUsers(FinancialProfile $perfil): Collection
    {
        $ids = ProfileMember::query()
            ->where('profile_id', $perfil->id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->push($perfil->owner_user_id)
            ->unique();

        return User::query()->whereIn('id', $ids)->get();
    }

    private function resolve(User $usuario, FinancialProfile $perfil): ?EngagementViewer
    {
        if ($usuario->isBroker()) {
            return null;
        }

        if ($usuario->isConsultant()) {
            $vinculado = ConsultantClient::query()
                ->where('consultant_id', $usuario->id)
                ->where('client_id', $perfil->owner_user_id)
                ->where('status', ConsultantClientStatus::Active)
                ->exists();

            return $vinculado ? new EngagementViewer('consultant', $usuario->id, $perfil->id, [$usuario->id]) : null;
        }

        $ehDoPerfil = $perfil->owner_user_id === $usuario->id || $perfil->memberFor($usuario) !== null;

        if (! $ehDoPerfil) {
            return null;
        }

        $consultores = $this->activeConsultants($perfil)->pluck('id')->values()->all();

        return $consultores === [] ? null : new EngagementViewer('client', $usuario->id, $perfil->id, $consultores);
    }
}

<?php

namespace App\Livewire\Admin;

use App\Enums\InviteStatus;
use App\Enums\UserRole;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\ClientInviteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Painel administrativo: todo usuário e todo perfil da plataforma, e um
 * jeito de criar conta de cliente SEM vínculo de consultor — pro amigo que
 * quer usar o Cerne por conta própria, sem o Marcelo (ou qualquer outro
 * consultor) enxergando os dados dele. Mesmo fluxo de convite/aceite de
 * sempre (ConsultantInvite + /convite/{token}), só que o convite nasce sem
 * consultor — ver ClientInviteService::sendStandalone().
 */
#[Layout('components.layouts.app')]
class AdminUsers extends Component
{
    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;

    /** id da conta com o painel de exclusão aberto, ou nulo. */
    public ?string $excluindoUserId = null;

    public string $confirmacaoExclusao = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;
        $this->cancelarExclusao();

        if ($this->showInviteForm) {
            $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    public function invite(ClientInviteService $invites): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        if (User::where('email', $this->inviteEmail)->exists()) {
            $this->addError('inviteEmail', 'Esse e-mail já tem conta no Cerne.');

            return;
        }

        $this->lastInviteLink = $invites->sendStandalone($this->inviteName, $this->inviteEmail);
        $this->reset('inviteName', 'inviteEmail');
        session()->flash('status', 'Convite criado — copie o link abaixo e envie pro seu amigo.');
    }

    public function pedirExclusao(string $userId): void
    {
        $this->excluindoUserId = $userId;
        $this->confirmacaoExclusao = '';
        $this->showInviteForm = false;
        $this->resetErrorBag();
    }

    public function cancelarExclusao(): void
    {
        $this->excluindoUserId = null;
        $this->confirmacaoExclusao = '';
    }

    /**
     * Excluir é físico, não is_active — é o que "excluir" pediu, pra
     * limpar de vez conta de teste/amigo que não vai continuar. O alcance
     * vem inteiro das foreign keys (cascadeOnDelete em owner_user_id,
     * profile_id em toda tabela de domínio, consultant_clients dos dois
     * lados): dono de perfil leva o perfil INTEIRO junto — inclusive dados
     * do cônjuge, se houver, embora o login do cônjuge sobreviva vazio;
     * membro sem ser dono só perde o login (profile_members.user_id vira
     * nulo, ver nullOnDelete); consultor só perde os vínculos, cliente
     * nenhum é tocado. Por isso a confirmação por e-mail digitado, não só
     * um clique.
     */
    public function excluirConta(): void
    {
        $alvo = User::findOrFail($this->excluindoUserId);

        if ($alvo->id === auth()->id()) {
            $this->addError('confirmacaoExclusao', 'Você não pode excluir a própria conta.');

            return;
        }

        if ($this->confirmacaoExclusao !== $alvo->email) {
            $this->addError('confirmacaoExclusao', 'Digite o e-mail exatamente como aparece na lista pra confirmar.');

            return;
        }

        $nome = $alvo->name;
        $alvo->delete();

        $this->excluindoUserId = null;
        $this->confirmacaoExclusao = '';
        session()->flash('status', "Conta de {$nome} excluída — todos os dados vinculados a ela foram apagados.");
    }

    /**
     * Resumo do que a exclusão vai levar junto, pra mostrar antes do
     * clique — dono de perfil vê a escala real (quantos lançamentos,
     * contas, investimentos), consultor vê quantos vínculos perde.
     *
     * @return array<string, mixed>|null
     */
    public function getExclusaoInfoProperty(): ?array
    {
        if ($this->excluindoUserId === null) {
            return null;
        }

        $alvo = User::find($this->excluindoUserId);

        if ($alvo === null) {
            return null;
        }

        $perfil = FinancialProfile::query()->where('owner_user_id', $alvo->id)->first();

        if ($perfil !== null) {
            return [
                'tipo' => 'dono_de_perfil',
                'nome' => $alvo->name,
                'email' => $alvo->email,
                'perfil' => $perfil,
                'membros' => $perfil->members()->count(),
                'despesas' => DB::table('expense_records')->where('profile_id', $perfil->id)->count(),
                'receitas' => DB::table('income_records')->where('profile_id', $perfil->id)->count(),
                'contas' => DB::table('bank_accounts')->where('profile_id', $perfil->id)->count(),
                'investimentos' => DB::table('investment_records')->where('profile_id', $perfil->id)->count(),
            ];
        }

        if ($alvo->isConsultant()) {
            return [
                'tipo' => 'consultor',
                'nome' => $alvo->name,
                'email' => $alvo->email,
                'clientes_vinculados' => ConsultantClient::query()->where('consultant_id', $alvo->id)->count(),
            ];
        }

        return [
            'tipo' => 'sem_perfil_proprio',
            'nome' => $alvo->name,
            'email' => $alvo->email,
        ];
    }

    /** Convites sem consultor ainda aguardando cadastro. */
    public function getPendingInvitesProperty(): Collection
    {
        return ConsultantInvite::query()
            ->whereNull('consultant_id')
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->get()
            ->reject(fn (ConsultantInvite $invite) => $invite->isExpired())
            ->values();
    }

    /**
     * Consultor → clientes vinculados → clientes sem consultor, nessa
     * ordem — é como o consultor pensa a própria carteira (ver
     * PortfolioOverview), então o admin devia ver a plataforma inteira do
     * mesmo jeito, só que com todo mundo, não só quem está vinculado a
     * ele. "Outras contas" pega o resto (cônjuge com login próprio, que
     * não é dono de perfil nenhum; e qualquer conta que sobre) pra nenhuma
     * conta ficar impossível de encontrar ou excluir por aqui.
     */
    public function render()
    {
        $consultores = User::query()->where('role', UserRole::Consultant)->orderBy('name')->get();

        $idsClientesVinculados = ConsultantClient::query()->active()->pluck('client_id');

        $grupos = $consultores->map(fn (User $consultor) => [
            'consultor' => $consultor,
            'perfis' => FinancialProfile::query()
                ->whereIn('owner_user_id', ConsultantClient::query()
                    ->where('consultant_id', $consultor->id)
                    ->active()
                    ->pluck('client_id'))
                ->with('owner')
                ->orderBy('profile_name')
                ->get(),
        ]);

        $perfisSemConsultor = FinancialProfile::query()
            ->whereNotIn('owner_user_id', $idsClientesVinculados->merge($consultores->pluck('id')))
            ->with('owner')
            ->orderBy('profile_name')
            ->get();

        $idsJaMostrados = $consultores->pluck('id')
            ->merge($grupos->flatMap(fn (array $g) => $g['perfis'])->pluck('owner_user_id'))
            ->merge($perfisSemConsultor->pluck('owner_user_id'));

        $outrasContas = User::query()
            ->whereNotIn('id', $idsJaMostrados)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->contextoDeOutraConta($u));

        return view('livewire.admin.admin-users', [
            'totalUsuarios' => User::query()->count(),
            'totalPerfis' => FinancialProfile::query()->count(),
            'grupos' => $grupos,
            'perfisSemConsultor' => $perfisSemConsultor,
            'outrasContas' => $outrasContas,
        ]);
    }

    /**
     * Perfil principal e consultor de quem não é dono de perfil nenhum —
     * tipicamente o cônjuge com login próprio. O perfil vem da primeira
     * associação em profile_members; o consultor, do vínculo ativo do
     * TITULAR desse perfil (o cônjuge nunca tem vínculo de consultor
     * próprio, só o dono tem).
     *
     * @return array{user: User, perfil: ?FinancialProfile, consultor: ?User}
     */
    private function contextoDeOutraConta(User $u): array
    {
        $perfil = $u->memberships()->with('profile.owner')->first()?->profile;

        $consultor = $perfil
            ? ConsultantClient::query()->where('client_id', $perfil->owner_user_id)->active()->with('consultant')->first()?->consultant
            : null;

        return ['user' => $u, 'perfil' => $perfil, 'consultor' => $consultor];
    }
}

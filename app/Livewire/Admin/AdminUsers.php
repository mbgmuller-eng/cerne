<?php

namespace App\Livewire\Admin;

use App\Enums\InviteStatus;
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
                'clientes_vinculados' => ConsultantClient::query()->where('consultant_id', $alvo->id)->count(),
            ];
        }

        return [
            'tipo' => 'sem_perfil_proprio',
            'nome' => $alvo->name,
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

    public function render()
    {
        return view('livewire.admin.admin-users', [
            'users' => User::query()->orderByDesc('created_at')->get(),
            'profiles' => FinancialProfile::query()
                ->with(['owner.consultantLinks' => fn ($q) => $q->active()->with('consultant')])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}

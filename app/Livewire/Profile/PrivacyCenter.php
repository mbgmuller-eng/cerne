<?php

namespace App\Livewire\Profile;

use App\Jobs\ExportUserDataJob;
use App\Models\DataExport;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Central de privacidade em Minha conta (LGPD, art. 18): baixar os próprios dados, revogar o consentimento de saúde e pedir a exclusão da conta.
 *
 * Age sempre sobre quem está logado, nunca sobre o perfil aberto: um consultor com cliente aberto vê esta tela com os dados dele, não os do cliente.
 * A exclusão tem carência de 7 dias (pedido por engano, ou feito por quem pegou o celular emprestado, dá tempo de ser desfeito) e
 * exige a senha E o e-mail digitado.
 */
class PrivacyCenter extends Component
{
    public const GRACE_DAYS = 7;

    public bool $confirmingDeletion = false;

    public string $deletePassword = '';

    public string $deleteEmail = '';

    public bool $understandsShared = false;

    public function requestExport(): void
    {
        $usuario = auth()->user();

        // Um pedido por dia: montar o zip custa CPU e disco, e a hospedagem é compartilhada.
        $recente = DataExport::query()->where('user_id', $usuario->id)->where('created_at', '>', now()->subDay())->exists();

        if ($recente) {
            $this->addError('export', 'Você já pediu uma cópia nas últimas 24 horas. Use o link já enviado ou tente de novo amanhã.');

            return;
        }

        $pedido = DataExport::query()->create(['user_id' => $usuario->id, 'status' => DataExport::PENDING]);
        ExportUserDataJob::dispatch($pedido->id);

        session()->flash('privacy_status', 'Pedido recebido. Quando a cópia estiver pronta, enviamos o link por e-mail.');
    }

    public function revokeHealthConsent(): void
    {
        auth()->user()->revokeHealthConsent();

        session()->flash('privacy_status', 'Consentimento de saúde revogado. A área de Saúde foi fechada e os avisos de saúde pararam. Os dados continuam guardados até você pedir a exclusão.');
    }

    public function startDeletion(): void
    {
        $usuario = auth()->user();

        if ($usuario->isPlatformAdmin()) {
            $this->addError('deletePassword', 'Contas de administrador da plataforma não podem ser excluídas por aqui.');

            return;
        }

        $this->validate([
            'deletePassword' => ['required', 'string'],
            'deleteEmail' => ['required', 'string'],
        ], [], ['deletePassword' => 'senha', 'deleteEmail' => 'e-mail']);

        if (! Hash::check($this->deletePassword, $usuario->password)) {
            $this->addError('deletePassword', 'A senha não confere.');

            return;
        }

        if (mb_strtolower(trim($this->deleteEmail)) !== mb_strtolower($usuario->email)) {
            $this->addError('deleteEmail', 'Digite o e-mail da conta exatamente como ele está cadastrado.');

            return;
        }

        if ($this->sharedWith() !== [] && ! $this->understandsShared) {
            $this->addError('understandsShared', 'Confirme que entende que o perfil compartilhado também será apagado.');

            return;
        }

        $usuario->forceFill([
            'deletion_requested_at' => now(),
            'deletion_due_at' => now()->addDays(self::GRACE_DAYS),
        ])->save();

        $this->reset('confirmingDeletion', 'deletePassword', 'deleteEmail', 'understandsShared');
        session()->flash('privacy_status', 'Exclusão agendada. Sua conta e todos os seus dados serão apagados em '.self::GRACE_DAYS.' dias. Até lá você pode cancelar aqui.');
    }

    public function cancelDeletion(): void
    {
        auth()->user()->forceFill(['deletion_requested_at' => null, 'deletion_due_at' => null])->save();

        session()->flash('privacy_status', 'Exclusão cancelada. Sua conta continua como estava.');
    }

    /**
     * Nomes das pessoas que dividem um perfil DESTE usuário: apagar o titular apaga o perfil inteiro, com o que o outro lançou.
     *
     * @return list<string>
     */
    public function sharedWith(): array
    {
        $usuario = auth()->user();
        $perfis = FinancialProfile::query()->where('owner_user_id', $usuario->id)->pluck('id');

        return ProfileMember::query()->whereIn('profile_id', $perfis)->whereNotNull('user_id')->where('user_id', '!=', $usuario->id)->pluck('name')->all();
    }

    public function render()
    {
        $usuario = auth()->user();

        return view('livewire.profile.privacy-center', [
            'user' => $usuario,
            'latestExport' => DataExport::query()->where('user_id', $usuario->id)->latest()->first(),
            'sharedWith' => $this->sharedWith(),
            'isHealthUser' => $usuario->isClient() || $usuario->health_consent_at !== null,
        ]);
    }
}

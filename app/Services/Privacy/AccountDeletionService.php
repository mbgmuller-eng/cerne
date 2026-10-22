<?php

namespace App\Services\Privacy;

use App\Enums\SubscriptionStatus;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\LeadAttachment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use App\Services\PixAutomaticBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Exclusão de conta (LGPD, art. 18, VI): cancela a cobrança, apaga todos os dados e TODOS os arquivos da pessoa e registra, sem dado
 * pessoal, que a exclusão aconteceu.
 *
 * O banco apaga em cascata o que tem chave estrangeira (dono de perfil leva o perfil inteiro: lançamentos, investimentos, saúde...), mas a
 * cascata não toca no disco: os PDFs, documentos pessoais e fotos precisam ser apagados à parte, e é isso que fecha a conta de verdade.
 * O que não tem chave estrangeira (notificações, sessões, convites com o e-mail da pessoa...) é apagado aqui, explicitamente.
 *
 * Membro de um perfil que NÃO é o dono (o cônjuge) só perde o login: o que foi lançado no perfil do titular continua com o titular.
 *
 * Se a Asaas não conseguir cancelar a cobrança, a exclusão NÃO segue: a pessoa não pode ficar sem conta e ainda sendo cobrada.
 */
class AccountDeletionService
{
    public function __construct(private readonly AsaasClient $asaas, private readonly PixAutomaticBillingService $pix) {}

    /**
     * @param  string  $requestedBy  'titular' (pediu a própria exclusão) ou 'admin'
     * @return int quantos arquivos foram apagados do disco
     */
    public function delete(User $user, string $requestedBy = 'titular'): int
    {
        $this->cancelBilling($user);

        $arquivos = $this->collectFiles($user);
        $papel = $user->role->value;
        $tinhaPerfil = FinancialProfile::withCareProfiles()->where('owner_user_id', $user->id)->exists();
        $pedidoEm = $user->deletion_requested_at;

        DB::transaction(function () use ($user, $papel, $tinhaPerfil, $pedidoEm, $requestedBy, $arquivos): void {
            $this->deleteLoose($user);

            // O registro dos acessos de administrador sobrevive à conta, mas sem nome nem e-mail de quem foi excluído.
            DB::table('impersonation_logs')->where('target_user_id', $user->id)->update(['target_name' => null, 'target_email' => null, 'updated_at' => now()]);

            $user->delete();

            DB::table('account_deletions')->insert([
                'role' => $papel, 'had_profile' => $tinhaPerfil, 'requested_by' => $requestedBy, 'requested_at' => $pedidoEm, 'deleted_at' => now(),
                'files_removed' => count($arquivos), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        // Só depois de o banco confirmar: arquivo apagado sem a conta ter sido apagada seria o pior dos dois mundos.
        foreach ($arquivos as [$disco, $caminho]) {
            try {
                Storage::disk($disco)->delete($caminho);
            } catch (Throwable) {
                // arquivo já ausente ou disco indisponível: a rotina de retenção varre o que sobrar
            }
        }

        return count($arquivos);
    }

    /** Cancela assinatura e débito automático. Falha da Asaas propaga e interrompe a exclusão. */
    private function cancelBilling(User $user): void
    {
        $assinaturas = Subscription::query()->where('user_id', $user->id)->where('status', '!=', SubscriptionStatus::Cancelled->value)->get();

        foreach ($assinaturas as $assinatura) {
            // Assinatura de cortesia não tem id na Asaas: nada para cancelar lá.
            if ($assinatura->asaas_subscription_id !== null) {
                $this->asaas->cancelSubscription($assinatura->asaas_subscription_id);
            }

            $this->pix->cancelAuthorization($assinatura);
            $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
        }
    }

    /**
     * Todo arquivo no disco que é da pessoa: [disco, caminho].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function collectFiles(User $user): array
    {
        $perfis = FinancialProfile::withCareProfiles()->where('owner_user_id', $user->id)->pluck('id');
        $arquivos = $this->filesForProfiles($perfis);

        // Profissional: os anexos dos leads dele (simulações em PDF de gente que nunca foi cliente).
        $leads = DB::table('leads')->where('consultant_id', $user->id)->pluck('id');
        if ($leads->isNotEmpty()) {
            foreach (LeadAttachment::query()->whereIn('lead_id', $leads)->pluck('storage_path') as $p) {
                $arquivos[] = [(string) config('cerne.lead_files.disk'), (string) $p];
            }
        }

        foreach (DataExport::query()->where('user_id', $user->id)->whereNotNull('path')->pluck('path') as $p) {
            $arquivos[] = ['local', (string) $p];
        }

        return $arquivos;
    }

    /**
     * Apaga uma pessoa acompanhada: dados em cascata e, depois de o banco confirmar, os arquivos do disco.
     *
     * @return int quantos arquivos foram apagados
     */
    public function deleteProfile(FinancialProfile $perfil): int
    {
        $arquivos = $this->filesForProfiles(collect([$perfil->id]));

        DB::transaction(function () use ($perfil): void {
            $perfil->delete();
        });

        foreach ($arquivos as [$disco, $caminho]) {
            try {
                Storage::disk($disco)->delete($caminho);
            } catch (Throwable) {
                // arquivo já ausente ou disco indisponível: a rotina de retenção varre o que sobrar
            }
        }

        return count($arquivos);
    }

    /**
     * Arquivos no disco dos perfis dados: [disco, caminho].
     *
     * @param  \Illuminate\Support\Collection<int, string>  $perfis
     * @return list<array{0: string, 1: string}>
     */
    private function filesForProfiles(\Illuminate\Support\Collection $perfis): array
    {
        $arquivos = [];

        if ($perfis->isNotEmpty()) {
            $cofre = (string) config('cerne.document_vault.disk');
            $importacao = (string) config('cerne.documents.disk');
            $academia = (string) config('cerne.gym_images.disk');

            foreach (Document::withoutGlobalScopes()->whereIn('profile_id', $perfis)->pluck('storage_path') as $p) {
                $arquivos[] = [$cofre, (string) $p];
            }

            foreach (DocumentUpload::withoutGlobalScopes()->whereIn('profile_id', $perfis)->whereNotNull('storage_path')->pluck('storage_path') as $p) {
                if ($p !== '') {
                    $arquivos[] = [$importacao, (string) $p];
                }
            }

            foreach (GymExercise::withoutGlobalScopes()->whereIn('profile_id', $perfis)->get(['image_path', 'image_path_2']) as $e) {
                foreach ([$e->image_path, $e->image_path_2] as $p) {
                    if ($p !== null && $p !== '') {
                        $arquivos[] = [$academia, (string) $p];
                    }
                }
            }
        }

        return $arquivos;
    }

    /** O que tem o id ou o e-mail da pessoa mas não tem chave estrangeira que o apague junto. */
    private function deleteLoose(User $user): void
    {
        $porUsuario = [
            ['notifications', 'notifiable_id'], ['push_subscriptions', 'subscribable_id'], ['sessions', 'user_id'],
            ['engagement_reminders', 'user_id'], ['error_log_users', 'user_id'],
        ];

        foreach ($porUsuario as [$tabela, $coluna]) {
            if (Schema::hasTable($tabela)) {
                DB::table($tabela)->where($coluna, $user->id)->delete();
            }
        }

        $email = mb_strtolower($user->email);

        foreach ([['consultant_invites', 'client_email'], ['partner_invites', 'partner_email'], ['professional_invites', 'email'], ['password_reset_tokens', 'email']] as [$tabela, $coluna]) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, $coluna)) {
                DB::table($tabela)->whereRaw("lower({$coluna}) = ?", [$email])->delete();
            }
        }
    }
}

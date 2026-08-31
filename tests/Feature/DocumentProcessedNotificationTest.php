<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DocumentProcessed;
use App\Services\Extraction\DocumentExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DocumentProcessedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_falha_por_tipo_nao_extraivel_notifica_quem_enviou(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfil();

        $documento = $this->criarDocumento($perfil, $membro, $titular, DocumentType::Other);

        app(DocumentExtractionService::class)->extract($documento);

        Notification::assertSentTo(
            $titular,
            DocumentProcessed::class,
            fn ($n) => $n->status === ProcessingStatus::Failed
        );
    }

    public function test_job_failed_apos_esgotar_tentativas_tambem_notifica(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfil();

        $documento = $this->criarDocumento($perfil, $membro, $titular, DocumentType::BankStatement);

        (new ProcessDocumentJob($documento->id))->failed(new \RuntimeException('falha de teste'));

        Notification::assertSentTo($titular, DocumentProcessed::class);
    }

    public function test_via_respeita_preferencia_de_canal(): void
    {
        [, $titular] = $this->criarPerfil();
        $notificacao = new DocumentProcessed('id-fake', 'documento.pdf', ProcessingStatus::Completed);

        $titular->update(['notify_email_enabled' => false, 'notify_push_enabled' => false]);
        self::assertSame(['database'], $notificacao->via($titular->fresh()));

        $titular->update(['notify_email_enabled' => true]);
        self::assertSame(['database', 'mail'], $notificacao->via($titular->fresh()));
    }

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$perfil, $titular, $membro];
    }

    private function criarDocumento(FinancialProfile $perfil, ProfileMember $membro, User $titular, DocumentType $tipo): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $titular->id,
            'member_id' => $membro->id,
            'document_type' => $tipo,
            'original_filename' => 'documento.pdf',
            'storage_path' => 'documentos/'.$perfil->id.'/documento.pdf',
            'size_bytes' => 1024,
            'processing_status' => ProcessingStatus::Pending,
        ]);
    }
}

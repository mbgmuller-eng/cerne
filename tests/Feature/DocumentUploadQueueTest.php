<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O aviso na tela de importação promete que, sem ANTHROPIC_API_KEY, o envio
 * "fica na fila até que ela seja definida" — não pode virar "Falhou" na
 * primeira tentativa. Ver rotina "documentos-pendentes" em routes/console.php.
 */
class DocumentUploadQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_sem_chave_configurada_nao_despacha_e_fica_na_fila(): void
    {
        config(['cerne.ai.api_key' => null]);
        Storage::fake(config('cerne.documents.disk'));
        Queue::fake();
        $this->criarPerfil();

        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->set('documentType', 'insurance_policy') // não exige conta bancária — irrelevante pro que este teste cobre
            ->call('enviar')
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessDocumentJob::class);
        $documento = DocumentUpload::withoutProfileScope()->sole();
        self::assertSame(ProcessingStatus::Pending, $documento->processing_status);
    }

    public function test_upload_com_chave_configurada_despacha_o_job(): void
    {
        config(['cerne.ai.api_key' => 'chave-de-teste']);
        Storage::fake(config('cerne.documents.disk'));
        Queue::fake();
        $this->criarPerfil();

        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->set('documentType', 'insurance_policy') // não exige conta bancária — irrelevante pro que este teste cobre
            ->call('enviar')
            ->assertHasNoErrors();

        $documento = DocumentUpload::withoutProfileScope()->sole();
        Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->documentId === $documento->id);
    }

    public function test_reprocessar_documento_falho_limpa_o_erro_e_despacha_de_novo(): void
    {
        config(['cerne.ai.api_key' => 'chave-de-teste']);
        Queue::fake();
        [$perfil, $membro] = $this->criarPerfil();
        $documento = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'document_type' => 'bank_statement',
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/extrato.pdf',
            'processing_status' => ProcessingStatus::Failed,
            'error_message' => 'Anthropic Bad Request Exception: credit balance too low',
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('reprocessar', $documento->id);

        $documento->refresh();
        self::assertSame(ProcessingStatus::Pending, $documento->processing_status);
        self::assertNull($documento->error_message);
        Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->documentId === $documento->id);
    }

    public function test_reprocessar_nao_faz_nada_em_documento_que_nao_falhou(): void
    {
        Queue::fake();
        [$perfil, $membro] = $this->criarPerfil();
        $documento = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'document_type' => 'bank_statement',
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/extrato.pdf',
            'processing_status' => ProcessingStatus::Completed,
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('reprocessar', $documento->id);

        self::assertSame(ProcessingStatus::Completed, $documento->fresh()->processing_status);
        Queue::assertNotPushed(ProcessDocumentJob::class);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
